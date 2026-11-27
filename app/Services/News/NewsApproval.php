<?php

namespace App\Services\News;

use App\Enums\News\NewsStatus;
use App\Models\City;
use App\Models\News;
use App\Models\User;
use App\Notifications\NewsApprovalDecided;
use App\Notifications\NewsApprovalRequested;
use App\Notifications\NewsPublishedByManager;
use App\Support\News\NewsAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * HR-040: a manager publishes to their own team; anything wider waits here
 * for HR. NewsForm::persist() and NewsTable::publishNow() decide pending vs
 * published (NewsAccess::needsApproval); this class tells HR, and approves or
 * rejects. The HR of the author's city is told, falling back to HR Syria.
 */
class NewsApproval
{
    /** A manager's item is waiting: ask the author's city HR to review it. */
    public function requested(News $news): void
    {
        $this->send($this->hrUsers($news), new NewsApprovalRequested($news));
    }

    /** A manager published to their team: tell the author's city HR, for information. */
    public function publishedToTeam(News $news): void
    {
        $this->send($this->hrUsers($news), new NewsPublishedByManager($news));
    }

    /** Publish a pending item, now or at the moment the author scheduled. */
    public function approve(User $reviewer, News $news): News
    {
        $news = DB::transaction(function () use ($reviewer, $news): News {
            $news = News::query()->lockForUpdate()->findOrFail($news->getKey());
            $this->authorize($reviewer, $news);

            $news->forceFill([
                'status' => NewsStatus::Published,
                'published_at' => $news->published_at?->isFuture() ? $news->published_at : now(),
                'archived_at' => null,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_note' => null,
                'updated_by' => $reviewer->getKey(),
            ])->save();

            return $news;
        });

        $this->send(collect([$news->creator])->filter(), new NewsApprovalDecided($news, approved: true));

        return $news;
    }

    /** Turn a pending item down; the author is told why and may edit and resend it. */
    public function reject(User $reviewer, News $news, string $reason): News
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reviewNote' => __('news.review.reason_required')]);
        }

        $news = DB::transaction(function () use ($reviewer, $news, $reason): News {
            $news = News::query()->lockForUpdate()->findOrFail($news->getKey());
            $this->authorize($reviewer, $news);

            $news->forceFill([
                'status' => NewsStatus::Rejected,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_note' => mb_substr($reason, 0, 2000),
                'updated_by' => $reviewer->getKey(),
            ])->save();

            return $news;
        });

        $this->send(collect([$news->creator])->filter(), new NewsApprovalDecided($news, approved: false));

        return $news;
    }

    /**
     * The HR of the author's city, else HR Syria.
     *
     * @return Collection<int, User>
     */
    public function hrUsers(News $news): Collection
    {
        $slug = $news->creator?->profile?->city?->slug;
        $users = $slug ? $this->usersWithRoles(City::hrRolesForSlug($slug)) : collect();

        if ($users->isEmpty()) {
            $users = $this->usersWithRoles(['HR-SYR']);
        }

        return $users->reject(fn (User $user) => (int) $user->getKey() === (int) $news->created_by)->values();
    }

    private function authorize(User $reviewer, News $news): void
    {
        if (! NewsAccess::canApprove($reviewer, $news)) {
            throw new AuthorizationException(__('news.review.not_pending'));
        }
    }

    /** @return Collection<int, User> */
    private function usersWithRoles(array $roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query): Builder => $query->whereIn('name', $roles))
            ->whereHas('profile', fn (Builder $query): Builder => $query->where('is_active', true))
            ->get();
    }

    /** A failed notification never undoes the publish or the review behind it. */
    private function send(Collection $users, object $notification): void
    {
        foreach ($users as $user) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
