<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * The index holds a row's latest committed text, whichever write commits last (ruling ER-68).
 * A write built from an instance loaded before a newer save used to index the older text when
 * it committed after that save's own index write. The indexer now reloads the row after it
 * claims it, and indexes that; a row that is gone, or soft-deleted, leaves the index instead.
 */
class IndexLatestRowTest extends TestCase
{
    private function terms(string $class, $id): array
    {
        return DB::table('fuzzy_index_postings as p')
            ->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type', $class)
            ->where('p.model_id', (string) $id)
            ->where('p.column_name', 'name')
            ->orderBy('t.term')
            ->pluck('t.term')
            ->all();
    }

    private function totalDocs(string $class): int
    {
        return (int) DB::table('fuzzy_index_meta')->where('model_type', $class)->value('total_docs');
    }

    public function test_a_write_built_from_a_stale_instance_indexes_the_newer_row(): void
    {
        $stale = User::where('name', 'John Doe')->first();
        DB::table('users')->where('id', $stale->id)->update(['name' => 'John Waited']); // the newer save

        app(IndexManager::class)->indexModel($stale);
        $this->assertSame(['john', 'waited'], $this->terms(User::class, $stale->id));

        app(IndexManager::class)->indexBatch(collect([$stale]));
        $this->assertSame(['john', 'waited'], $this->terms(User::class, $stale->id));
        $this->assertSame(1, $this->totalDocs(User::class));
    }

    public function test_a_stale_instance_of_a_deleted_row_removes_it_from_the_index(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $stale = User::where('name', 'John Doe')->first();
        DB::table('users')->where('id', $stale->id)->delete();

        app(IndexManager::class)->indexModel($stale);

        $this->assertSame([], $this->terms(User::class, $stale->id));
        $this->assertSame(6, $this->totalDocs(User::class));
        $this->assertSame(0, DB::table('fuzzy_index_documents')->where('model_type', User::class)->where('model_id', (string) $stale->id)->count());
    }

    public function test_a_soft_deleted_row_and_a_deleted_row_in_a_batch_leave_the_index(): void
    {
        app(IndexManager::class)->indexBatch(SoftDeletedUser::all());
        $batch = SoftDeletedUser::all();
        $john  = $batch->firstWhere('name', 'John Doe');
        $jane  = $batch->firstWhere('name', 'Jane Doe');
        DB::table('users')->where('id', $john->id)->update(['deleted_at' => now()]);
        DB::table('users')->where('id', $jane->id)->delete();

        $this->assertSame(5, app(IndexManager::class)->indexBatch($batch));

        $this->assertSame([], $this->terms(SoftDeletedUser::class, $john->id));
        $this->assertSame([], $this->terms(SoftDeletedUser::class, $jane->id));
        $this->assertSame(5, $this->totalDocs(SoftDeletedUser::class));
        $this->assertSame(5, DB::table('fuzzy_index_documents')->where('model_type', SoftDeletedUser::class)->count());
        $this->assertSame(0, (int) DB::table('fuzzy_index_terms')->where('term', 'jane')->value('doc_count'));

        $single = SoftDeletedUser::withTrashed()->find($john->id); // a trashed instance, indexed directly
        app(IndexManager::class)->indexModel($single);
        $this->assertSame([], $this->terms(SoftDeletedUser::class, $john->id));
        $this->assertSame(5, $this->totalDocs(SoftDeletedUser::class));
    }
}
