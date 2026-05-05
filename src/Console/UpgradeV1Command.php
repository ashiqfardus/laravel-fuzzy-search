<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class UpgradeV1Command extends Command
{
    protected $signature = 'fuzzy-search:upgrade-v1
                            {path=app : Directory to scan (relative to project root)}';

    protected $description = 'Scan PHP files for v1-era API patterns and suggest migration actions';

    /**
     * v1 patterns: [ regex, label, action ]
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const PATTERNS = [
        [
            'use .*Traits[\\\\]Fuzzy(?![a-zA-Z])',
            'Deprecated Fuzzy trait',
            'Replace with Searchable trait',
        ],
        [
            '(?:->|::)searchFuzzy\\s*\\(',
            'scopeSearchFuzzy usage',
            'Replace with User::search()->searchIn()->get()',
        ],
        [
            '(?:->|::)reindex\s*\(',
            '->reindex() call',
            'Replace with: php artisan fuzzy-search:rebuild',
        ],
        [
            'fuzzy-search:index',
            'fuzzy-search:index artisan call',
            'Replace with: php artisan fuzzy-search:rebuild',
        ],
        [
            'ReindexModelJob',
            'ReindexModelJob usage',
            'Replace with IndexModelJob or RebuildIndexJob',
        ],
        [
            '[\'"]use_index[\'"]',
            'use_index config key',
            'Rename to inverted_index.use_for or call ->useInvertedIndex()',
        ],
        [
            '[\'"]search_index[\'"]',
            'search_index table reference',
            'Remove; v2 uses fuzzy_index_* tables (auto-migrated)',
        ],
        [
            '->useIndex\s*\(',
            '->useIndex() deprecated call',
            'Replace with ->useInvertedIndex()',
        ],
    ];

    public function handle(): int
    {
        foreach (self::PATTERNS as [$pattern]) {
            if (@preg_match('/' . $pattern . '/i', '') === false) {
                $this->error("Internal error: invalid regex pattern: {$pattern}");
                return self::FAILURE;
            }
        }

        $rawPath  = $this->argument('path');
        $isAbsolute = str_starts_with($rawPath, '/');
        $scanPath   = $isAbsolute ? $rawPath : base_path($rawPath);

        // For relative paths, enforce containment within the project root to
        // prevent directory-traversal via inputs like ../../etc.
        if (!$isAbsolute) {
            $realScanPath = realpath($scanPath);
            $realBasePath = realpath(base_path());

            if ($realScanPath !== false && $realBasePath !== false
                && !str_starts_with($realScanPath, $realBasePath)
            ) {
                $this->error('Path must be within the project root.');
                return self::FAILURE;
            }

            if ($realScanPath !== false) {
                $scanPath = $realScanPath;
            }
        }

        if (!is_dir($scanPath)) {
            $this->error("Directory not found: {$scanPath}");
            return self::FAILURE;
        }

        $finder = new Finder();
        $finder->files()->name('*.php')->in($scanPath);

        $rows = [];

        foreach ($finder as $file) {
            $filePath = $file->getRealPath();
            $lines    = file($filePath, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $lineNumber => $lineContent) {
                foreach (self::PATTERNS as [$pattern, $label, $action]) {
                    if (preg_match('/' . $pattern . '/i', $lineContent)) {
                        $displayPath = ltrim(str_replace($scanPath, '', $filePath), '/\\');
                        $rows[]      = [$displayPath, $lineNumber + 1, $label, $action];
                    }
                }
            }
        }

        if (empty($rows)) {
            $this->info('No v1 patterns found — your codebase looks clean for v2!');
            return self::SUCCESS;
        }

        $this->table(['File', 'Line', 'Pattern', 'Action'], $rows);
        $this->newLine();
        $this->line('Full migration guide: docs/UPGRADE_v1_TO_v2.md');

        return self::FAILURE;
    }
}
