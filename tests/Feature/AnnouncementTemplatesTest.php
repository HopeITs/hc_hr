<?php

namespace Tests\Feature;

use App\Livewire\Messages\AnnouncementComposer;
use App\Support\Announcements\AdministrativeChange;
use App\Support\Announcements\AnnouncementTemplates;
use App\Support\Announcements\HolidayClosure;
use App\Support\Announcements\VolunteerResignation;
use App\Support\Announcements\WelcomeNewcomer;
use App\Support\CommandPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesHrFixtures;
use Tests\TestCase;

/**
 * The ready-made announcements: each one reads exactly as the office writes it
 * from the fields, its grammar follows the gender chosen, and only someone who
 * may use message templates opens the composer.
 */
class AnnouncementTemplatesTest extends TestCase
{
    use CreatesHrFixtures, RefreshDatabase;

    public function test_the_welcome_reads_as_the_office_writes_it(): void
    {
        $message = (new WelcomeNewcomer)->render([
            'gender' => 'm',
            'name' => 'جان مغامز',
            'position' => 'مستشار (Consultant) لمشاريع التمكين في المؤسسة',
            'center' => 'Christian Hope Center',
            'direction' => 'تعزيز قيمة التمكين وتطوير برامجها',
            'projects' => 'الـ DISC, LEAP, Children University',
            'expertise' => 'التمكين',
            'signature' => '',
        ]);

        $this->assertSame(
            "الأعزاء،\nنهاركم سعيد،\n\n"
            .'انسجامًا مع توجهات المؤسسة في تعزيز قيمة التمكين وتطوير برامجها، يسرّنا إعلامكم بانضمام السيد جان مغامز إلى عائلة Christian Hope Center بمنصب مستشار (Consultant) لمشاريع التمكين في المؤسسة، والتي تشمل الـ DISC, LEAP, Children University.'
            ."\n\n".'نرحّب به بيننا، ونتمنى له كل التوفيق في مهامه الجديدة. نحن على ثقة بأن خبرته الطويلة في مجال التمكين ستشكّل إضافة نوعيّة تسهم في دعم هذه المشاريع وتعزيز أثرها.'
            ."\n\nمع خالص التقدير والمحبة،",
            $message['body'],
        );
        $this->assertSame('ترحيب بانضمام جان مغامز إلى عائلة Christian Hope Center', $message['subject']);
    }

    public function test_the_welcome_follows_a_woman_and_drops_the_optional_parts(): void
    {
        $body = (new WelcomeNewcomer)->render([
            'gender' => 'f', 'name' => 'رين', 'position' => 'منسقة', 'center' => 'Christian Hope Center', 'direction' => 'التمكين',
        ])['body'];

        $this->assertStringContainsString('بانضمام السيدة رين', $body);
        $this->assertStringContainsString('نرحّب بها بيننا، ونتمنى لها كل التوفيق في مهامها الجديدة', $body);
        $this->assertStringContainsString('بأن خبرتها ستشكّل', $body);
        $this->assertStringContainsString('دعم عمل المؤسسة وتعزيز أثره.', $body);
        $this->assertStringNotContainsString('والتي تشمل', $body);
    }

    public function test_a_missing_field_shows_its_name_and_is_listed(): void
    {
        $template = new WelcomeNewcomer;
        $values = $template->defaults();

        $this->assertStringContainsString('«الاسم»', $template->render($values)['body']);
        $this->assertContains($template->fieldLabel('name'), $template->missing($values));
    }

