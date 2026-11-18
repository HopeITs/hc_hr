<?php

namespace App\Support\Search\Providers;

use App\Models\MailList;
use App\Models\User;
use App\Support\Search\SearchProvider;
use App\Support\Search\SearchResult;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;

/**
 * The organisation's mailing lists by name or address, for every signed-in employee (the Mail
 * lists page is open to all). A result emails the whole list or copies its address.
 */
final class MailListsProvider implements SearchProvider
{
    public function allows(User $user): bool
    {
        return true;
    }

    public function search(User $user, string $term, int $limit): array
    {
        $words = SearchText::words($term);

        if ($words === []) {
            return [];
        }

        return MailList::query()
            ->where(function (Builder $lists) use ($words): void {
                foreach ($words as $word) {
                    $lists->where(fn (Builder $any) => $any
                        ->where('name', 'like', "%{$word}%")
                        ->orWhere('address', 'like', "%{$word}%"));
                }
            })
            ->orderBy('position')
            ->limit($limit)
            ->get()
            ->map(fn (MailList $list): array => SearchResult::make(
                'lists',
                $list->id,
                (string) $list->name,
                $list->address,
                route('mail-lists.index'),
                [
                    ['kind' => 'mail', 'label' => __('mail_lists.email_list'), 'href' => $list->mailto()],
                    ['kind' => 'copy', 'label' => __('mail_lists.copy_address'), 'text' => $list->address],
                ],
            ))
            ->all();
    }
}
