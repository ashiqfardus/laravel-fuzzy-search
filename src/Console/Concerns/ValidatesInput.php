<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console\Concerns;

use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;

/**
 * Argument checks the commands share: each prints why the input cannot be used and returns
 * false (or null), so the command can return FAILURE instead of reporting success or crashing.
 */
trait ValidatesInput
{
    /**
     * $class must exist and be an Eloquent model. $require adds: 'indexable', a model the index
     * can read (getSearchableColumns(): the Searchable trait's, or a Scout model's own); or
     * 'searchable', the Searchable trait itself, whose search() returns the fluent builder.
     */
    protected function validModel(string $class, string $require = ''): bool
    {
        if (!class_exists($class)) {
            $this->error("Model class [{$class}] not found.");
            return false;
        }

        if (!is_subclass_of($class, Model::class)) {
            $this->error("[{$class}] is not an Eloquent model.");
            return false;
        }

        $ok = match ($require) {
            'indexable'  => method_exists($class, 'getSearchableColumns'),
            'searchable' => in_array(Searchable::class, class_uses_recursive($class), true),
            default      => true,
        };

        if (!$ok) {
            $this->error("[{$class}] does not use the " . Searchable::class . ' trait.');
        }

        return $ok;
    }

    /** A short name ("User") is looked up under App\Models; a qualified one is taken as given. */
    protected function modelName(string $name): string
    {
        return class_exists($name) || str_contains($name, '\\') ? $name : 'App\\Models\\' . $name;
    }

    /** The --$name option as an integer of at least $min, or null after printing the error. */
    protected function integerOption(string $name, int $min): ?int
    {
        $value = filter_var($this->option($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]);

        if ($value === false) {
            $this->error("--{$name} must be a whole number of at least {$min}.");
            return null;
        }

        return $value;
    }
}
