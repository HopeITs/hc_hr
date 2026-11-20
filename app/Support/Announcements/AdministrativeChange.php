<?php

namespace App\Support\Announcements;

/**
 * Someone ends their service in a post, and (when there is one) who takes the
 * post over and from when. The replacement takes the same position and project.
 */
class AdministrativeChange extends AnnouncementTemplate
{
    public function key(): string
    {
        return 'administrative_change';
    }

    public function fields(): array
    {
        $titles = self::titles();

        return [
            ['name' => 'audience', 'type' => 'text', 'required' => true, 'default' => 'متطوعي مركز الرجاء المسيحي', 'hint' => 'audience'],
            ['name' => 'title', 'type' => 'choice', 'options' => $titles, 'default' => 'السيد'],
            ['name' => 'name', 'type' => 'text', 'required' => true],
            ['name' => 'position', 'type' => 'text', 'required' => true],
            ['name' => 'project', 'type' => 'text', 'dir' => 'auto', 'hint' => 'project'],
            ['name' => 'date', 'type' => 'date', 'required' => true],
            ['name' => 'has_replacement', 'type' => 'toggle', 'default' => true],
            ['name' => 'replacement_title', 'type' => 'choice', 'options' => $titles, 'default' => 'السيد', 'when' => 'has_replacement'],
            ['name' => 'replacement_name', 'type' => 'text', 'required' => true, 'when' => 'has_replacement'],
            ['name' => 'replacement_date', 'type' => 'date', 'required' => true, 'when' => 'has_replacement'],
        ];
    }

    /** The replacement usually starts the day the post is left: follow it until set apart. */
    public function updated(string $field, array $values): array
    {
        if ($field === 'date' && trim((string) ($values['replacement_date'] ?? '')) === '') {
            $values['replacement_date'] = $values['date'];
        }

        return $values;
    }

    public function render(array $values): array
    {
        $title = $this->titleOf($values, 'title');
        $female = $title !== 'السيد';
        $name = trim((string) ($values['name'] ?? ''));
        // The thanks names the person by first name, as the office writes it.
        $firstName = $name !== '' ? preg_split('/\s+/u', $name)[0] : $this->value($values, 'name');

        $role = $this->value($values, 'position')
            .($this->filled($values, 'project') ? ' '.trim($values['project']) : '');

        $body = 'الأعزاء '.trim((string) ($values['audience'] ?? ''))."،\nتحية طيبة وبعد،\n\n"
            .'نود إعلامكم بأن '.$title.' '.$this->value($values, 'name').' '.($female ? 'ستنهي تطوعها' : 'سينهي تطوعه')
            .' في المؤسسة بمنصب '.$role.'، وسيكون ذلك نافذاً اعتباراً من '.$this->dateText($values, 'date').".\n\n"
            // لِ + السيد is written للسيد: the alef of the article drops.
            .'باسم الإدارة نتوجه بالشكر الجزيل ل'.mb_substr($title, 1).' '.$firstName
            .($female
                ? ' على الجهود التي بذلتها، وما قدّمته خلال فترة تطوعها معنا من عطاء وإخلاص، ونتمنى لها كل التوفيق والنجاح في مسيرتها القادمة.'
                : ' على الجهود التي بذلها، وما قدّمه خلال فترة تطوعه معنا من عطاء وإخلاص، ونتمنى له كل التوفيق والنجاح في مسيرته القادمة.');

        if ((bool) ($values['has_replacement'] ?? false)) {
            $replacementTitle = $this->titleOf($values, 'replacement_title');
            $replacementFemale = $replacementTitle !== 'السيد';

            $body .= "\n\n".'كما يسعدنا إعلامكم بتكليف '.$replacementTitle.' '.$this->value($values, 'replacement_name')
                .' بمهام '.$role.' اعتباراً من '.$this->dateText($values, 'replacement_date')
                .($replacementFemale ? '، ونتمنى لها التوفيق والنجاح في مسؤولياتها.' : '، ونتمنى له التوفيق والنجاح في مسؤولياته.');
        }

        $body .= "\n\nمع خالص التقدير،";

        $subjectRole = trim(trim((string) ($values['position'] ?? '')).' '.trim((string) ($values['project'] ?? '')));

        return [
            'subject' => 'تغييرات إدارية'.($subjectRole !== '' ? ' – '.$subjectRole : ''),
            'body' => $body,
        ];
    }

    /** @return array<string, string> */
    private static function titles(): array
    {
        return ['السيد' => 'السيد', 'السيدة' => 'السيدة', 'الآنسة' => 'الآنسة'];
    }

    /** @param  array<string, mixed>  $values */
    private function titleOf(array $values, string $name): string
    {
        $title = (string) ($values[$name] ?? '');

        return array_key_exists($title, self::titles()) ? $title : 'السيد';
    }
}
