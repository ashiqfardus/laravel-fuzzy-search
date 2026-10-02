<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * R11-L7 / TC-1 (round 12). `migrate --pretend` runs no select (each returns nothing), so the 2.1
 * migrations' catalog reads saw an empty schema and printed another plan than the one `migrate`
 * runs: from the 2.0.1 schema the column_name migration left out its DROP of the old key (the
 * printed script failed at the key swap), 2026_10_01_000001 left out both of its drops, and on SQL
 * Server the collation migration printed nothing. DBAs plan or hand-run the large ALTERs from that
 * output. Under --pretend each migration now assumes the state the earlier ones leave on an upgrade
 * (up()) or the state its own up() leaves (down()), so the printed statements are the ones that run.
 */
class MigrationPretendTest extends TestCase
{
    private const PATH = __DIR__ . '/../../database/migrations';

    /** @return list<string> the statements $run sends, but for reads and the migrations table's own */
    private function statements(\Closure $run): array
    {
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements) {
            if (!preg_match('/^\s*(select|with|exec sp_|pragma\s+(table|index)_)/i', $query->sql) && !preg_match('/\bmigrations\b/i', $query->sql)) {
                $statements[] = $query->sql;
            }
        });
        try {
            $run();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        return $statements;
    }

    /** $command over the package's migrations; it must exit 0. */
    private function migrations(string $command, array $options = []): void
    {
        $this->assertSame(0, $this->artisan($command, ['--path' => realpath(self::PATH), '--realpath' => true, ...$options])->run(), $command);
    }

    /**
     * From a 2.0.1 install (the four create migrations, so the dictionary has the database's own
     * collation on SQL Server): what --pretend prints for the upgrade is what migrate then runs, and
     * the same for rolling the 2.1 migrations back.
     */
    public function test_pretend_lists_what_the_upgrade_and_its_rollback_run(): void
    {
        $files = glob(self::PATH . '/*.php');
        $this->migrations('migrate:rollback', ['--step' => count($files)]);
        foreach ($files as $file) {
            if (basename($file) < '2026_09') {
                $this->assertSame(0, $this->artisan('migrate', ['--path' => realpath($file), '--realpath' => true])->run());
            }
        }
        $steps = count(array_filter($files, fn (string $file) => basename($file) >= '2026_09'));

        $pretended = $this->statements(fn () => $this->migrations('migrate', ['--pretend' => true]));
        $ran       = $this->statements(fn () => $this->migrations('migrate'));

        $this->assertSame($ran, $pretended);
        $this->assertNotEmpty(preg_grep('/postings_unique_idx/i', preg_grep('/\bdrop\b/i', $ran)), 'the key swap drops the old key');
        $this->assertNotEmpty(preg_grep('/\bdrop\b.*postings_term_model_idx/i', $ran));
        $this->assertNotEmpty(preg_grep('/\bdrop\b.*fuzzy_index_terms_term_length_index/i', $ran));

        $pretended = $this->statements(fn () => $this->migrations('migrate:rollback', ['--step' => $steps, '--pretend' => true]));
        $ran       = $this->statements(fn () => $this->migrations('migrate:rollback', ['--step' => $steps]));

        $this->assertSame($ran, $pretended);
        $this->assertNotEmpty(preg_grep('/\bdrop\b( column)?\s+[`"\[]?term_length\b/i', $ran), 'the rollback drops term_length');

        $this->migrations('migrate');
    }
}
