<?php

namespace Ashiqfardus\LaravelFuzzySearch\Exceptions;

use Exception;

/**
 * Base Exception for Laravel Fuzzy Search Package
 *
 * This is the parent class for all package-specific exceptions.
 * It provides a consistent way to handle and identify errors from
 * this package, allowing developers to catch all fuzzy search
 * related exceptions with a single catch block.
 *
 * Usage:
 * ```php
 * try {
 *     User::search($term)->get();
 * } catch (LaravelFuzzySearchException $e) {
 *     // Handle any fuzzy search related error
 *     Log::error('Search failed: ' . $e->getMessage());
 * }
 * ```
 *
 * Child Exceptions:
 * - EmptySearchTermException: Thrown when search term is empty
 * - InvalidAlgorithmException: Thrown when an unsupported algorithm is specified
 * - InvalidConfigException: Thrown when configuration is invalid
 * - SearchableColumnsNotFoundException: Thrown when no searchable columns are found
 */
class LaravelFuzzySearchException extends Exception
{
    /**
     * Additional context data for debugging
     */
    protected array $context = [];

    /**
     * Create a new exception instance
     *
     * @param string $message
     * @param array $context Additional debugging context
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = '',
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the exception context data
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * The context Laravel's exception handler adds to the log entry
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Add context to the exception
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function withContext(string $key, mixed $value): self
    {
        $this->context[$key] = $value;
        return $this;
    }

    /**
     * Get a formatted error report for logging
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'exception' => static::class,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'context' => $this->context,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}
