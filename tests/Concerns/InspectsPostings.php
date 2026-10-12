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
