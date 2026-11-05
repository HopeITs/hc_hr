<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Every class in app/Exports must still be declarable against the installed
 * maatwebsite/excel concerns. A signature that drifts from an interface (as
 * `styles()` did when v4 added `: ?array`) is a compile-time fatal error that
 * cannot be caught in-process, so each class is loaded in its own PHP process.
 */
class ExcelExportClassesTest extends TestCase
{
    public static function exportClasses(): array
    {
        $classes = [];

        foreach (glob(dirname(__DIR__, 2).'/app/Exports/*.php') as $file) {
            $class = 'App\\Exports\\'.basename($file, '.php');
            $classes[$class] = [$class];
        }

        return $classes;
    }

    #[DataProvider('exportClasses')]
    public function test_export_class_is_compatible_with_its_concerns(string $class): void
    {
        $root = dirname(__DIR__, 2);
        $code = sprintf(
            'require %s; exit(class_exists(%s) ? 0 : 1);',
            var_export($root.'/vendor/autoload.php', true),
            var_export($class, true),
        );

        $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], $root);
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            "{$class} cannot be loaded:\n".trim($process->getOutput().$process->getErrorOutput()),
        );
    }
}
