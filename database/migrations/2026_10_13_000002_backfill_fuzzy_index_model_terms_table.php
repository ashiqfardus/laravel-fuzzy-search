<?php

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ER-197: fill fuzzy_index_model_terms, the per-model dictionary of counts 2026_10_13_000001
 * creates, from an index written before it: for each model type and word, the number of the type's
 * documents that hold it (count(distinct model_id) of its postings), beside the word and its length.
 * From here on every index write keeps the table in step.
 *
 * The postings are clustered by term_id, so a range of words is one range of their primary key. The
 * ranges come from the dictionary, in id order, each about $chunk documents by the words' doc_count
 * (a word that common is a range of its own), and each is one transaction: delete the range's rows,
 * then one INSERT … SELECT that counts its postings. Outside a transaction, so every range commits on
 * its own.
 *
 * A marker table (RUNNING) stands while a run is under way. A run that stopped part way (a killed
 * deploy, a lock timeout) is resumed from the highest term_id the table holds, that word counted
 * again; a run that finds the table filled and no marker finds a complete run's work, kept since by
 * the index writes, and does nothing. An index with no posting has nothing to count, and nothing
 * runs: what `migrate --pretend`, which runs no select, prints.
 *
 * Code from before this migration writes the index without this table. The run warns (Log and
 * STDERR), naming each model to rebuild with --fresh, when the meta totals changed while it ran. A
 * resumed run names every indexed model: writes made between the stopped run and this one landed in
 * ranges the stopped run had counted, and this run's own snapshot cannot see them (as the compact
 * postings migration's, VA-1, VA-4).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const TABLE   = 'fuzzy_index_model_terms';
    private const RUNNING = 'fuzzy_index_model_terms_filling';

    /** The documents (the words' doc_count, summed) one range counts, about. */
    public int $chunk = 50000;

    /** Called with the last term id of each range up() commits, null for the last one (tests write the index in between). */
    public ?\Closure $afterRange = null;

    public function up(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        $running = Schema::hasTable(self::RUNNING);
        if (!$running && (DB::table(self::TABLE)->exists() || !DB::table('fuzzy_index_postings')->exists())) {
            return;
        }
        if (!$running) {
            // A primary key, as every table needs under MySQL's sql_require_primary_key (VA-3).
            Schema::create(self::RUNNING, fn (Blueprint $table) => $table->unsignedTinyInteger('id')->primary());
        }

        $started = microtime(true);
        $resumed = DB::table(self::TABLE)->exists();
        $meta    = $this->meta();
        $written = 0;

        foreach ($this->ranges($resumed ? (int) DB::table(self::TABLE)->max('term_id') - 1 : 0) as [$low, $high]) {
            $written += DB::transaction(fn () => $this->count($low, $high));
            if ($this->afterRange !== null) {
                ($this->afterRange)($high);
            }
        }

        $after   = $this->meta();
        $changed = array_keys(array_diff_assoc($meta, $after) + array_diff_assoc($after, $meta)
            + ($resumed ? $after + array_flip(DB::table('fuzzy_index_model_types')->pluck('model_type')->map(fn ($type) => (string) $type)->all()) : []));
        if ($changed !== []) {
            $this->warn('fuzzy-search: ' . ($resumed
                ? 'the model terms backfill was resumed after an interrupted run, and may have missed index writes made meanwhile'
                : 'the index was written while the model terms backfill ran')
                . '. Rebuild each model it names: ' . implode('; ', array_map(fn (string $type) => "php artisan fuzzy-search:rebuild \"{$type}\" --fresh", $changed)));
        }

        Schema::drop(self::RUNNING);
        Log::info(sprintf('fuzzy-search: the model terms backfill wrote %d row(s) in %.1f s.', $written, microtime(true) - $started));

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
        // Nothing to undo: 2026_10_13_000001's down() drops the table this fills.
    }

    /**
     * Count the words in ($low, $high] (to the end for null) again, in the caller's transaction:
     * delete their rows (through model_terms_term_id_idx), then insert them from the postings, one
     * range of their primary key grouped in its order, the words joined after. The words' range
     * bounds the dictionary's side too: MySQL, which reckons the grouped postings the larger, read
     * the whole dictionary for each range and looked each word up in them. Returns the rows inserted.
     */
    private function count(int $low, ?int $high): int
    {
        DB::table(self::TABLE)->where('term_id', '>', $low)->when($high !== null, fn ($query) => $query->where('term_id', '<=', $high))->delete();

        $grammar = DB::getQueryGrammar();
        $range   = fn (string $column) => "{$column} > ?" . ($high === null ? '' : " and {$column} <= ?");
        $bounds  = $high === null ? [$low] : [$low, $high];

        return DB::affectingStatement(
            'insert into ' . $grammar->wrapTable(self::TABLE) . ' (model_type_id, term_id, term, term_length, doc_count)'
            . ' select g.model_type_id, g.term_id, t.term, t.term_length, g.documents from (select term_id, model_type_id, count(distinct model_id) as documents'
            . ' from ' . $grammar->wrapTable('fuzzy_index_postings') . ' where ' . $range('term_id') . ' group by term_id, model_type_id) g'
            . ' inner join ' . $grammar->wrapTable('fuzzy_index_terms') . ' t on t.id = g.term_id where ' . $range('t.id'),
            [...$bounds, ...$bounds]
        );
    }

    /**
     * The ranges of term ids past $start, ($low, $high], each of about $chunk documents by the words'
     * doc_count, read from the dictionary in id order; the last one is open, for the words written
     * meanwhile.
     *
     * @return \Generator<array{int, ?int}>
     */
    private function ranges(int $start): \Generator
    {
        $low  = $start;
        $last = $start;
        $sum  = 0;

        do {
            $terms = DB::table('fuzzy_index_terms')->where('id', '>', $last)->orderBy('id')->limit(10000)->get(['id', 'doc_count']);
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
        } while ($terms->isNotEmpty());

        yield [$low, null];
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
