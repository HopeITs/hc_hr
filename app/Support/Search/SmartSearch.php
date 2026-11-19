<?php

namespace App\Support\Search;

use App\Models\User;
use App\Support\Search\Providers\ApplicantsProvider;
use App\Support\Search\Providers\ContractsProvider;
use App\Support\Search\Providers\FoldersProvider;
use App\Support\Search\Providers\LeaveRequestsProvider;
use App\Support\Search\Providers\MailListsProvider;
use App\Support\Search\Providers\NewsProvider;
use App\Support\Search\Providers\PositionsProvider;
use App\Support\Search\Providers\RecruitmentRequestsProvider;
use Throwable;

/**
 * Runs the kinds of result the header search finds beyond pages, people and commands. Each kind
 * is a SearchProvider that asks for its rows through the scope of the page that lists them, so
 * nothing here widens what a role can see. One failing provider is reported and skipped: the box
 * keeps the results of the others.
 */
final class SmartSearch
{
    /** Type key => provider, in the order the groups appear. */
    public const PROVIDERS = [
        'positions' => PositionsProvider::class,
        'contracts' => ContractsProvider::class,
        'leave' => LeaveRequestsProvider::class,
        'requests' => RecruitmentRequestsProvider::class,
        'applicants' => ApplicantsProvider::class,
        'lists' => MailListsProvider::class,
        'folders' => FoldersProvider::class,
        'news' => NewsProvider::class,
    ];

    /**
     * The kinds this user's active role may search.
     *
     * @return list<string>
     */
    public static function types(User $user): array
    {
        return array_values(array_filter(
            array_keys(self::PROVIDERS),
            fn (string $type) => self::provider($type)->allows($user),
        ));
    }

    /**
     * @param  string  $only  'all', or one type key
     * @return array{groups: array<string, list<array<string, mixed>>>, failed: bool}
     */
    public static function run(User $user, string $term, string $only = 'all', int $limit = 4): array
    {
        $groups = [];
        $failed = false;

        foreach (self::PROVIDERS as $type => $class) {
            if ($only !== 'all' && $only !== $type) {
                continue;
            }

            $provider = self::provider($type);

            try {
                if (! $provider->allows($user)) {
                    continue;
                }

                $results = $provider->search($user, $term, $limit);
            } catch (Throwable $e) {
                report($e);
                $failed = true;

                continue;
            }

            if ($results !== []) {
                $groups[$type] = $results;
            }
        }

        return ['groups' => $groups, 'failed' => $failed];
    }

    private static function provider(string $type): SearchProvider
    {
        return app(self::PROVIDERS[$type]);
    }
}
