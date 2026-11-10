<?php

namespace App\Livewire\Layout;

use App\Support\Navigation;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Throwable;

class Breadcrumbs extends Component
{
    public string $title = 'Page Title';

    /** @var array<int, array{label: string, url?: string|null}> */
    public array $crumbs = [];

    public bool $showButton = false;

    /**
     * The menu area this page sits in for the active role (C1): the first
     * crumb. Worked out once on the page request; a later Livewire update
     * runs on the update route, which is in no area.
     */
    public ?string $area = null;

    public function mount(): void
    {
        $user = auth()->user();

        $this->area = $user
            ? Navigation::areaFor(request()->route()?->getName(), $user, Navigation::isDeliverResponsible($user))
            : null;
    }

    public function render()
    {
        // Normalize crumbs & compute href
        $items = collect($this->crumbs)->map(function ($c) {
            $label = (string) ($c['label'] ?? '');
            $href = null;

            if (! empty($c['url'])) {
                $href = (string) $c['url'];
            } elseif (! empty($c['route']) && Route::has($c['route'])) {
                try {
                    $href = route($c['route'], $c['params'] ?? []);
                } catch (Throwable $e) {
                    $href = null;
                }
            }

            return ['label' => $label, 'href' => $href];
        })->toArray();

        if ($this->area !== null) {
            array_unshift($items, ['label' => $this->area, 'href' => null]);
        }

        return view('livewire.layout.breadcrumbs', [
            'items' => $items,
            'title' => $this->title,
            'showButton' => $this->showButton,
        ]);
    }
}
