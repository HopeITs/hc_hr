<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Lang;

/**
 * The Commands group of the Ctrl+K box (prompt 10): start a task or change the
 * page from the keyboard, beside the pages and people it already finds.
 *
 *   - What the header's New button may start, from Navigation::newActions(),
 *     so a command exists exactly when the New menu item does. Each screen
 *     checks its permission again when it opens.
 *   - Toggle dark mode, switch language, customise the dashboard and the list
 *     of keyboard shortcuts: open to every signed-in person.
 *
 * A command matches its name and keywords in both languages whatever the
 * interface language, folded by SearchText: «اجازة» and «leave» find the same
 * one. What a command does in the browser is resources/js/commands.js.
 */
final class CommandPalette
{
    /** The commands every signed-in person has, after what they may create. */
    private const GENERAL = ['theme', 'locale', 'customise', 'shortcuts'];

    /**
     * The commands whose name or keywords, in either language, hold every word typed.
     *
     * @return list<array{id: string, label: string, hint: ?string, hint_lang: ?string, action: string, href: ?string, returns: bool}>
     */
    public static function search(User $user, string $term, ?string $otherLocaleUrl = null): array
    {
        return array_values(array_map(
            fn (array $command) => array_diff_key($command, ['terms' => true]),
            array_filter(self::for($user, $otherLocaleUrl), fn (array $command) => SearchText::matches($term, ...$command['terms'])),
        ));
    }

    /**
     * Every command the active role has, in the order shown. $otherLocaleUrl is
     * the current page in the other language; without it there is no
     * "Switch language" (the box is given it when it mounts with the page).
     *
     * @return list<array{id: string, label: string, hint: ?string, hint_lang: ?string, action: string, href: ?string, returns: bool, terms: list<string>}>
     */
    public static function for(User $user, ?string $otherLocaleUrl = null): array
    {
        $commands = [];

        foreach (Navigation::newActions($user) as $action) {
            // Cancel on the screen comes back to this page (?from=), as from the New menu.
            $commands[] = self::make($action['id'], 'open', route($action['route'], $action['params']), $action['returns'], fallback: $action['label']);
        }

        $other = app()->getLocale() === 'ar' ? 'en' : 'ar';

        foreach (self::GENERAL as $id) {
            $command = match ($id) {
                'theme' => self::make($id, 'theme'),
                'locale' => $otherLocaleUrl ? self::make($id, 'locale', $otherLocaleUrl) : null,
                // Prompt 4 opens the dashboard straight into its customise mode on ?customise=1.
                'customise' => self::make($id, 'open', route('dashboard', ['customise' => 1])),
                'shortcuts' => self::make($id, 'shortcuts'),
            };

            if ($command === null) {
                continue;
            }

            if ($id === 'locale') {
                // The language it switches to, named in that language.
                $command['hint'] = __("navigation.commands.locale.names.{$other}");
                $command['hint_lang'] = $other;
            } elseif ($id === 'shortcuts') {
                $command['hint'] = '?';
            }

            $commands[] = $command;
        }

        return $commands;
    }

    /** @return array{id: string, label: string, hint: ?string, hint_lang: ?string, action: string, href: ?string, returns: bool, terms: list<string>} */
    private static function make(string $id, string $action, ?string $href = null, bool $returns = false, ?string $fallback = null): array
    {
        $key = "navigation.commands.{$id}";
        // A New action added to the menu before its command copy still appears, under its menu name.
        $has = Lang::has("{$key}.label");
        $terms = [];

        foreach (['en', 'ar'] as $locale) {
            $terms[] = $has ? __("{$key}.label", [], $locale) : (string) $fallback;
            $terms[] = Lang::has("{$key}.keywords", $locale) ? __("{$key}.keywords", [], $locale) : '';
        }

        return [
            'id' => $id,
            'label' => $has ? __("{$key}.label") : (string) $fallback,
            'hint' => null,
            'hint_lang' => null,
            'action' => $action,
            'href' => $href,
            'returns' => $returns,
            'terms' => $terms,
        ];
    }
}
