<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Features\SupportToasts;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class WithFeedbackTest extends TestCase
{
    public function test_a_toast_is_sent_to_the_browser(): void
    {
        Livewire::test(FeedbackFixture::class)
            ->call('save')
            ->assertDispatched('toast', type: 'success', message: 'Saved')
            ->assertDispatched('toast', fn (string $name, array $params) => empty($params['undo']));
    }

    public function test_a_toast_survives_alongside_the_components_own_events(): void
    {
        // Regression: Livewire's event hook replaces the dispatch list rather
        // than appending to it, which once dropped every toast raised next to
        // another event (EmployeeEdit dispatches form-saved on each save).
        Livewire::test(FeedbackFixture::class)
            ->call('saveAndAnnounce')
            ->assertDispatched('form-saved')
            ->assertDispatched('toast', message: 'Saved');
    }

    public function test_a_toast_without_a_message_uses_the_generic_success_message(): void
    {
        Livewire::test(FeedbackFixture::class)
            ->call('saveSilently')
            ->assertDispatched('toast', type: 'success', message: __('general.success-message'));
    }

    public function test_an_undo_toast_calls_back_into_the_same_component(): void
    {
        $component = Livewire::test(FeedbackFixture::class)->call('remove', 7);

        $component->assertDispatched('toast', function (string $name, array $params) use ($component) {
            return $params['undo'] === [
                'component' => $component->id(),
                'method' => 'restore',
                'payload' => ['id' => 7],
            ];
        });
    }

    public function test_a_toast_raised_before_a_redirect_is_flashed_for_the_next_page(): void
    {
        Livewire::test(FeedbackFixture::class)
            ->call('saveAndLeave')
            ->assertRedirect('/elsewhere')
            ->assertNotDispatched('toast');

        // Undo is dropped: the component it would call does not exist on the next page.
        $this->assertSame(
            [['type' => 'success', 'message' => 'Saved', 'undo' => null]],
            session(SupportToasts::SESSION_KEY),
        );
    }

    public function test_an_unknown_toast_type_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Livewire::test(FeedbackFixture::class)->call('warn');
    }

    public function test_confirm_defaults_to_the_delete_question(): void
    {
        $component = Livewire::test(FeedbackFixture::class)->call('confirmDelete', 3);

        $component->assertDispatched('confirm-dialog',
            componentId: $component->id(),
            method: 'delete',
            payload: ['id' => 3],
            title: __('general.delete-item'),
            message: __('general.confirm-delete-item'),
            html: null,
            confirmLabel: __('general.delete'),
            danger: true,
        );
    }

    public function test_confirm_can_ask_a_non_destructive_question(): void
    {
        Livewire::test(FeedbackFixture::class)
            ->call('confirmToggle')
            ->assertDispatched('confirm-dialog',
                method: 'toggle',
                payload: null,
                title: 'Toggle',
                message: 'Sure?',
                confirmLabel: 'Save',
                danger: false,
            );
    }

    public function test_the_browser_cannot_raise_toasts_or_confirmations_itself(): void
    {
        $this->assertFalse((new ReflectionMethod(FeedbackFixture::class, 'toast'))->isPublic());
        $this->assertFalse((new ReflectionMethod(FeedbackFixture::class, 'confirm'))->isPublic());
    }

    public function test_the_layout_renders_flashed_toasts_and_the_status_message(): void
    {
        session()->put(SupportToasts::SESSION_KEY, [['type' => 'error', 'message' => 'Could not save', 'undo' => null]]);
        session()->put('status', 'Password reset link sent');

        $this->blade('<x-toasts/>')
            ->assertSee('Could not save')
            ->assertSee('Password reset link sent')
            ->assertSee('aria-live="polite"', false);
    }

    public function test_a_status_code_is_left_to_the_page_that_set_it(): void
    {
        session()->put('status', 'verification-link-sent');

        $this->blade('<x-toasts/>')->assertDontSee('verification-link-sent');
    }
}

class FeedbackFixture extends Component
{
    use WithFeedback;

    public function save(): void
    {
        $this->toast('Saved');
    }

    public function saveAndAnnounce(): void
    {
        $this->dispatch('form-saved');
        $this->toast('Saved');
    }

    public function saveSilently(): void
    {
        $this->toast();
    }

    public function saveAndLeave(): void
    {
        $this->toast('Saved', undo: 'restore');
        $this->redirect('/elsewhere');
    }

    public function remove(int $id): void
    {
        $this->toast('Removed', undo: 'restore', undoPayload: ['id' => $id]);
    }

    public function warn(): void
    {
        $this->toast('Careful', type: 'warning');
    }

    public function confirmDelete(int $id): void
    {
        $this->confirm('delete', ['id' => $id]);
    }

    public function confirmToggle(): void
    {
        $this->confirm('toggle', title: 'Toggle', message: 'Sure?', confirmLabel: 'Save', danger: false);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
