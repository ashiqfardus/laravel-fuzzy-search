<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The legacy-postings query at the end of handle() is a full table scan by design: there is no
 * index on column_name, and this is an admin command run by hand, not on a request path. It took
 * 100 s, or a statement timeout, at 6M rows on MySQL (S4), so past LEGACY_SCAN_TOKENS indexed
 * tokens it runs only under --legacy.
 */
class StatusCommand extends Command
{
    protected $signature   = 'fuzzy-search:status {--legacy : Check for postings that predate column weighting, which reads every posting, however large the index}';
    protected $description = 'Show inverted index statistics per model';

    /** The index size, in fuzzy_index_meta's total_tokens, up to which the legacy check runs by default. */
    private const LEGACY_SCAN_TOKENS = 1_000_000;

    public function handle(): int
    {
        $rows = DB::table('fuzzy_index_meta')->get();

        if ($rows->isEmpty()) {
            $this->warn('Index is empty. Run: php artisan fuzzy-search:rebuild {Model}');
            return self::SUCCESS;
        }

        $this->table(
            ['Model', 'Total docs', 'Total tokens', 'Avg doc length'],
            $rows->map(fn($r) => [
                class_basename($r->model_type),
                number_format($r->total_docs),
                number_format($r->total_tokens),
                round($r->avg_doc_length, 2),
            ])->toArray()
        );

        $termCount = DB::table('fuzzy_index_terms')->count();
        $this->info('Total unique terms in dictionary: ' . number_format($termCount));

        $tokens = (int) $rows->sum('total_tokens');
        if ($tokens > self::LEGACY_SCAN_TOKENS && !$this->option('legacy')) {
            $this->line(sprintf(
                'Skipped the check for postings that predate column weighting: it reads every posting, and the index holds %s tokens. Run it with: php artisan fuzzy-search:status --legacy',
                number_format($tokens)
            ));

            return self::SUCCESS;
        }

        $legacy = DB::table('fuzzy_index_postings')
            ->where('column_name', '')
            ->groupBy('model_type')
            ->selectRaw('model_type, COUNT(*) as cnt')
            ->pluck('cnt', 'model_type');

        foreach ($legacy as $modelType => $cnt) {
            $this->warn(sprintf(
                '%s: %s posting(s) predate column weighting and rank at weight 1 — run: php artisan fuzzy-search:rebuild "%s" --fresh',
                class_basename($modelType), number_format($cnt), $modelType
            ));
        }

        return self::SUCCESS;
    }
}
