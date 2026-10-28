<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Page-context help (D3): which help topics exist, and which of them belong
 * to the page being rendered.
 *
 * The copy lives in resources/lang/{en,ar}/help.php under help.topics.<key>.
 * Everything the panel can show is resolved here, during the full page render,
 * and handed to <x-help.panel> as plain data, so opening help, searching and
 * moving between topics never asks the server anything: an open form keeps
 * its state because nothing reaches its Livewire component.
 *
 * Like ProductTour, the page is read while the full page renders, never inside
 * a Livewire update (whose route is `livewire.update`).
 *
 * `permission` only decides whether a topic is relevant to the active role
 * (an employee has no use for contract help). It grants nothing and hides
 * nothing sensitive: help text holds no figures and no personal data.
 */
class HelpTopics
{
    /**
     * Topic key => options. `points` is the number of bullet lines in its copy.
     *
     * @var array<string, array{points: int, permission?: string}>
     */
    public const TOPICS = [
        'leave-units' => ['points' => 6],
        'leave-balance' => ['points' => 5],
        'contract-types' => ['points' => 5, 'permission' => 'contracts.view'],
        'auto-renew' => ['points' => 5, 'permission' => 'contracts.view'],
        'position-dependencies' => ['points' => 5, 'permission' => 'contracts.view'],
    ];

    /**
     * Route name (request()->routeIs() patterns allowed) => topics of that page,
     * in the order the panel lists them under "On this page".
     *
     * @var array<string, list<string>>
     */
    public const ROUTES = [
        'dashboard' => ['leave-balance', 'leave-units'],
        'leaves.index' => ['leave-units', 'leave-balance'],
        'leaves.all' => ['leave-units', 'leave-balance'],
        'leaves.balance' => ['leave-balance', 'leave-units'],
        'contracts.create' => ['contract-types', 'position-dependencies', 'auto-renew'],
        'contracts.renew' => ['contract-types', 'position-dependencies', 'auto-renew'],
        'contracts.edit' => ['contract-types', 'auto-renew', 'position-dependencies'],
        'contracts.show' => ['contract-types', 'auto-renew'],
        'contracts.index' => ['contract-types', 'auto-renew', 'position-dependencies'],
    ];

    /**
     * Topic keys of the page $routeName, filtered to the ones $user may use.
     *
     * @return list<string>
     */
    public static function keysForRoute(?string $routeName, ?User $user = null): array
    {
        $keys = $routeName !== null ? (self::ROUTES[$routeName] ?? []) : [];

        return array_values(array_filter($keys, fn (string $key) => self::relevantTo($key, $user)));
    }

    /**
     * Everything the panel needs, in the current locale.
     *
     * @return array{current: list<string>, topics: list<array{key: string, title: string, summary: string, points: list<string>, keywords: string}>}
     */
    public static function forRequest(Request $request, ?User $user = null): array
    {
        $topics = [];

        foreach (array_keys(self::TOPICS) as $key) {
            if (self::relevantTo($key, $user)) {
                $topics[] = self::topic($key);
            }
        }

        return [
            'current' => self::keysForRoute($request->route()?->getName(), $user),
            'topics' => $topics,
        ];
    }

    /**
     * One topic's copy in the current locale.
     *
     * @return array{key: string, title: string, summary: string, points: list<string>, keywords: string}
     */
    public static function topic(string $key): array
    {
        $points = [];

        for ($i = 1; $i <= self::TOPICS[$key]['points']; $i++) {
            $points[] = __("help.topics.{$key}.points.{$i}");
        }

        return [
            'key' => $key,
            'title' => __("help.topics.{$key}.title"),
            'summary' => __("help.topics.{$key}.summary"),
            'points' => $points,
            'keywords' => __("help.topics.{$key}.keywords"),
        ];
    }

    /**
     * Every translation key a topic's copy uses, for the EN/AR parity test.
     *
     * @return list<string>
     */
    public static function translationKeys(string $key): array
    {
        $keys = ["help.topics.{$key}.title", "help.topics.{$key}.summary", "help.topics.{$key}.keywords"];

        for ($i = 1; $i <= self::TOPICS[$key]['points']; $i++) {
            $keys[] = "help.topics.{$key}.points.{$i}";
        }

        return $keys;
    }

    private static function relevantTo(string $key, ?User $user): bool
    {
        $permission = self::TOPICS[$key]['permission'] ?? null;

        return $permission === null || ($user?->hasActivePermission($permission) ?? false);
    }
}
