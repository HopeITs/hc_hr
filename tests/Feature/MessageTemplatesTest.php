<?php

namespace Tests\Feature;

use App\Livewire\Messages\TemplateManager;
use App\Models\Employee;
use App\Models\MessageTemplate;
use App\Services\MessageTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * Message templates: placeholders are filled from the person, an unknown one is
 * left alone, a Syrian local number becomes a wa.me number, and only someone
 * with message-templates.manage reaches the editor.
 */
class MessageTemplatesTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_known_placeholders_are_filled_and_unknown_ones_kept(): void
    {
        $text = app(MessageTemplateRenderer::class)->fill(
            'Dear {name}, your contract ends on {contract_end_date}. {nonsense}',
            ['name' => 'Mai', 'contract_end_date' => '30/11/2026'],
        );

        $this->assertSame('Dear Mai, your contract ends on 30/11/2026. {nonsense}', $text);
    }

    public function test_a_local_number_becomes_an_international_whatsapp_number(): void
    {
        $renderer = app(MessageTemplateRenderer::class);

        $this->assertSame('963912345678', $renderer->whatsappNumber((new Employee)->forceFill(['whatsapp_work' => '0912 345 678'])));
        $this->assertSame('963911111111', $renderer->whatsappNumber((new Employee)->forceFill(['work_phone' => '0911111111'])));
        $this->assertSame('905551234567', $renderer->whatsappNumber((new Employee)->forceFill(['whatsapp_work' => '00905551234567'])));
        $this->assertNull($renderer->whatsappNumber(new Employee));
    }

    public function test_an_email_template_opens_a_mailto_with_subject_and_body(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['message-templates.use']);
        $employee = $this->makeEmployee($aleppo, 'rami');
        \App\Models\User::factory()->create(['employee_id' => $employee->id, 'email' => 'rami@hopecenter.org']);

        $template = MessageTemplate::create([
            'name' => 'Hello', 'channel' => 'email', 'language' => 'en',
            'subject' => 'Hi {first_name}', 'body' => "Line one\nLine two", 'created_by' => $hr->id,
        ]);

        $url = app(MessageTemplateRenderer::class)->url($template, $employee->fresh(), $hr);

        $this->assertStringStartsWith('mailto:rami@hopecenter.org?', $url);
        $this->assertStringContainsString('subject='.rawurlencode('Hi '.$employee->first_name), $url);
        $this->assertStringContainsString('body='.rawurlencode("Line one\r\nLine two"), $url);
    }

    public function test_the_editor_needs_the_manage_permission(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $nobody = $this->makeUser($aleppo, 'employee', []);
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['message-templates.manage']);

        $this->actingAs($nobody)->get(route('message-templates.index'))->assertForbidden();

        Livewire::actingAs($hr)->test(TemplateManager::class)
            ->call('newTemplate')
            ->set('name', 'Contract ending')
            ->set('channel', 'whatsapp')
            ->set('body', 'Dear {name}, your contract ends on {contract_end_date}.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('message_templates', ['name' => 'Contract ending', 'channel' => 'whatsapp', 'subject' => null]);
    }
}