    public function test_the_resignation_request_writes_the_weekday_and_greets_the_specialist(): void
    {
        $message = (new VolunteerResignation)->render([
            'gender' => 'f', 'name' => 'راوية العنيني', 'volunteer_id' => '483', 'date' => '2026-06-30',
            'greeting_gender' => 'f', 'greeting_name' => 'مي',
        ]);

        $this->assertSame(
            "العزيزة مي،\nأتمنى ان تكوني بخير،،،\n\n"
            ."قدمت الزميلة راوية العنيني استقالتها يوم الثلاثاء الواقع في 30/6/2026.\n\n"
            ."وبناء على ذلك أرجو منك إيقاف الإيميل الخاص بها علماً أن رقم الـID هو 483.\n\n"
            ."نهارك سعيد\nتحياتي",
            $message['body'],
        );
        $this->assertSame(['to' => 'mai.youssef@hcsyria.org', 'cc' => 'judy.dadaghlian@hcsyria.org'], (new VolunteerResignation)->recipients());
    }

    public function test_the_administrative_change_thanks_by_first_name_and_names_the_replacement(): void
    {
        $body = (new AdministrativeChange)->render([
            'audience' => 'متطوعي مركز الرجاء المسيحي', 'title' => 'السيد', 'name' => 'رامي تارك',
            'position' => 'مدير مشروع', 'project' => 'ADISC', 'date' => '2027-01-01',
            'has_replacement' => true, 'replacement_title' => 'الآنسة', 'replacement_name' => 'رين شنكجي', 'replacement_date' => '2027-01-01',
        ])['body'];

        $this->assertStringContainsString('نود إعلامكم بأن السيد رامي تارك سينهي تطوعه في المؤسسة بمنصب مدير مشروع ADISC، وسيكون ذلك نافذاً اعتباراً من 01/01/2027.', $body);
        $this->assertStringContainsString('بالشكر الجزيل للسيد رامي على الجهود التي بذلها', $body);
        $this->assertStringContainsString('بتكليف الآنسة رين شنكجي بمهام مدير مشروع ADISC اعتباراً من 01/01/2027، ونتمنى لها التوفيق والنجاح في مسؤولياتها.', $body);

        $alone = (new AdministrativeChange)->render(['title' => 'السيدة', 'name' => 'هبة', 'position' => 'منسقة', 'date' => '2027-01-01', 'has_replacement' => false])['body'];
        $this->assertStringContainsString('ستنهي تطوعها', $alone);
        $this->assertStringNotContainsString('بتكليف', $alone);
    }

    public function test_the_holiday_return_follows_its_last_day_with_the_weekday(): void
    {
        $template = new HolidayClosure;
        $values = $template->updated('end', ['start' => '2026-12-23', 'end' => '2027-01-11', 'resume' => '', 'office' => 'مكتب الإدارة العامة']);

        $this->assertSame('2027-01-12', $values['resume']);
        $this->assertStringContainsString('ابتداءً من 23/12/2026 ولغاية 11/01/2027، على أن نستأنف الدوام يوم الثلاثاء الموافق 12/01/2027.', $template->render($values)['body']);
        $this->assertSame([], $template->problems($values));
        $this->assertCount(1, $template->problems([...$values, 'resume' => '2027-01-10']));
    }

    public function test_the_mailto_carries_every_recipient_and_skips_what_is_not_an_address(): void
    {
        $mail = AnnouncementTemplates::mailto('team@hcsyria.org', 'fredy.youssef@hcsyria.org; nonsense', 'عطلة', "سطر\nسطر");

        $this->assertFalse($mail['trimmed']);
        $this->assertStringStartsWith('mailto:team@hcsyria.org?cc=fredy.youssef@hcsyria.org&subject=', $mail['url']);
        $this->assertStringContainsString('body='.rawurlencode("سطر\r\nسطر"), $mail['url']);
        $this->assertNull(AnnouncementTemplates::mailto('', '', 's', 'b'));
    }

