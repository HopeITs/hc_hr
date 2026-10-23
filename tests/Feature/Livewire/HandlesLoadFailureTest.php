<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Concerns\HandlesLoadFailure;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Component;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class HandlesLoadFailureTest extends TestCase
{
    public function test_a_failing_load_is_reported_flagged_and_never_shown(): void
    {
        Exceptions::fake();

        Livewire::test(LoadFailureFixture::class)
            ->assertSet('loadFailed', true)
            ->assertSee(__('general.state-error-title'))
            ->assertDontSee('SECRET-SQL-DETAIL');

        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'SECRET-SQL-DETAIL'));
    }

    public function test_retry_clears_the_flag_and_loads_when_the_cause_is_gone(): void
    {
        Exceptions::fake();

        Livewire::test(LoadFailureFixture::class)
            ->assertSet('loadFailed', true)
            ->set('broken', false)
            ->call('retryLoad')
            ->assertSet('loadFailed', false)
            ->assertSee('2 rows loaded');
    }

    public function test_shared_state_components_render_accessibly(): void
    {
        $filtered = Blade::render('<x-center-empty-state variant="filtered"><x-slot:action>GO</x-slot:action></x-center-empty-state>');
        $this->assertStringContainsString('role="status"', $filtered);
        $this->assertStringContainsString('GO', $filtered);
        $this->assertStringContainsString(__('general.state-filtered-title'), $filtered);

        $legacy = Blade::render('<x-center-empty-state title="T-LEGACY" description="D-LEGACY" />');
        $this->assertStringContainsString('T-LEGACY', $legacy);
        $this->assertStringContainsString('D-LEGACY', $legacy);

        $this->assertStringContainsString('aria-hidden="true"', Blade::render('<x-ui.skeleton variant="card" />'));
        $this->assertStringContainsString('motion-safe:animate-pulse', Blade::render('<x-ui.skeleton />'));
    }
}

class LoadFailureFixture extends Component
{
    use HandlesLoadFailure;

    public bool $broken = true;

    public int $count = 0;

    public function render()
    {
        $rows = $this->guardedLoad(function () {
            if ($this->broken) {
                throw new RuntimeException('SECRET-SQL-DETAIL');
            }

            return [1, 2];
        }, fallback: []);

        $this->count = count($rows);

        return <<<'BLADE'
        <div>
            @if ($loadFailed)
                <x-ui.load-error />
            @else
                <p>{{ $count }} rows loaded</p>
            @endif
        </div>
        BLADE;
    }
}
