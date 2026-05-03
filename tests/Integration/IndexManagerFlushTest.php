<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Illuminate\Support\Facades\DB;

/**
 * Tests IndexManager::flush() — the three-branch orphan-term cleanup.
 *
 * The test suite runs on SQLite (both locally and in CI), so this directly
 * exercises the SQLite / "else" branch of flush(). The MySQL and PostgreSQL
 * branches are structurally equivalent (same query intent, different SQL
 * dialect); they run when CI executes this same suite against MySQL 8 and
 * PostgreSQL 14 (see .github/workflows/ci.yml — Integration is included in
 * all three DB jobs). A regression in any vendor branch will therefore break
 * the corresponding CI job.
 */
class IndexManagerFlushTest extends TestCase
{
    private IndexManager $manager;

    /** Deterministic model-type string used across this test class */
    private const MODEL_TYPE = 'FlushTestUser';

    /** A second model-type used to verify flush() isolation */
    private const OTHER_TYPE = 'FlushTestOtherModel';

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new IndexManager(new WhitespaceTokenizer(), new NullStemmer());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Seed the index tables directly (no ORM / indexModel) for MODEL_TYPE.
     * Inserts `count` unique document rows, each with a distinct term, plus a
     * shared term 'shared' to exercise orphan cleanup when all postings for it
     * are removed together.
     *
     * @param int $count Number of documents to seed (default 3)
     */
    private function seedIndexData(int $count = 3): void
    {
        // Insert terms: one unique per doc + one shared across all docs
        $terms = ['shared'];
        for ($i = 1; $i <= $count; $i++) {
            $terms[] = "unique{$i}";
        }

        foreach ($terms as $term) {
            DB::table('fuzzy_index_terms')->insert(['term' => $term, 'doc_count' => $count]);
        }

        $termIds = DB::table('fuzzy_index_terms')
            ->whereIn('term', $terms)
            ->pluck('id', 'term');

        $postingRows  = [];
        $documentRows = [];

        for ($i = 1; $i <= $count; $i++) {
            $modelId = 8000 + $i;

            // One posting for the shared term
            $postingRows[] = [
                'term_id'    => $termIds['shared'],
                'model_type' => self::MODEL_TYPE,
                'model_id'   => $modelId,
                'frequency'  => 1,
            ];
            // One posting for the doc-unique term
            $postingRows[] = [
                'term_id'    => $termIds["unique{$i}"],
                'model_type' => self::MODEL_TYPE,
                'model_id'   => $modelId,
                'frequency'  => 2,
            ];

            $documentRows[] = [
                'model_type' => self::MODEL_TYPE,
                'model_id'   => $modelId,
                'doc_length' => 3,
            ];
        }

        DB::table('fuzzy_index_postings')->insert($postingRows);
        DB::table('fuzzy_index_documents')->insert($documentRows);
        DB::table('fuzzy_index_meta')->insert([
            'model_type'     => self::MODEL_TYPE,
            'total_docs'     => $count,
            'total_tokens'   => $count * 3,
            'avg_doc_length' => 3,
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Core flush() contract:
     *  1. After seeding 3 model documents into the index, postings exist.
     *  2. After flush(), all postings for that model_type are gone.
     *  3. Terms that had postings only from those models are cleaned up (no
     *     orphan rows remain in fuzzy_index_terms after the flush).
     *  4. The meta row for the model_type is also removed.
     */
    public function test_flush_removes_all_postings_and_orphan_terms(): void
    {
        // ── Arrange: seed 3 documents ─────────────────────────────────────────
        $this->seedIndexData(3);

        // ── Assert pre-condition: postings exist ──────────────────────────────
        $postingsBefore = DB::table('fuzzy_index_postings')     // line 107
            ->where('model_type', self::MODEL_TYPE)
            ->count();
        $this->assertGreaterThan(0, $postingsBefore,
            'Expected postings to exist before flush()');

        $termsBefore = DB::table('fuzzy_index_terms')->count();
        $this->assertGreaterThan(0, $termsBefore,
            'Expected terms to exist before flush()');

        // ── Act ───────────────────────────────────────────────────────────────
        $this->manager->flush(self::MODEL_TYPE);                // line 117

        // ── Assert: postings are gone ─────────────────────────────────────────
        $postingsAfter = DB::table('fuzzy_index_postings')      // line 120
            ->where('model_type', self::MODEL_TYPE)
            ->count();
        $this->assertSame(0, $postingsAfter,
            'flush() must delete all postings for the given model_type');

        // ── Assert: documents are gone ────────────────────────────────────────
        $docsAfter = DB::table('fuzzy_index_documents')         // line 126
            ->where('model_type', self::MODEL_TYPE)
            ->count();
        $this->assertSame(0, $docsAfter,
            'flush() must delete all document rows for the given model_type');

        // ── Assert: meta row is gone ──────────────────────────────────────────
        $metaAfter = DB::table('fuzzy_index_meta')              // line 132
            ->where('model_type', self::MODEL_TYPE)
            ->count();
        $this->assertSame(0, $metaAfter,
            'flush() must delete the meta row for the given model_type');

        // ── Assert: no orphan terms remain ────────────────────────────────────
        // After flush all postings are gone; the orphan-cleanup branch of
        // flush() should have removed every term that now has no backing
        // posting row. On SQLite this uses the whereNotExists() query builder
        // path; MySQL uses DELETE...LEFT JOIN; PostgreSQL uses WHERE NOT EXISTS.
        $orphanTerms = DB::table('fuzzy_index_terms')           // line 142
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                  ->from('fuzzy_index_postings')
                  ->whereColumn('fuzzy_index_postings.term_id', 'fuzzy_index_terms.id');
            })
            ->count();
        $this->assertSame(0, $orphanTerms,
            'flush() must remove terms that have no remaining postings (orphan cleanup)');
    }

    /**
     * flush() must only affect the target model_type and leave other model
     * types' index data intact (no cross-tenant contamination).
     */
    public function test_flush_does_not_affect_other_model_types(): void
    {
        // ── Arrange: seed MODEL_TYPE data ──────────────────────────────────────
        $this->seedIndexData(2);

        // Seed a separate term exclusively referenced by OTHER_TYPE
        DB::table('fuzzy_index_terms')->insert(['term' => 'otherterm', 'doc_count' => 1]);
        $otherTermId = DB::table('fuzzy_index_terms')->where('term', 'otherterm')->value('id');

        DB::table('fuzzy_index_postings')->insert([
            'term_id'    => $otherTermId,
            'model_type' => self::OTHER_TYPE,
            'model_id'   => 9999,
            'frequency'  => 1,
        ]);
        DB::table('fuzzy_index_documents')->insert([
            'model_type' => self::OTHER_TYPE,
            'model_id'   => 9999,
            'doc_length' => 1,
        ]);
        DB::table('fuzzy_index_meta')->insert([
            'model_type'     => self::OTHER_TYPE,
            'total_docs'     => 1,
            'total_tokens'   => 1,
            'avg_doc_length' => 1,
        ]);

        // ── Act: flush only MODEL_TYPE ─────────────────────────────────────────
        $this->manager->flush(self::MODEL_TYPE);

        // ── Assert: other model_type postings survive ─────────────────────────
        $survivingPostings = DB::table('fuzzy_index_postings')  // line 189
            ->where('model_type', self::OTHER_TYPE)
            ->count();
        $this->assertSame(1, $survivingPostings,
            'flush() must not remove postings belonging to other model types');

        $survivingDocs = DB::table('fuzzy_index_documents')     // line 194
            ->where('model_type', self::OTHER_TYPE)
            ->count();
        $this->assertSame(1, $survivingDocs,
            'flush() must not remove documents belonging to other model types');

        $survivingMeta = DB::table('fuzzy_index_meta')          // line 199
            ->where('model_type', self::OTHER_TYPE)
            ->count();
        $this->assertSame(1, $survivingMeta,
            'flush() must not remove meta rows belonging to other model types');

        // The term exclusively referenced by OTHER_TYPE must also survive
        $survivingTerm = DB::table('fuzzy_index_terms')         // line 205
            ->where('term', 'otherterm')
            ->count();
        $this->assertSame(1, $survivingTerm,
            'flush() must not remove terms that still have postings from other model types');
    }

    /**
     * flush() on an already-empty index must be a no-op (no exception thrown,
     * table counts unchanged).
     */
    public function test_flush_on_empty_index_is_a_noop(): void
    {
        // No prior indexing — just flush a model_type that has no data.
        $this->manager->flush('NonExistentModelType');          // line 217

        $postings = DB::table('fuzzy_index_postings')->count();
        $terms    = DB::table('fuzzy_index_terms')->count();

        $this->assertSame(0, $postings,
            'flush() on empty index must not throw and must leave postings table empty');
        $this->assertSame(0, $terms,
            'flush() on empty index must not throw and must leave terms table empty');
    }
}
