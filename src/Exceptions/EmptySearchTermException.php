<?php

namespace Ashiqfardus\LaravelFuzzySearch\Exceptions;

class EmptySearchTermException extends LaravelFuzzySearchException
{
    public function __construct(string $message = 'Search term cannot be empty. Please provide a valid search query.')
    {
        parent::__construct($message);
    }
}

