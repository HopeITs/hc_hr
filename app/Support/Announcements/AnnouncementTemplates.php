<?php

namespace App\Support\Announcements;

/** The ready-made announcements, in the order the composer lists them. */
final class AnnouncementTemplates
{
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
     */
    public static function mailto(string $to, string $cc, string $subject, string $body): ?string
    {
        $to = self::addresses($to);

        if ($to === []) {
            return null;
        }

        $query = [];
        $cc = self::addresses($cc);

        if ($cc !== []) {
            $query[] = 'cc='.implode(',', array_map('rawurlencode', $cc));
        }

        $query[] = 'subject='.rawurlencode($subject);
        $query[] = 'body='.rawurlencode(str_replace("\n", "\r\n", $body));

        return 'mailto:'.implode(',', array_map('rawurlencode', $to)).'?'.implode('&', $query);
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
