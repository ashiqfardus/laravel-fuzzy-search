<?php

namespace Ashiqfardus\LaravelFuzzySearch\Exceptions;

class SearchableColumnsNotFoundException extends LaravelFuzzySearchException
{
    public function __construct(string $modelClass = '')
    {
        $context = $modelClass !== '' ? " for model '{$modelClass}'" : '';
        parent::__construct(
            "No searchable columns found{$context}. " .
            "Please define \$searchable property or \$fillable columns in your model, " .
            "or specify columns using searchIn() method."
        );
    }
}

