<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class UpgradeV1CommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/fuzzy-v1-scan-' . uniqid();
        mkdir($this->tmpDir . '/app/Models', 0755, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->tmpDir . '/app/Models/*.php') as $f) unlink($f);
        @rmdir($this->tmpDir . '/app/Models');
        @rmdir($this->tmpDir . '/app');
        @rmdir($this->tmpDir);
    }

    public function test_command_detects_deprecated_fuzzy_trait(): void
    {
        file_put_contents($this->tmpDir . '/app/Models/User.php',
            "<?php\nuse Ashiqfardus\\LaravelFuzzySearch\\Traits\\Fuzzy;\nclass User { use Fuzzy; }\n"
        );

        $this->artisan('fuzzy-search:upgrade-v1', ['path' => $this->tmpDir . '/app'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Fuzzy');
    }

    public function test_command_detects_reindex_call(): void
    {
        file_put_contents($this->tmpDir . '/app/Models/Post.php',
            "<?php\nclass Post {\n    public function sync() { \$this->reindex(); }\n}\n"
        );

        $this->artisan('fuzzy-search:upgrade-v1', ['path' => $this->tmpDir . '/app'])
            ->assertExitCode(1)
            ->expectsOutputToContain('reindex');
    }

    public function test_command_reports_clean_when_no_v1_patterns(): void
    {
        file_put_contents($this->tmpDir . '/app/Models/Clean.php',
            "<?php\nuse Ashiqfardus\\LaravelFuzzySearch\\Traits\\Searchable;\nclass Clean { use Searchable; }\n"
        );

        $this->artisan('fuzzy-search:upgrade-v1', ['path' => $this->tmpDir . '/app'])
            ->assertExitCode(0)
            ->expectsOutputToContain('No v1 patterns found');
    }

    public function test_command_exits_with_code_1_when_issues_found(): void
    {
        file_put_contents($this->tmpDir . '/app/Models/Legacy.php',
            "<?php\nuse Ashiqfardus\\LaravelFuzzySearch\\Traits\\Fuzzy;\n"
        );

        $this->artisan('fuzzy-search:upgrade-v1', ['path' => $this->tmpDir . '/app'])
            ->assertExitCode(1);
    }
}
