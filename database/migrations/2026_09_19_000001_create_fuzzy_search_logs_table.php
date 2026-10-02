<?php

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEXES = [
        'search_logs_term_idx'    => 'normalized_term',
        'search_logs_created_idx' => 'created_at',
        'search_logs_results_idx' => 'result_count',
        'search_logs_day_idx'     => 'day',
    ];

    // analytics.table, the name SearchAnalytics writes and reads.
    public function up(): void
    {
        $name = SearchAnalytics::table();

        // A run that failed after the CREATE left the table (MySQL, MariaDB and SQLite run a
        // migration outside a transaction and record it only once up() returns), and the next
        // `migrate` stopped at it: then only the indexes it lacks are added.
        if (!Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('term', 255);                 // '' when analytics.hash_terms is on
                // Lower-cased, whitespace-collapsed (or its keyed sha256). 191, as every indexed
                // string: 255 utf8mb4 characters made a 1,020-byte key, over MySQL's 767-byte limit
                // for the COMPACT row format.
                $table->string('normalized_term', 191);
                $table->string('model_type', 191)->nullable();
                $table->string('algorithm', 32);
                $table->string('path', 16);                  // like | bm25 | extended | in_memory
                $table->unsignedInteger('result_count');
                $table->decimal('latency_ms', 8, 2);
                $table->date('day');                         // created_at's date, for portable per-day grouping
                $table->timestamp('created_at');
            });
        }

        $existing = $this->indexes($name);
        Schema::table($name, function (Blueprint $table) use ($existing) {
            foreach (self::INDEXES as $index => $column) {
                if (!in_array($index, $existing, true)) {
                    $table->index($column, $index);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(SearchAnalytics::table());
    }

    /**
     * The names of the indexes on $table, read from the catalog (Laravel 10 has no
     * Schema::getIndexes()).
     *
     * @return list<string>
     */
    private function indexes(string $table): array
    {
        $connection = DB::connection();
        $table      = $connection->getTablePrefix() . $table;

        $rows = match ($connection->getDriverName()) {
            DbDialect::SQLITE => $connection->select("select name from sqlite_master where type = 'index' and tbl_name = ?", [$table]),
            DbDialect::PGSQL  => $connection->select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?))', [$table]),
            DbDialect::SQLSRV => $connection->select('select name from sys.indexes where object_id = object_id(?) and name is not null', [$table]),
            default           => $connection->select('select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ?', [$table]),
        };

        return array_map(fn ($row) => strtolower((string) ((object) $row)->name), $rows); // whatever the fetch mode
    }
};
