<?php

namespace App\Livewire\Feedback;

use App\Models\InAppFeedback;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The IT team's reading list of in-app feedback (D3, DEC-08), newest first.
 *
 * The route carries `can:feedback.read`; boot() repeats the check on every
 * request, Livewire updates included, so a role switched in another tab or a
 * replayed snapshot is refused as well. Read-only: rows leave only by expiry.
 */
class FeedbackIndex extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $category = '';

    public function boot(): void
    {
        abort_unless(Gate::allows('feedback.read'), 403);
    }

    public function updatedCategory(): void
    {
        if (! in_array($this->category, config('feedback.categories', []), true)) {
            $this->category = '';
        }

        $this->resetPage();
    }

    public function render()
    {
        $category = in_array($this->category, config('feedback.categories', []), true) ? $this->category : null;

        return view('livewire.feedback.feedback-index', [
            'items' => InAppFeedback::query()
                ->with('user.profile')
                ->when($category, fn ($query) => $query->where('category', $category))
                ->latest('created_at')
                ->latest('id')
                ->paginate(25),
        ]);
    }
}
