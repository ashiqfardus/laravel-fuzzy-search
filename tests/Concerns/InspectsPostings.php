<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Concerns;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The postings as the tests read and write them: by model type and column name, as the layout
 * before 2.1's compact one named them. fuzzy_index_postings keys a posting by the ids of its model
 * type (fuzzy_index_model_types) and its column (fuzzy_index_columns), and carries its document's
 * length.
 */
trait InspectsPostings
{
    /**
     * The postings as a table named $alias with the columns term_id, model_type, model_id,
     * column_name, frequency and doc_length, so a test filters and joins them by name.
     */
    protected function namedPostings(string $alias = 'fuzzy_index_postings'): Builder
    {
        return DB::query()->fromSub(
            DB::table('fuzzy_index_postings as fp')
                ->join('fuzzy_index_model_types as fmt', 'fmt.id', '=', 'fp.model_type_id')
                ->join('fuzzy_index_columns as fc', 'fc.id', '=', 'fp.column_id')
                ->select('fp.term_id', 'fmt.model_type', 'fp.model_id', 'fc.name as column_name', 'fp.frequency', 'fp.doc_length'),
            $alias
        );
    }

    /**
     * Insert postings given by model type and column name (default ''), each with its document's
     * length: the row's doc_length, else its document row's, else 0. 300 rows a statement: six
     * bindings a row, under SQL Server's 2,100.
     *
     * @param list<array<string, mixed>> $rows
     */
    protected function insertNamedPostings(array $rows): void
    {
        $lengths = [];
        foreach (array_chunk(array_values(array_unique(array_map(fn (array $row) => (string) $row['model_id'], $rows))), 1000) as $ids) {
            foreach (DB::table('fuzzy_index_documents')->whereIn('model_id', $ids)->get() as $document) {
                $document = (object) $document;
                $lengths[$document->model_type . '|' . $document->model_id] = (int) $document->doc_length;
            }
        }

        $rows = array_map(fn (array $row) => [
            'term_id'       => $row['term_id'],
            'model_type_id' => $this->modelTypeId($row['model_type']),
            'model_id'      => (string) $row['model_id'],
            'column_id'     => $this->columnId($row['column_name'] ?? ''),
            'frequency'     => $row['frequency'] ?? 1,
            'doc_length'    => (int) ($row['doc_length'] ?? $lengths[$row['model_type'] . '|' . $row['model_id']] ?? 0),
        ], $rows);

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('fuzzy_index_postings')->insert($chunk);
        }
    }

    /**
     * fuzzy_index_model_terms mirrors the postings (ruling ER-197): one row per model type and word
     * it posts, counting the type's documents holding the word; per word, the types' counts sum to
     * the dictionary's doc_count; each row's word and length are the dictionary's.
     */
    protected function assertModelTermsMirrorPostings(string $after = ''): void
    {
        $expected = [];
        foreach (DB::table('fuzzy_index_postings')->groupBy('model_type_id', 'term_id')->select('model_type_id', 'term_id')->selectRaw('count(distinct ' . DB::getQueryGrammar()->wrap('model_id') . ') as n')->get() as $row) {
            $row                                              = (object) $row; // whatever the app's fetch mode
            $expected[$row->model_type_id . ':' . $row->term_id] = (int) $row->n;
        }

        $actual = [];
        $sums   = [];
        $copies = [];
        $rows   = DB::table('fuzzy_index_model_terms as mt')->join('fuzzy_index_terms as t', 't.id', '=', 'mt.term_id')
            ->get(['mt.model_type_id', 'mt.term_id', 'mt.doc_count', 'mt.term', 'mt.term_length', 't.term as dictionary_term', 't.term_length as dictionary_length']);
        foreach ($rows as $row) {
            $row                                            = (object) $row;
            $actual[$row->model_type_id . ':' . $row->term_id] = (int) $row->doc_count;
            $sums[(int) $row->term_id]                      = ($sums[(int) $row->term_id] ?? 0) + (int) $row->doc_count;
            if ([(string) $row->term, (int) $row->term_length] !== [(string) $row->dictionary_term, (int) $row->dictionary_length]) {
                $copies[] = "{$row->term}/{$row->term_length} for {$row->dictionary_term}/{$row->dictionary_length}";
            }
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);

        $this->assertSame($expected, $actual, "fuzzy_index_model_terms against the postings, after {$after}");
        $this->assertSame([], $copies, "the word and its length as the dictionary has them, after {$after}");

        $counts = DB::table('fuzzy_index_terms')->pluck('doc_count', 'id')->map(fn ($count) => (int) $count)->all();
        $this->assertSame(
            array_filter($counts),
            array_filter(array_replace(array_fill_keys(array_keys($counts), 0), $sums)),
            "the dictionary's doc_count against the sum of the types' counts, after {$after}"
        );
    }

    /** The id of $modelType's row in fuzzy_index_model_types, inserted on first need. */
    protected function modelTypeId(string $modelType): int
    {
        return IndexManager::modelTypeId($modelType, insert: true);
    }

    /** The id of $name's row in fuzzy_index_columns, inserted on first need. */
    protected function columnId(string $name): int
    {
        return IndexManager::columnIds([$name], insert: true)[$name];
    }
}
