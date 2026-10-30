<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Go-to keys (prompt 10): "g" then a letter opens a page, and "?" lists the
 * keys (<x-keyboard-shortcuts>, resources/js/commands.js).
 *
 * A letter is only offered while the active role's menu holds its page
 * (Navigation::shortcuts), so the keys never reach a page the menu would not
 * show; the page checks its own permission anyway. Each letter tries its menu
 * items in order and takes the first the role has: "g l" is All leaves for HR,
 * Team leaves for a manager, My leaves for everyone else.
 */
final class KeyboardShortcuts
{
    /**
     * Letter => menu item ids (Navigation), first match wins. None of these
     * depends on Navigation::isDeliverResponsible(), so goTo() skips that query.
     * Recruitment's items are those of the recruitment module's menu: until it
     * is part of this menu, "g r" is simply not offered.
     */
    public const GO_TO = [
        'd' => ['dashboard'],
        'e' => ['nav.hr.employees'],
        'l' => ['nav.hr.leaves', 'nav.my-team.leaves', 'nav.my-space.leaves'],
        'c' => ['nav.hr.contracts', 'nav.my-team.contracts'],
        'i' => ['nav.inbox'],
        'r' => ['nav.recruitment.requests', 'nav.recruitment.campaigns', 'nav.recruitment.applicants', 'nav.recruitment.approvals'],
    ];

    /**
     * The go-to keys this person has, with where each leads.
     *
     * @return array<string, array{id: string, label: string, href: string}>
     */
    public static function goTo(User $user): array
    {
        $allowed = Navigation::shortcuts($user);
        $keys = [];

        foreach (self::GO_TO as $letter => $ids) {
            foreach ($ids as $id) {
                // The dashboard is every signed-in person's home page; the menu lists it but shortcuts() leaves it out.
                $page = $id === 'dashboard'
                    ? ['route' => 'dashboard', 'label' => __('general.dashboard')]
                    : ($allowed[$id] ?? null);

                if ($page !== null && Route::has($page['route'])) {
                    $keys[$letter] = ['id' => $id, 'label' => $page['label'], 'href' => route($page['route'])];
                    break;
                }
            }
        }

        return $keys;
    }
}
