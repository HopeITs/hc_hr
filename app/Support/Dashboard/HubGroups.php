<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Navigation;

/**
 * The dashboard's six groups, standing in for the long menu: each holds the
 * pages of the active role's menu that belong to it. They are read from
 * Navigation::for(), so a page the role cannot open is never here and every
 * page keeps its own server check. My space's home and folders are left out:
 * they stay in the icon rail.
 */
final class HubGroups
{
    private const LEAVES = ['nav.my-space.leaves', 'nav.my-space.team-calendar', 'nav.hr.leaves', 'nav.hr.balances', 'nav.my-team.leaves', 'nav.my-team.holidays'];

    private const REPORTS = ['nav.hr.statistics.general', 'nav.hr.statistics.contracts', 'nav.news'];

    private const RAIL = ['nav.my-space.home', 'nav.my-space.files', 'nav.my-space.shared-files'];

    /** In the order they are shown: id => icon (resources/views/components/icons). */
    private const GROUPS = [
        'people' => 'users',
        'leaves' => 'calendar',
        'recruitment' => 'clipboard-list',
        'structure' => 'code-fork',
        'reports' => 'statistics',
        'settings' => 'cog',
    ];

    /**
     * Groups with at least one page for this person.
     *
     * @return list<array{id: string, label: string, icon: string, href: string, page: string, pages: list<array{id: string, label: string, href: string}>}>
     */
    public static function for(User $user): array
    {
        $nav = Navigation::for($user, Navigation::isDeliverResponsible($user), countInbox: false);
        $pages = array_fill_keys(array_keys(self::GROUPS), []);

        foreach ($nav['areas'] as $area) {
            foreach ($area['items'] as $item) {
                foreach ($item['children'] ?? [$item] as $page) {
                    if (in_array($page['id'], self::RAIL, true)) {
                        continue;
                    }

                    $pages[self::groupOf($page['id'], $area['id'])][] = [
                        'id' => $page['id'],
                        'label' => $page['label'],
                        'href' => route($page['route']),
                    ];
                }
            }
        }

        // The group opens on its work, so the person's own items come after it.
        usort($pages['people'], fn (array $a, array $b): int => ($a['id'] === 'nav.my-space.items') <=> ($b['id'] === 'nav.my-space.items'));

        $groups = [];

        foreach (self::GROUPS as $id => $icon) {
            if ($pages[$id] !== []) {
                $groups[] = [
                    'id' => $id,
                    'label' => __('dashboard.hub.groups.'.$id),
                    'icon' => $icon,
                    'href' => $pages[$id][0]['href'],
                    'page' => $pages[$id][0]['label'],
                    'pages' => $pages[$id],
                ];
            }
        }

        return $groups;
    }

    private static function groupOf(string $pageId, string $areaId): string
    {
        return match (true) {
            in_array($pageId, self::LEAVES, true) => 'leaves',
            in_array($pageId, self::REPORTS, true) => 'reports',
            $areaId === 'recruitment' => 'recruitment',
            $areaId === 'organisation' => 'structure',
            $areaId === 'settings' => 'settings',
            default => 'people',
        };
    }
}
