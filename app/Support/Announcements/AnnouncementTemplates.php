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

    /** The house font first, then what a computer without it falls back to. */
    private const FONT = "'Somar Sans', 'Segoe UI', Tahoma, sans-serif";

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
     * The whole message as an .eml file, for what is too long for a mailto: link.
     * Opened, Outlook shows it as a new message to send (`X-Unsent: 1` makes it a
     * draft, not a received mail), with To, CC, subject and the full text, laid out
     * right to left as Arabic is. UTF-8 throughout, so no letter is lost. Null
     * without a valid To.
     */
    public static function eml(string $to, string $cc, string $subject, string $body): ?string
    {
        $to = self::addresses($to);

        if ($to === []) {
            return null;
        }

        $cc = self::addresses($cc);
        // Outlook (Word's engine) reads the direction and the font from each element, not from the
        // page, so both are written on every one. Somar Sans shows where it is installed; the
        // fallbacks are for a computer without it.
        $rtl = 'direction:rtl;text-align:right;unicode-bidi:embed;font-family:'.self::FONT.';font-size:12pt';
        $paragraphs = array_map(
            fn (string $paragraph) => '<p dir="rtl" style="'.$rtl.';margin:0 0 12pt">'.nl2br(e($paragraph), false).'</p>',
            preg_split('/\n{2,}/', trim($body)) ?: [],
        );
        $html = '<html dir="rtl" lang="ar"><head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>'
            .'<body dir="rtl" style="'.$rtl.'"><div dir="rtl" style="'.$rtl.'">'.implode('', $paragraphs).'</div></body></html>';

        $lines = [
            'X-Unsent: 1',
            'To: '.implode(', ', $to),
            ...($cc ? ['Cc: '.implode(', ', $cc)] : []),
            'Subject: '.mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        return implode("\r\n", $lines)."\r\n\r\n".chunk_split(base64_encode($html), 76, "\r\n");
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
