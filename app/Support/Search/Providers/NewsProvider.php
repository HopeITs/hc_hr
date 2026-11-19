<?php

namespace App\Support\Search\Providers;

use App\Models\News;
use App\Models\User;
use App\Support\Search\SearchProvider;
use App\Support\Search\SearchResult;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;

/**
 * News by title: what is published now and meant for this person (News::live + visibleTo, the
 * feed's own rules). Drafts, archived and out-of-audience items are never offered.
 */
final class NewsProvider implements SearchProvider
{
    public function allows(User $user): bool
    {
        return $user->hasActivePermission('news.view');
    }

    public function search(User $user, string $term, int $limit): array
    {
        $words = SearchText::words($term);

        if ($words === []) {
            return [];
        }

        return News::query()
            ->live()
            ->visibleTo($user)
            ->where(function (Builder $titles) use ($words): void {
                foreach ($words as $word) {
                    $titles->where(fn (Builder $any) => $any
                        ->where('news.title_en', 'like', "%{$word}%")
                        ->orWhereRaw(SearchText::foldedColumn('news.title_ar').' like ?', ["%{$word}%"]));
                }
            })
            ->orderByDesc('news.published_at')
            ->limit($limit)
            ->get()
            ->map(function (News $news): array {
                $title = app()->getLocale() === 'ar'
                    ? ($news->title_ar ?: $news->title_en)
                    : ($news->title_en ?: $news->title_ar);

                return SearchResult::make('news', $news->id, (string) $title, (string) $news->published_at?->format('d/m/Y'), route('news.show', $news));
            })
            ->all();
    }
}
