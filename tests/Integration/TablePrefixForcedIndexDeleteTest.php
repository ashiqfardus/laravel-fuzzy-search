<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * MySQL/MariaDB under a table prefix: the posting DELETE of a re-index names the table with
 * `as p force index (primary)`. It must run against the prefixed table and leave the postings a
 * fresh rebuild gives.
 */
class TablePrefixForcedIndexDeleteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $default = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$default}.prefix", 'pfx_');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB only: the forced-index DELETE is their syntax.');
        }
    }

    /** @return list<string> */
    private function postings(): array
    {
        return $this->namedPostings()
            ->join('fuzzy_index_terms as t', 't.id', '=', 'fuzzy_index_postings.term_id')
            ->where('model_type', User::class)
            ->select('t.term', 'column_name', 'model_id', 'frequency', 'doc_length')
            ->get()
            ->map(fn ($r) => "{$r->term}|{$r->column_name}|{$r->model_id}|{$r->frequency}|{$r->doc_length}")
            ->sort()->values()->all();
    }

    public function test_a_reindex_that_deletes_postings_runs_the_forced_index_delete_and_matches_a_fresh_rebuild(): void
    {
        $this->assertSame('pfx_', DB::connection()->getTablePrefix());
        app(IndexManager::class)->indexBatch(User::all());

        DB::table('users')->where('name', 'John Doe')->update(['name' => 'Zed Doe', 'email' => 'zed@example.org']);

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });
        app(IndexManager::class)->indexBatch(User::all());

        $forced = array_filter($statements, fn ($sql) => str_contains($sql, 'as p force index (primary)'));
        $this->assertNotEmpty($forced, 'No forced-index DELETE ran.');
        foreach ($forced as $sql) {
            $this->assertStringContainsString('`pfx_fuzzy_index_postings`', $sql);
        }
        $reindexed = $this->postings();
        $this->assertNotContains('john', array_map(fn ($l) => explode('|', $l)[0], $reindexed));

        app(IndexManager::class)->flush(User::class);
        app(IndexManager::class)->indexBatch(User::all());
        $this->assertSame($this->postings(), $reindexed);
    }
}
