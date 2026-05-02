<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StatusCommand extends Command
{
    protected $signature   = 'fuzzy-search:status';
    protected $description = 'Show inverted index statistics per model';

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

        return self::SUCCESS;
    }
}
