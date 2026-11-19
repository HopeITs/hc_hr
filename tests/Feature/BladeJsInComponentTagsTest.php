<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Blade does not compile the @js directive inside the tag of a component (<x-card …>, <x-button …>):
 * the browser receives the literal text "@js($value)" and Alpine throws "Invalid or unexpected token",
 * which silently breaks the page. Tests rendering the HTML never see it. Inside a component tag use
 * {{ \Illuminate\Support\Js::from($value) }}; on a plain element @js is fine.
 */
class BladeJsInComponentTagsTest extends TestCase
{
    public function test_no_view_puts_the_js_directive_inside_a_component_tag(): void
    {
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            if (preg_match_all('/<x-[\w.\-:]+(?:[^>]|=>)*?@js\(/s', $file->getContents(), $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as [$text, $offset]) {
                    $line = substr_count($file->getContents(), "\n", 0, $offset) + 1;
                    $offenders[] = str_replace('\\', '/', $file->getRelativePathname()).":$line";
                }
            }
        }

        $this->assertSame([], $offenders, "@js inside a component tag is not compiled (use {{ Js::from() }}):\n  ".implode("\n  ", $offenders));
    }
}