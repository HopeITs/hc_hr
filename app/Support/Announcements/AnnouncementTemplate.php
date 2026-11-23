<?php

namespace App\Support\Announcements;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A ready-made announcement email (welcome a newcomer, a volunteer's
 * resignation to IT, an administrative change, the holiday closure). Unlike a
 * MessageTemplate it is not about one person on file: HR types the few things
 * that change (a name, a position, dates) into fields, and the fixed Arabic
 * wording around them is kept here, with its grammar following the gender
 * chosen. Nothing is sent by the system: the composer opens the result as a new
 * message (or copies it) for HR to read first.
 */
abstract class AnnouncementTemplate
{
    public const MALE = 'm';

    public const FEMALE = 'f';

    private const WEEKDAYS = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

    /** The key used in the composer and in config('announcements.recipients'). */
    abstract public function key(): string;

    /**
     * The inputs, in the order shown. Each: name, type (text|textarea|date|
     * choice|toggle), required, default, options (for choice: value => label),
     * when (shown only while that toggle is on), dir (ltr for codes and Latin
     * names), hint (a lang key under messages.announcements.hints).
     *
     * @return list<array{name: string, type: string, required?: bool, default?: mixed, options?: array<string, string>, when?: string, dir?: string, hint?: string}>
     */
    abstract public function fields(): array;

    /**
     * The subject and the body for these values. A required value left empty
     * shows as «its label», so the preview says what is missing.
     *
     * @param  array<string, mixed>  $values
     * @return array{subject: string, body: string}
     */
    abstract public function render(array $values): array;

    /**
     * React to one field changing, before the next preview (the holiday's
     * return day follows its last day, say). The default does nothing.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function updated(string $field, array $values): array
    {
        return $values;
    }

    public function label(): string
    {
        return __("messages.announcements.templates.{$this->key()}.label");
    }

    public function description(): string
    {
        return __("messages.announcements.templates.{$this->key()}.description");
    }

    /** @return array{to: string, cc: string} */
    public function recipients(): array
    {
        $configured = config("announcements.recipients.{$this->key()}", []);

        return ['to' => (string) ($configured['to'] ?? ''), 'cc' => (string) ($configured['cc'] ?? '')];
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        $values = [];

        foreach ($this->fields() as $field) {
            $values[$field['name']] = $field['default'] ?? ($field['type'] === 'toggle' ? false : '');
        }

        return $values;
    }

    /**
     * The labels of the required fields still empty. A field hidden behind an
     * unticked toggle is not required.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function missing(array $values): array
    {
        $missing = [];

        foreach ($this->fields() as $field) {
            if (! ($field['required'] ?? false) || ! $this->isShown($field, $values)) {
                continue;
            }

            if (trim((string) ($values[$field['name']] ?? '')) === '') {
                $missing[] = $this->fieldLabel($field['name']);
            }
        }

        return $missing;
    }

    /**
     * @param  array{when?: string}  $field
     * @param  array<string, mixed>  $values
     */
    public function isShown(array $field, array $values): bool
    {
        return ! isset($field['when']) || (bool) ($values[$field['when']] ?? false);
    }

    public function fieldLabel(string $name): string
    {
        return __("messages.announcements.fields.{$this->key()}.{$name}");
    }

    /**
     * The value as typed, or «its label» when empty.
     *
     * @param  array<string, mixed>  $values
     */
    protected function value(array $values, string $name): string
    {
        $value = trim((string) ($values[$name] ?? ''));

        return $value !== '' ? $value : '«'.__("messages.announcements.fields.{$this->key()}.{$name}", [], 'ar').'»';
    }

    /** @param  array<string, mixed>  $values */
    protected function filled(array $values, string $name): bool
    {
        return trim((string) ($values[$name] ?? '')) !== '';
    }

    /** @param  array<string, mixed>  $values */
    protected function isFemale(array $values, string $name): bool
    {
        return ($values[$name] ?? self::MALE) === self::FEMALE;
    }

    /** A Y-m-d input as 01/01/2027, or null when it is not a date. */
    protected static function date(?string $iso): ?CarbonImmutable
    {
        if (! is_string($iso) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $iso);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The date written the way the office writes it, ««label»» when unset.
     *
     * @param  array<string, mixed>  $values
     */
    protected function dateText(array $values, string $name, bool $withWeekday = false): string
    {
        $date = self::date($values[$name] ?? null);

        if (! $date) {
            return $this->value([], $name);
        }

        $text = $date->format('d/m/Y');

        return $withWeekday ? self::weekday($date).' الموافق '.$text : $text;
    }

    protected static function weekday(CarbonImmutable $date): string
    {
        return self::WEEKDAYS[$date->dayOfWeek];
    }

    /** @return array<string, string> */
    protected static function genders(): array
    {
        return [
            self::MALE => __('messages.announcements.gender.male'),
            self::FEMALE => __('messages.announcements.gender.female'),
        ];
    }
}
