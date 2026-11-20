<?php

namespace App\Support\Announcements;

/** Welcoming someone who joined, to the whole team. */
class WelcomeNewcomer extends AnnouncementTemplate
{
    public function key(): string
    {
        return 'welcome';
    }

    public function fields(): array
    {
        return [
            ['name' => 'gender', 'type' => 'choice', 'options' => self::genders(), 'default' => self::MALE],
            ['name' => 'name', 'type' => 'text', 'required' => true],
            ['name' => 'position', 'type' => 'text', 'required' => true],
            ['name' => 'center', 'type' => 'text', 'required' => true, 'default' => 'Christian Hope Center', 'dir' => 'auto'],
            ['name' => 'direction', 'type' => 'textarea', 'required' => true, 'hint' => 'direction'],
            ['name' => 'projects', 'type' => 'text', 'dir' => 'auto'],
            ['name' => 'expertise', 'type' => 'text', 'hint' => 'expertise'],
        ];
    }

    public function render(array $values): array
    {
        $female = $this->isFemale($values, 'gender');
        $title = $female ? 'السيدة' : 'السيد';
        [$him, $for, $tasks, $experience] = $female
            ? ['بها', 'لها', 'مهامها', 'خبرتها']
            : ['به', 'له', 'مهامه', 'خبرته'];

        $joined = 'انسجامًا مع توجهات المؤسسة في '.$this->value($values, 'direction')
            .'، يسرّنا إعلامكم بانضمام '.$title.' '.$this->value($values, 'name')
            .' إلى عائلة '.$this->value($values, 'center')
            .' بمنصب '.$this->value($values, 'position')
            .($this->filled($values, 'projects') ? '، والتي تشمل '.trim($values['projects']) : '')
            .'.';

        $wish = 'نرحّب '.$him.' بيننا، ونتمنى '.$for.' كل التوفيق في '.$tasks.' الجديدة. نحن على ثقة بأن '.$experience
            .($this->filled($values, 'expertise') ? ' الطويلة في مجال '.trim($values['expertise']) : '')
            .' ستشكّل إضافة نوعيّة تسهم في دعم '
            .($this->filled($values, 'projects') ? 'هذه المشاريع وتعزيز أثرها' : 'عمل المؤسسة وتعزيز أثره')
            .'.';

        $body = "الأعزاء،\nنهاركم سعيد،\n\n".$joined."\n\n".$wish."\n\nمع خالص التقدير والمحبة،";

        return [
            'subject' => 'ترحيب بانضمام '.$this->value($values, 'name').' إلى عائلة '.$this->value($values, 'center'),
            'body' => $body,
        ];
    }
}
