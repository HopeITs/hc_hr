<?php

namespace App\Support\Search;

/**
 * One line of the search box: what it is, where it opens, and the quick actions beside it.
 *
 * An action is ['kind' => 'link'|'mail'|'external'|'copy'|'templates', 'label' => string, …]:
 *  - link / mail / external: 'href' (mailto: and wa.me open in a new message or chat);
 *  - copy: 'text' goes to the clipboard;
 *  - templates: 'id' (an employee), the list of message templates is fetched when it is clicked.
 */
final class SearchResult
{
    /**
     * @param  list<array<string, mixed>>  $actions
     * @return array{type: string, id: int|string, title: string, subtitle: string, href: string, actions: list<array<string, mixed>>}
     */
    public static function make(string $type, int|string $id, string $title, string $subtitle, string $href, array $actions = []): array
    {
        return compact('type', 'id', 'title', 'subtitle', 'href', 'actions');
    }

    /** "a · b · c" without the empty parts. */
    public static function line(?string ...$parts): string
    {
        return implode(' · ', array_filter($parts, fn (?string $part) => filled($part)));
    }
}
