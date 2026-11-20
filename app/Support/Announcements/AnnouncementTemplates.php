<?php

namespace App\Support\Announcements;

/** The ready-made announcements, in the order the composer lists them. */
final class AnnouncementTemplates
{
    /**
     * Longest mailto: link mail programs reliably open (Windows refuses a link past
     * about 2000 characters without a word, and Arabic costs ~6 characters a letter).
     */
    public const MAILTO_LIMIT = 1800;

    /** @var list<class-string<AnnouncementTemplate>> */
    private const TEMPLATES = [
        WelcomeNewcomer::class,
        VolunteerResignation::class,
        AdministrativeChange::class,
        HolidayClosure::class,
    ];

    /** @return array<string, AnnouncementTemplate> */
    public static function all(): array
    {
        $templates = [];

        foreach (self::TEMPLATES as $class) {
            $template = new $class;
            $templates[$template->key()] = $template;
        }

        return $templates;
    }

    public static function find(?string $key): ?AnnouncementTemplate
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /**
     * The mailto: link for a finished message, or null without a valid To.
     * Several addresses may be given, separated by commas or semicolons.
     * Too long to open with its text, the link carries To, CC and subject only
     * and `trimmed` is true: the composer copies the text for the user to paste.
     *
     * @return array{url: string, trimmed: bool}|null
     */
    public static function mailto(string $to, string $cc, string $subject, string $body): ?array
    {
        $to = self::addresses($to);

        if ($to === []) {
            return null;
        }

        $head = [];
        $cc = self::addresses($cc);

        if ($cc !== []) {
            $head[] = 'cc='.implode(',', $cc);
        }

        $head[] = 'subject='.rawurlencode($subject);
        $url = 'mailto:'.implode(',', $to).'?'.implode('&', [...$head, 'body='.rawurlencode(str_replace("\n", "\r\n", $body))]);

        if (strlen($url) <= self::MAILTO_LIMIT) {
            return ['url' => $url, 'trimmed' => false];
        }

        return ['url' => 'mailto:'.implode(',', $to).'?'.implode('&', $head), 'trimmed' => true];
    }

    /**
     * The valid addresses in a list typed by hand.
     *
     * @return list<string>
     */
    public static function addresses(string $list): array
    {
        $addresses = preg_split('/[,;\s]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($addresses, fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false));
    }

    /** @return list<string> the parts of a typed list that are not addresses */
    public static function invalidAddresses(string $list): array
    {
        $parts = preg_split('/[,;\s]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) === false));
    }
}
