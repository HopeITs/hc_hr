<?php

namespace App\Livewire\News;

use App\Services\News\Publishing\ExternalPublishing;
use App\Support\News\NewsAccess;
use Livewire\Component;

class NewsModule extends Component
{
    public function render()
    {
        return view('livewire.news.news-module', [
            'canCreate' => NewsAccess::canCreate(auth()->user()),
            // No external publisher configured yet: say so in one quiet line,
            // never with a button that pretends to publish.
            'externalPublishingPending' => NewsAccess::canCreate(auth()->user())
                && ! app(ExternalPublishing::class)->isAvailable(),
        ]);
    }
}
