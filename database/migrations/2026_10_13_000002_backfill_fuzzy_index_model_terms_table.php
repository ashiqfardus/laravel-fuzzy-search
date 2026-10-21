<?php

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fill fuzzy_index_model_terms, the per-model dictionary of counts 2026_10_13_000001
 * creates, from an index written before it: for each model type and word, the number of the type's
 * documents that hold it (count(distinct model_id) of its postings), beside the word and its length.
 * Every index write keeps the table in step from 2026_10_13_000001 on.
 *
 * The postings are clustered by term_id, so a range of words is one range of their primary key. The
 * ranges come from the dictionary, in id order, up to its highest id when the run starts (a word
 * added later gets its rows from the index write that adds it), each about $chunk documents by the
 * words' doc_count (a word that common is a range of its own). Each range is one transaction: lock
 * its words, delete its rows, insert them again with one INSERT … SELECT that counts its postings,
 * set the words' dictionary counts to the sum of those rows where they differ, and record
 * its end in STATE. Outside a transaction, so every range commits on its own.
 *
 * STATE, which 2026_10_13_000001 creates with last_term_id 0, holds the progress: a run that stopped
 * part way (a killed deploy, a lock timeout) goes on after the last range it committed, and the run
 * that counts every word drops it, so a run that finds no STATE does nothing. The rows the index
 * writes add meanwhile say nothing of the progress (V1-1). An index with no word has nothing to
 * count: STATE is dropped and nothing else runs, which is what `migrate --pretend`, running no
 * select, prints.
 *
 * A range and an index write on the same words run one after the other (V1-2). The range's delete
 * and insert are two statements, and under READ COMMITTED (PostgreSQL, SQL Server, a MySQL set to
 * it) a write committed between them would have its row hit by the insert's key, or its delta lost
 * or counted twice. So a range first locks its words, in ascending id order, holding nothing before
 * them, as a write locks every word it touches, in ascending id order too, before it writes any
 * posting or model terms row (IndexManager::adjustDocCounts()): whichever locks a shared word first,
 * the other waits for its commit, and neither waits holding a word the other needs. On SQL Server a
 * range takes the index's write lock first instead, as every index write and flush does.
 * MySQL/MariaDB's REPEATABLE READ also locks the record just past what a statement reads (next-key
 * locking), which a write on the next word can hold: should InnoDB roll the range back as a deadlock
 * victim, the range is retried, as a write is.
 *
 * Code from before this migration writes the index without this table. The run warns (Log and
 * STDERR), naming each model to rebuild with --fresh, when the meta totals changed while it ran. A
 * resumed run names every indexed model: writes made between the stopped run and this one landed in
 * ranges the stopped run had counted, and this run's own snapshot cannot see them (as the compact
 * postings migration's).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLE = 'fuzzy_index_model_terms';
    private const STATE = 'fuzzy_index_model_terms_backfill';

    /**
     * The documents (the words' doc_count, summed) one range counts, about. Its statement's time
     * follows the rows it inserts more than the postings it reads: at 4M postings on MySQL 9.6 a
     * range of common words took 0.1–0.3 s, one of 50,000 single-document words about 1 s, and the
     * whole run took the same time with ranges of 50,000 to 1M documents.
     */
    public int $chunk = 50000;

    /** Called with the last term id of each range up() commits (tests write the index in between). */
    public ?\Closure $afterRange = null;

    public function up(): void
    {
        $pretending = DB::connection()->pretending();
        if (!$pretending && !Schema::hasTable(self::STATE)) {
            return;
        }

        $written = $pretending ? 0 : $this->fill();
        Schema::drop(self::STATE);

        // PostgreSQL: statistics, and the visibility map for an index-only read. Best-effort, as the
        // package's own ANALYZE: autovacuum gets there too. Unprepared: VACUUM runs only on its own.
        if ($written > 0 && DB::connection()->getDriverName() === DbDialect::PGSQL) {
            try {
                DB::unprepared('VACUUM (ANALYZE) ' . DB::getQueryGrammar()->wrapTable(self::TABLE));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function down(): void
    {
        // Nothing to undo: 2026_10_13_000001's down() drops the table this fills, and STATE.
    }

    /** Count every word past STATE's last term id, a range at a time, and warn as the class says. Returns the rows inserted. */
    private function fill(): int
    {
        $started = microtime(true);
        $done    = (int) DB::table(self::STATE)->value('last_term_id');
        $end     = (int) DB::table('fuzzy_index_terms')->max('id');
        $meta    = $this->meta();
        $written = 0;

        foreach ($this->ranges($done, $end) as [$low, $high]) {
            $written += DB::transaction(fn () => $this->count($low, $high), 3); // retried on a deadlock, as an index write (IndexManager::ATTEMPTS)
            if ($this->afterRange !== null) {
                ($this->afterRange)($high);
            }
        }

        $after   = $this->meta();
        $changed = array_keys(array_diff_assoc($meta, $after) + array_diff_assoc($after, $meta)
            + ($done > 0 ? $after + array_flip(DB::table('fuzzy_index_model_types')->pluck('model_type')->map(fn ($type) => (string) $type)->all()) : []));
        if ($changed !== []) {
            $this->warn('fuzzy-search: ' . ($done > 0
                ? 'the model terms backfill was resumed after an interrupted run, and may have missed index writes made meanwhile'
                : 'the index was written while the model terms backfill ran')
                . '. Rebuild each model it names: ' . implode('; ', array_map(fn (string $type) => "php artisan fuzzy-search:rebuild \"{$type}\" --fresh", $changed)));
        }

        if ($end > $done) {
            Log::info(sprintf('fuzzy-search: the model terms backfill wrote %d row(s) in %.1f s.', $written, microtime(true) - $started));
        }

        return $written;
    }

    /**
     * Count the words in ($low, $high] again, in the caller's transaction: lock them (on SQL Server
     * the index's write lock), delete their rows (through model_terms_term_id_idx), insert them from
     * the postings, one range of their primary key grouped in its order, the words joined after, and
     * record $high. The words' range bounds the dictionary's side of the join too: MySQL, which
     * reckons the grouped postings the larger, read the whole dictionary for each range. Returns the
     * rows inserted.
     */
    private function count(int $low, int $high): int
    {
        if (DB::connection()->getDriverName() === DbDialect::SQLSRV) {
            app(IndexManager::class)->lockIndexForWrite();
        } else {
            DB::table('fuzzy_index_terms')->where('id', '>', $low)->where('id', '<=', $high)->orderBy('id')->lockForUpdate()->pluck('id');
        }

        // A word a 2.0 worker added during the upgrade's deploy window has a term_length of 0 on
        // MySQL/MariaDB (UPGRADE: "New migration to run"). The rows below copy the length, and a
        // model's typo expansion reads it there, so set it first, as 2026_09_17_000001 does.
        // Found with a plain read (the range's words are locked above, so it reads them as they
        // are) and set by primary key: an UPDATE by `term_length = 0` went through the
        // (term_length, doc_count, id) index and locked every such word, those of later ranges and
        // the gap new words go in too, so an index write deadlocked with it.
        $driver = DB::connection()->getDriverName();
        $length = $driver === DbDialect::SQLSRV
            ? 'LEN(term COLLATE Latin1_General_100_CI_AS_SC)'
            : DbDialect::lengthFunction($driver) . '(term)';
        $zero = DB::table('fuzzy_index_terms')->where('id', '>', $low)->where('id', '<=', $high)->where('term_length', 0)->pluck('id')->all();
        foreach (array_chunk($zero, 1000) as $ids) {
            DB::update('UPDATE ' . DbDialect::rawIdentifier('fuzzy_index_terms') . " SET term_length = {$length} WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')');
        }

        DB::table(self::TABLE)->where('term_id', '>', $low)->where('term_id', '<=', $high)->delete();

        $grammar = DB::getQueryGrammar();
        $written = DB::affectingStatement(
            'insert into ' . $grammar->wrapTable(self::TABLE) . ' (model_type_id, term_id, term, term_length, doc_count)'
            . ' select g.model_type_id, g.term_id, t.term, t.term_length, g.documents from (select term_id, model_type_id, count(distinct model_id) as documents'
            . ' from ' . $grammar->wrapTable('fuzzy_index_postings') . ' where term_id > ? and term_id <= ? group by term_id, model_type_id) g'
            . ' inner join ' . $grammar->wrapTable('fuzzy_index_terms') . ' t on t.id = g.term_id where t.id > ? and t.id <= ?',
            [$low, $high, $low, $high]
        );

        // The dictionary's counts of the range's words, again from the rows just counted:
        // an index 2.0 wrote can hold a count too high, or a word no posting holds any more, which no
        // flush gave back, and the dictionary would no longer equal the sum of the types' counts.
        IndexManager::recountDictionary(fn ($words) => $words->where('id', '>', $low)->where('id', '<=', $high));

        DB::table(self::STATE)->update(['last_term_id' => $high]);

        return $written;
    }

    /**
     * The ranges of term ids in ($start, $end], ($low, $high], each of about $chunk documents by
     * the words' doc_count, read from the dictionary in id order.
     *
     * @return \Generator<array{int, int}>
     */
    private function ranges(int $start, int $end): \Generator
    {
        $low  = $start;
        $last = $start;
        $sum  = 0;

        while ($last < $end) {
            $terms = DB::table('fuzzy_index_terms')->where('id', '>', $last)->where('id', '<=', $end)->orderBy('id')->limit(10000)->get(['id', 'doc_count']);
            if ($terms->isEmpty()) {
                break;
            }

            foreach ($terms as $term) {
                $term = (object) $term; // whatever the app's fetch mode
                $last = (int) $term->id;
                $sum += max(1, (int) $term->doc_count);
                if ($sum >= $this->chunk) {
                    yield [$low, $last];
                    $low = $last;
                    $sum = 0;
                }
            }
        }

        if ($low < $end) {
            yield [$low, $end];
        }
    }

    /** @return array<string, string> model type => its meta totals, to compare before and after the run */
    private function meta(): array
    {
        $out = [];
        foreach (DB::table('fuzzy_index_meta')->get(['model_type', 'total_docs', 'total_tokens']) as $row) {
            $row                             = (object) $row; // whatever the app's fetch mode
            $out[(string) $row->model_type] = (int) $row->total_docs . '|' . (int) $row->total_tokens;
        }

        return $out;
    }

    /** Log $message, and print it under `php artisan migrate` (not in a test run). */
    private function warn(string $message): void
    {
        Log::warning($message);
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            fwrite(STDERR, "  WARN  {$message}" . PHP_EOL);
        }
    }
};