    public function test_a_link_too_long_for_a_mail_program_drops_its_text_and_says_so(): void
    {
        $holiday = new HolidayClosure;
        $values = [...$holiday->defaults(), 'start' => '2026-12-23', 'end' => '2027-01-11', 'resume' => '2027-01-12'];
        $message = $holiday->render($values);

        $mail = AnnouncementTemplates::mailto('team@hcsyria.org', 'fredy.youssef@hcsyria.org', $message['subject'], $message['body']);

        $this->assertTrue($mail['trimmed']);
        $this->assertLessThanOrEqual(AnnouncementTemplates::MAILTO_LIMIT, strlen($mail['url']));
        $this->assertStringNotContainsString('body=', $mail['url']);
        $this->assertStringContainsString('subject=', $mail['url']);

        $web = AnnouncementTemplates::webLinks('team@hcsyria.org', 'fredy.youssef@hcsyria.org', $message['subject'], $message['body']);
        $this->assertStringStartsWith('https://mail.google.com/mail/?view=cm', $web['gmail']);
        $this->assertStringContainsString('body='.rawurlencode(str_replace("\n", "\r\n", $message['body'])), $web['outlook']);
    }

    public function test_the_eml_opens_as_a_new_message_with_the_whole_text(): void
    {
        $holiday = new HolidayClosure;
        $message = $holiday->render([...$holiday->defaults(), 'start' => '2026-12-23', 'end' => '2027-01-11', 'resume' => '2027-01-12']);

        $eml = AnnouncementTemplates::eml('team@hcsyria.org', 'fredy.youssef@hcsyria.org', $message['subject'], $message['body']);

        $this->assertStringStartsWith("X-Unsent: 1\r\nTo: team@hcsyria.org\r\nCc: fredy.youssef@hcsyria.org\r\nSubject: =?UTF-8?B?", $eml);
        $this->assertStringContainsString('charset=UTF-8', $eml);
        [$head, $encoded] = explode("\r\n\r\n", $eml, 2);
        $this->assertSame(str_replace("\n", "\r\n", $message['body']), base64_decode($encoded));
        $this->assertStringContainsString('Subject: '.$message['subject'], mb_decode_mimeheader($head));
        $this->assertNull(AnnouncementTemplates::eml('', '', 's', 'b'));
    }

    public function test_the_composer_downloads_the_eml_for_who_may_use_it(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['message-templates.use']);

        Livewire::actingAs($hr)->test(AnnouncementComposer::class)
            ->call('compose', 'holiday_closure')
            ->call('downloadEml')
            ->assertFileDownloaded('holiday_closure.eml');
    }

    public function test_only_who_may_use_templates_opens_the_composer(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $nobody = $this->makeUser($aleppo, 'employee', []);
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['message-templates.use']);

        Livewire::actingAs($nobody)->test(AnnouncementComposer::class)
            ->dispatch('announcement-compose')
            ->assertForbidden();

        Livewire::actingAs($hr)->test(AnnouncementComposer::class)
            ->dispatch('announcement-compose', template: 'welcome', prefill: ['name' => 'جان مغامز', 'not_a_field' => 'x'])
            ->assertSet('open', true)
            ->assertSet('template', 'welcome')
            ->assertSet('values.name', 'جان مغامز')
            ->assertSet('to', 'team@hcsyria.org')
            ->assertSee('ترحيب بانضمام جان مغامز')
            ->set('values.end', 'ignored')
            ->assertSet('values', fn (array $values) => ! array_key_exists('not_a_field', $values) && ! array_key_exists('end', $values));

        $ids = array_column(CommandPalette::for($hr), 'id');
        $this->assertContains('announcement', $ids);
        $this->assertNotContains('announcement', array_column(CommandPalette::for($nobody), 'id'));
    }

    public function test_the_holiday_composer_moves_the_return_day(): void
    {
        $aleppo = $this->makeCity('ALP', '001');
        $hr = $this->makeUser($aleppo, 'HR-ALP', ['message-templates.use']);

        Livewire::actingAs($hr)->test(AnnouncementComposer::class)
            ->call('compose', 'holiday_closure')
            ->set('values.end', '2027-01-11')
            ->assertSet('values.resume', '2027-01-12')
            ->assertSet('cc', 'fredy.youssef@hcsyria.org');
    }
}
