<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Illuminate\Console\Command;

/** The same as fuzzy-search:clear <model>, kept under its own name; clear holds the one code path. */
class FlushCommand extends Command
{
    protected $signature   = 'fuzzy-search:flush
                              {model : Fully-qualified model class e.g. App\\Models\\User}';
    protected $description = 'Remove all index entries for a model (the same as fuzzy-search:clear <model>)';

    public function handle(): int
    {
        return $this->call('fuzzy-search:clear', ['model' => $this->argument('model')]);
    }
}
