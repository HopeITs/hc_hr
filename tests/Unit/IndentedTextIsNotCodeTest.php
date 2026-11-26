<?php

namespace Tests\Unit;

use App\Notifications\Messages\BrandedMailMessage;
use App\Services\Policies\PolicyRenderer;
use Illuminate\Support\Str;
use Tests\TestCase;

class IndentedTextIsNotCodeTest extends TestCase
{
    public function test_a_list_item_is_never_a_code_block_however_wide_the_gap_after_its_marker(): void
    {
        $renderer = new PolicyRenderer;

        foreach (['-', '*', '+', '1.', '12)'] as $marker) {
            foreach ([' ', '  ', '    ', '     ', '        ', "\t", " \t"] as $gap) {
                foreach (['', '  ', '    ', "\t", '      '] as $indent) {
                    $html = $renderer->html("نص:\n\n{$indent}{$marker}{$gap}تُستخدم المعلومات الشخصية\n{$indent}{$marker}{$gap}بند ثان");

                    $this->assertStringNotContainsString('<pre', $html, json_encode([$indent, $marker, $gap]));
                    $this->assertStringContainsString('تُستخدم المعلومات الشخصية', $html);
                }
            }
        }
    }

    public function test_an_indented_line_is_text_for_the_assistant_and_for_mail(): void
    {
        $text = "السطر الأول:\n\tسطر بإزاحة\n    سطر آخر\n\n- بند\n  - فرعي";

        $html = Str::markdown(PolicyRenderer::withoutCodeBlocks($text), ['html_input' => 'strip']);
        $this->assertStringNotContainsString('<pre>', $html);
        $this->assertStringContainsString('<ul>', $html);

        $mail = (new BrandedMailMessage)->line($text)->render();
        $this->assertStringNotContainsString('<pre>', (string) $mail);
        $this->assertStringContainsString('سطر بإزاحة', (string) $mail);
    }
}
