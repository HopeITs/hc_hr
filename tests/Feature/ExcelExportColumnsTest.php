<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every column a table offers must be known to its Excel export, in both
 * locales. An export falls back to the raw column code as the heading and an
 * empty cell as the value, so a renamed column (e.g. `center.name` becoming
 * `center.name_en`) silently ships an empty column headed `center_name_en`.
 */
class ExcelExportColumnsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string, class-string}> */
    public static function tablesWithExports(): array
    {
        $pairs = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app/Livewire'));

        foreach ($files as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }

            $source = file_get_contents($file);

            if (! str_contains($source, 'function exportExcel')
                || ! preg_match('/^use (App\\\\Exports\\\\\w+);/m', $source, $export)
                || ! preg_match('/^namespace ([^;]+);/m', $source, $namespace)) {
                continue;
            }

            $table = $namespace[1].'\\'.basename($file, '.php');
            $pairs[class_basename($table)] = [$table, $export[1]];
        }

        ksort($pairs);

        return $pairs;
    }

    #[DataProvider('tablesWithExports')]
    public function test_every_table_column_has_an_export_heading(string $table, string $export): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $component = app($table);
            $columns = (new ReflectionMethod($component, 'resolveColumns'))->invoke($component);

            $codes = collect($columns)
                ->filter(fn ($column) => $column->label() !== __('general.actions'))
                ->map(fn ($column) => $column->code())
                ->values()
                ->all();

            $headings = (new $export(new Collection, $codes))->headings();
            $unmapped = array_values(array_filter($codes, fn ($code, $i) => $headings[$i] === $code, ARRAY_FILTER_USE_BOTH));

            $this->assertSame([], $unmapped, class_basename($export)." has no mapping for these {$locale} columns of ".class_basename($table));
        }
    }
}
