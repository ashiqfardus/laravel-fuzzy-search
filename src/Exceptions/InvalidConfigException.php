<?php

namespace Ashiqfardus\LaravelFuzzySearch\Exceptions;

class InvalidConfigException extends LaravelFuzzySearchException
{
    public function __construct(string $message)
    {
        parent::__construct("Invalid configuration: {$message}");
    }
}

