<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AddShadowColumnCommand extends Command
{
    protected $signature = 'fuzzy-search:add-shadow-column
                            {model : Fully-qualified model class, e.g. App\\Models\\User}
                            {column : Column to add the shadow for, e.g. name}
                            {--type=metaphone : Shadow column type (currently: metaphone)}';

    protected $description = 'Generate a migration that adds a fuzzy-search shadow column to the model\'s table';

    public function handle(): int
    {
        $modelClass = $this->argument('model');
        $column     = $this->argument('column');
        $type       = $this->option('type');

        if (!class_exists($modelClass)) {
            $this->error("Model class [{$modelClass}] not found.");
            return self::FAILURE;
        }

        $model = new $modelClass();

        if (!$model instanceof \Illuminate\Database\Eloquent\Model) {
            $this->error("[{$modelClass}] is not an Eloquent model.");
            return self::FAILURE;
        }

        $table        = $model->getTable();
        $shadowColumn = $column . '_' . $type;
        $className    = 'Add' . Str::studly($shadowColumn) . 'To' . Str::studly($table) . 'Table';
        $timestamp    = date('Y_m_d_His');
        $filename     = database_path("migrations/{$timestamp}_add_{$shadowColumn}_to_{$table}_table.php");

        $stub = <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('{$table}', function (Blueprint \$table) {
            \$table->string('{$shadowColumn}')->nullable()->after('{$column}');
            \$table->index('{$shadowColumn}');
        });
    }

    public function down(): void
    {
        Schema::table('{$table}', function (Blueprint \$table) {
            \$table->dropIndex(['{$shadowColumn}']);
            \$table->dropColumn('{$shadowColumn}');
        });
    }
};
PHP;

        file_put_contents($filename, $stub);

        $this->info("Migration created: {$filename}");
        $this->line("Run <comment>php artisan migrate</comment> to apply it.");
        $this->line("Then run <comment>php artisan fuzzy-search:index {$modelClass} --fresh</comment> to backfill the shadow column.");

        return self::SUCCESS;
    }
}
