<?php

namespace App\Support\Announcements;

/**
 * Asking IT to switch off the email of a volunteer who resigned. It goes to the
 * IT Specialist with the IT Manager on copy (config('announcements.recipients')),
 * and greets the specialist by the name typed, so a new holder of the post only
 * changes the fields.
 */
class VolunteerResignation extends AnnouncementTemplate
{
    public function key(): string
    {
        return 'volunteer_resignation';
    }

    public function fields(): array
    {
        return [
            ['name' => 'gender', 'type' => 'choice', 'options' => self::genders(), 'default' => self::FEMALE],
            ['name' => 'name', 'type' => 'text', 'required' => true],
            ['name' => 'volunteer_id', 'type' => 'text', 'required' => true, 'dir' => 'ltr'],
            ['name' => 'date', 'type' => 'date', 'required' => true, 'hint' => 'weekday'],
            ['name' => 'greeting_gender', 'type' => 'choice', 'options' => self::genders(), 'default' => (string) config('announcements.it_specialist.gender', self::FEMALE)],
            ['name' => 'greeting_name', 'type' => 'text', 'required' => true, 'default' => (string) config('announcements.it_specialist.greeting', '')],
        ];
    }

    public function render(array $values): array
    {
        $female = $this->isFemale($values, 'gender');
        [$colleague, $resignation, $their, $volunteer] = $female
            ? ['الزميلة', 'استقالتها', 'بها', 'المتطوعة']
            : ['الزميل', 'استقالته', 'به', 'المتطوع'];
        [$dear, $be] = $this->isFemale($values, 'greeting_gender') ? ['العزيزة', 'تكوني'] : ['العزيز', 'تكون'];

        $date = self::date($values['date'] ?? null);
        $day = $date ? self::weekday($date).' الواقع في '.$date->format('j/n/Y') : $this->value([], 'date');

        $body = $dear.' '.$this->value($values, 'greeting_name')."،\nأتمنى ان ".$be." بخير،،،\n\n"
            .'قدمت '.$colleague.' '.$this->value($values, 'name').' '.$resignation.' يوم '.$day.".\n\n"
            .'وبناء على ذلك أرجو منك إيقاف الإيميل الخاص '.$their.' علماً أن رقم الـID هو '.$this->value($values, 'volunteer_id').".\n\n"
            ."نهارك سعيد\nتحياتي";

        return [
            'subject' => 'إيقاف إيميل '.$volunteer.' '.$this->value($values, 'name').' – ID '.$this->value($values, 'volunteer_id'),
            'body' => $body,
        ];
    }
}
