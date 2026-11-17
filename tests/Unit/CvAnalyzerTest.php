<?php

namespace Tests\Unit;

use App\Services\Recruitment\Cv\CvAnalyzer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CvAnalyzerTest extends TestCase
{
    private CvAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-25');
        $this->analyzer = new CvAnalyzer;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_arabic_cv_yields_experience_education_languages_and_skills(): void
    {
        $cv = <<<'CV'
            الاسم: رنا حداد
            التعليم
            2010 - 2014 إجازة في الاقتصاد، جامعة البعث
            2015 - 2017 ماجستير إدارة أعمال
            الخبرات العملية
            ٢٠١٨ - ٢٠٢١ محاسبة في منظمة محلية
            2020 - حتى الآن مسؤولة مالية
            خبرة ثلاث سنوات في إعداد التقارير المالية
            المهارات: إكسل، العمل ضمن فريق
            اللغات: العربية، الإنكليزية
            CV;

        $facts = $this->analyzer->facts($cv);

        // 2018-2021 and 2020-now overlap: counted once, 2018-2026 = 8 years.
        // The schooling ranges are left out.
        $this->assertSame([[2018, 2021], [2020, 2026]], $facts['experience']['ranges']);
        $this->assertSame(8, $facts['experience']['from_dates']);
        $this->assertSame(3, $facts['experience']['stated']);
        $this->assertSame(8, $facts['experience']['years']);
        $this->assertSame('master', $facts['education']['level']);
        $this->assertSame(['arabic', 'english'], $facts['languages']);
        $this->assertContains('excel', $facts['skills']);
        $this->assertContains('teamwork', $facts['skills']);
        $this->assertContains('accounting', $facts['skills']);
    }

    public function test_an_english_cv_states_its_experience_and_degree(): void
    {
        $facts = $this->analyzer->facts(<<<'CV'
            Summary: 5+ years of experience in field coordination.
            Education: Bachelor of Social Work, Damascus University (2012 - 2016)
            Languages: Arabic (native), English (fluent), French (basic)
            Skills: MS Office, report writing, first aid
            CV);

        $this->assertSame(5, $facts['experience']['years']);
        $this->assertSame([], $facts['experience']['ranges']);
        $this->assertSame('university', $facts['education']['level']);
        $this->assertSame(['arabic', 'english', 'french'], $facts['languages']);
        $this->assertSame(['ms office', 'report writing', 'first aid'], array_values(array_intersect(['ms office', 'report writing', 'first aid'], $facts['skills'])));
    }

    public function test_required_skills_match_across_languages_and_by_phrase(): void
    {
        $cv = "خبرة في برنامج اكسل\nPsychosocial support for children\nManaged field teams across several governorates";
        $facts = $this->analyzer->facts($cv);

        $comparison = $this->analyzer->compare(
            $cv,
            $facts,
            requiredYears: 2,
            requiredQualifications: 'شهادة جامعية في العلوم الاجتماعية',
            requiredSkills: "Excel, Psychosocial support\nExperience managing field teams across governorates\nArmenian translation",
        );

        $items = collect($comparison['skills']['items'])->keyBy('term');
        $this->assertTrue($items['Excel']['matched']);
        $this->assertSame('اكسل', $items['Excel']['evidence']);
        $this->assertTrue($items['Psychosocial support']['matched']);
        $this->assertTrue($items['Experience managing field teams across governorates']['matched']);
        $this->assertFalse($items['Armenian translation']['matched']);
        $this->assertSame([3, 4], [$comparison['skills']['matched'], $comparison['skills']['total']]);

        // No years or level in this CV: the comparison says it cannot tell, not "no".
        $this->assertSame('unknown', $comparison['experience']['outcome']);
        $this->assertSame(['outcome' => 'unknown', 'required' => 'university'], $comparison['education']);
    }

    public function test_the_comparison_reports_met_and_below_against_the_request(): void
    {
        $cv = "Work experience\n2019 - 2022 Teacher\nDiploma in education";
        $facts = $this->analyzer->facts($cv);

        $comparison = $this->analyzer->compare($cv, $facts, 5, 'Bachelor degree required', null);

        $this->assertSame(['outcome' => 'not_met', 'required' => 5, 'found' => 3], $comparison['experience']);
        $this->assertSame(['outcome' => 'not_met', 'required' => 'university', 'found' => 'institute'], $comparison['education']);
        $this->assertSame('not_applicable', $comparison['skills']['outcome']);

        $this->assertSame('met', $this->analyzer->compare($cv, $facts, 3, 'معهد أو دبلوم', null)['experience']['outcome']);
        $this->assertSame('met', $this->analyzer->compare($cv, $facts, 3, 'معهد أو دبلوم', null)['education']['outcome']);
    }

    public function test_latin_words_match_whole_words_only(): void
    {
        $facts = $this->analyzer->facts('Mastering spreadsheets; wordsmith; officer');

        $this->assertNull($facts['education']);
        $this->assertNotContains('word', $facts['skills']);
        $this->assertNotContains('ms office', $facts['skills']);
    }
}
