<?php

namespace App\Livewire\News;

use App\Livewire\Concerns\RemembersReturnTo;
use App\Livewire\Concerns\WithFeedback;
use App\Livewire\Forms\NewsForm;
use App\Models\News;
use Livewire\Component;
use Livewire\WithFileUploads;

class NewsCreate extends Component
{
    use RemembersReturnTo;
    use WithFeedback;
    use WithFileUploads;

    public NewsForm $form;

    public ?News $news = null;

    public function mount(?News $news = null): void
    {
        if ($news?->exists) {
            abort_unless($this->canManageNews($news), 403);

            $this->news = $news;
            $this->form->fillFromNews($news);

            return;
        }

        abort_unless($this->canCreateNews(), 403);
    }

    public function updatedFormNewsableType(): void
    {
        $this->form->updatedNewsableType();
    }

    public function save()
    {
        abort_unless(
            $this->news ? $this->canManageNews($this->news) : $this->canCreateNews(),
            403,
        );

        $this->news = $this->form->persist($this->news);

        $this->toast();

        return $this->redirectRoute('news.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.news.news-create');
    }

    private function canCreateNews(): bool
    {
        return $this->canManageAllNews() || $this->isManager();
    }

    private function canManageNews(News $news): bool
    {
        return $this->canManageAllNews()
            || ($this->isManager() && (int) $news->created_by === (int) auth()->id());
    }

    private function canManageAllNews(): bool
    {
        return auth()->user()?->activeRoleIs('HR', 'super-admin', 'general-manager') ?? false;
    }

    private function isManager(): bool
    {
        return auth()->user()?->activeRoleIs('manager') ?? false;
    }
}
