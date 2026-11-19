<?php

namespace App\Support\Search\Providers;

use App\Enums\RecruitmentRequestType;
use App\Models\Position;
use App\Models\User;
use App\Support\Search\SearchProvider;
use App\Support\Search\SearchResult;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;

/**
 * Positions by title, in either language. They are what the org chart and the directory show
 * (Position::visible()), so every role that may open the org chart finds them. A free seat
 * carries "Open vacancy" for whoever may raise a hiring request, and "Edit" for who may edit.
 */
final class PositionsProvider implements SearchProvider
{
    public function allows(User $user): bool
    {
        return $user->hasActivePermission('positions.view');
    }

    public function search(User $user, string $term, int $limit): array
    {
        $words = SearchText::words($term);

        if ($words === []) {
            return [];
        }

        $canEdit = $user->hasActivePermission('positions.update');
        $canHire = $user->hasActivePermission('recruitment-requests.manage');

        return Position::query()
            ->visible()
            ->with(['center:id,name_en,name_ar', 'city:id,name_en,name_ar'])
            ->where(function (Builder $titles) use ($words): void {
                foreach ($words as $word) {
                    $titles->where(fn (Builder $any) => $any
                        ->where('title_en', 'like', "%{$word}%")
                        ->orWhereRaw(SearchText::foldedColumn('title_ar').' like ?', ["%{$word}%"]));
                }
            })
            ->orderBy('title_en')
            ->limit($limit)
            ->get()
            ->map(function (Position $position) use ($canEdit, $canHire): array {
                $free = max(0, (int) $position->max_emp - (int) $position->exist_emp);
                $actions = [];

                if ($free > 0 && $canHire) {
                    $actions[] = ['kind' => 'link', 'label' => __('smart_search.actions.open-vacancy'), 'href' => route('recruitment.requests.create', ['type' => RecruitmentRequestType::FillVacancy, 'position_id' => $position->id])];
                }

                if ($canEdit) {
                    $actions[] = ['kind' => 'link', 'label' => __('general.edit'), 'href' => route('positions.edit', $position->id)];
                }

                return SearchResult::make(
                    'positions',
                    $position->id,
                    (string) $position->title,
                    SearchResult::line(
                        $position->center?->name,
                        $position->city?->name,
                        $free > 0 ? trans_choice('smart_search.free-seats', $free, ['count' => $free]) : __('smart_search.full'),
                    ),
                    $canEdit ? route('positions.edit', $position->id) : route('org.chart'),
                    $actions,
                );
            })
            ->all();
    }
}
