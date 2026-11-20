<?php

namespace App\Support\Announcements;

/** The office closing for the Christmas and New Year holidays, to the whole team. */
class HolidayClosure extends AnnouncementTemplate
{
    public function key(): string
    {
        return 'holiday_closure';
    }

    public function fields(): array
    {
        return [
            ['name' => 'start', 'type' => 'date', 'required' => true],
            ['name' => 'end', 'type' => 'date', 'required' => true],
            ['name' => 'resume', 'type' => 'date', 'required' => true, 'hint' => 'resume'],
            ['name' => 'office', 'type' => 'text', 'required' => true, 'default' => 'مكتب الإدارة العامة'],
        ];
    }

    /** Moving the last day of the holiday moves the return to the day after it. */
    public function updated(string $field, array $values): array
    {
        if ($field === 'end' && ($end = self::date($values['end'] ?? null))) {
            $values['resume'] = $end->addDay()->format('Y-m-d');
        }

        return $values;
    }

    /**
     * Dates that do not hold together, worded for the composer.
     *
     * @return list<string>
     */
    public function problems(array $values): array
    {
        $start = self::date($values['start'] ?? null);
        $end = self::date($values['end'] ?? null);
        $resume = self::date($values['resume'] ?? null);
        $problems = [];

        if ($start && $end && $end->lt($start)) {
            $problems[] = __('messages.announcements.problems.end-before-start');
        }

        if ($end && $resume && $resume->lte($end)) {
            $problems[] = __('messages.announcements.problems.resume-not-after-end');
        }

        return $problems;
    }

    public function render(array $values): array
    {
        $body = "الزملاء الأعزاء،\nأرجو أن تكونوا بخير وأن تحمل لكم هذه الأيام الكثير من السلام والفرح،\n\n"
            .'يسرّني إعلامكم بأن '.$this->value($values, 'office').' سيُعطل بمناسبة الأعياد ابتداءً من '.$this->dateText($values, 'start')
            .' ولغاية '.$this->dateText($values, 'end').'، على أن نستأنف الدوام يوم '.$this->dateText($values, 'resume', withWeekday: true).".\n\n"
            .'باسم الإدارة وقسم الموارد البشرية، نود أن نعبر عن امتناننا العميق لكم على ما بذلتموه من جهد وعطاء خلال العام، فقد كان لعملكم أثر كبير في تعزيز رسالة Hope Center، ونتطلع معاً إلى عام جديد يحمل لنا جميعاً المزيد من الخير والنجاح والتميّز.'
            ."\n\nمع أطيب الأمنيات لكم ولعائلاتكم بميلاد مجيد وسنة جديدة مباركة";

        $start = self::date($values['start'] ?? null);
        $end = self::date($values['end'] ?? null);

        return [
            'subject' => 'عطلة الأعياد'.($start && $end ? ' من '.$start->format('d/m/Y').' لغاية '.$end->format('d/m/Y') : ''),
            'body' => $body,
        ];
    }
}
