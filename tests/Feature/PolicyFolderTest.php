<?php

namespace Tests\Feature;

use App\Jobs\ReadPolicyFile;
use App\Livewire\Archives\ArchivedFilesModule;
use App\Livewire\Onboarding\RequiredPolicies;
use App\Livewire\Policies\Index;
use App\Livewire\Policies\Show;
use App\Models\Folder;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyDocument;
use App\Models\User;
use App\Notifications\PolicyUpdated;
use App\Services\Assistant\PolicyLibrary;
use App\Services\Folders\FolderAccess;
use App\Services\Policies\DocxMarkdown;
use App\Services\Policies\PolicyFolder;
use App\Services\PolicyAcknowledgements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Policies live in the Policies folder (decided 2026-11-26): a PDF, Word or
 * text file put there is a policy, read in the background into Markdown and
 * shown as a formatted page in its own language's direction; replacing the
 * file is a new version everyone who agreed is asked to agree to again.
 */
class PolicyFolderTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private const ARCHIVES = ['archives.view', 'archives.create', 'archives.update', 'archives.delete', 'archives.download'];

    private User $hr;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();

        $city = $this->makeCity('SYR', '000');
        $this->hr = $this->makeUser($city, 'HR-SYR', ['employees.update', ...self::ARCHIVES]);
        $this->employee = $this->makeUser($city, 'employee', ['archives.view', 'archives.download']);
    }

    public function test_word_structure_becomes_markdown(): void
    {
        $markdown = app(DocxMarkdown::class)->convert($this->docxPath());

        $this->assertStringContainsString("# سياسة الإجازات\n", $markdown);
        $this->assertStringContainsString('# المادة الأولى: الإجازة السنوية', $markdown);
        $this->assertStringContainsString('يستحق الموظف **واحداً وعشرين يوماً** في السنة.', $markdown);
        $this->assertStringContainsString("1. تُطلب الإجازة عبر النظام.\n2. يوافق عليها المدير المباشر.", $markdown);
        $this->assertStringContainsString("| النوع | المدة |\n| --- | --- |\n| مرضية | 10 أيام |", $markdown);
        $this->assertStringContainsString('1\\. سطر يبدو قائمة لكنه فقرة', $markdown);
    }

    public function test_a_word_file_put_in_the_folder_becomes_a_policy_shown_as_a_formatted_rtl_page(): void
    {
        $document = $this->upload($this->docx());

        $this->assertSame('سياسة الإجازات', $document->title);
        $this->assertSame(PolicyDocument::READY, $document->read_status);
        $this->assertNotNull($document->source_modified_at);
        $this->assertSame('rtl', $document->direction());

        Livewire::actingAs($this->employee)->test(Show::class, ['document' => $document->id, 'q' => 'الإجازة'])
            ->assertSeeHtml('dir="rtl"')
            ->assertSeeHtml('<strong>واحداً وعشرين يوماً</strong>')
            ->assertSeeHtml('<ol>')
            ->assertSeeHtml('<table>')
            ->assertSeeHtml('<mark class="policy-mark">')
            ->assertSeeHtml(route('policies.file', $document->id))
            ->assertDontSeeHtml('data-testid="policy-agree"');

        // In the English interface the policy keeps its own direction.
        $this->actingAs($this->employee)->get(route('policies.show', $document->id))->assertOk()->assertSee('dir="rtl"', false);

        // The search finds it and leads to the passage.
        $this->assertSame($document->id, app(PolicyLibrary::class)->search('مرضية')[0]['id']);
    }

    public function test_every_employee_reads_the_folder_and_its_files_but_only_hr_changes_it(): void
    {
        $document = $this->upload($this->docx());
        $access = app(FolderAccess::class);

        $this->assertSame(FolderAccess::VIEWER, $access->role($this->employee, $this->library()));
        $this->assertSame(FolderAccess::OWNER, $access->role($this->hr, $this->library()));

        Livewire::withQueryParams(['scope' => 'shared'])->actingAs($this->employee)->test(ArchivedFilesModule::class)
            ->assertSee(__('policies.folder-name'));

        Livewire::actingAs($this->employee)->test(ArchivedFilesModule::class, ['folderId' => $this->library()->id])
            ->assertSeeHtml('data-testid="policy-folder-banner"')
            ->assertDontSeeHtml('data-testid="policy-replace-')
            ->set('filesToUpload', [$this->docx('another.docx')])
            ->assertForbidden();

        $download = $this->actingAs($this->employee)->get(route('policies.file', ['document' => $document->id, 'download' => 1]));
        $download->assertOk();
        $this->assertStringContainsString(rawurlencode('سياسة الإجازات.docx'), (string) $download->headers->get('Content-Disposition'));

        // The folder itself is never deleted or shared by hand.
        Livewire::actingAs($this->hr)->test(ArchivedFilesModule::class)
            ->call('deleteItem', ['type' => 'folder', 'id' => $this->library()->id])
            ->assertForbidden();
        Livewire::actingAs($this->hr)->test(ArchivedFilesModule::class)
            ->call('editPermissions', $this->library()->id)
            ->call('savePermissions')
            ->assertForbidden();
        $this->assertNotNull($this->library()->fresh());
    }

    public function test_replacing_the_file_is_a_new_version_everyone_who_agreed_agrees_to_again(): void
    {
        Notification::fake();
        $document = $this->upload($this->docx());
        $document->forceFill(['required_for_onboarding' => true])->save();
        $oldMedia = $document->media_id;

        $acks = app(PolicyAcknowledgements::class);
        $this->assertTrue($acks->acknowledge($this->employee->profile, $document));
        $this->assertSame('agreed', $acks->standing($this->employee->profile, $document));

        $this->travel(1)->minute();

        Livewire::actingAs($this->hr)->test(ArchivedFilesModule::class, ['folderId' => $this->library()->id])
            ->call('startReplacePolicyFile', $oldMedia)
            ->assertSeeHtml('data-testid="policy-replace"')
            ->set('replacementFile', $this->docx('سياسة الإجازات 2026.docx', '<w:p><w:r><w:t>المادة الثانية: الإجازة الأبوية</w:t></w:r></w:p>'))
            ->assertHasNoErrors();

        $document->refresh();
        $this->assertSame(1, PolicyDocument::query()->count());
        $this->assertNotSame($oldMedia, $document->media_id);
        $this->assertNull(\Spatie\MediaLibrary\MediaCollections\Models\Media::query()->find($oldMedia));
        $this->assertStringContainsString('الإجازة الأبوية', $document->content);
        $this->assertSame('سياسة الإجازات 2026', $document->title);

        $this->assertSame('outdated', $acks->standing($this->employee->profile, $document));
        $this->assertSame([$document->id], $acks->outdatedFor($this->employee->profile)->all());
        Notification::assertSentTo($this->employee, PolicyUpdated::class);

        Livewire::actingAs($this->employee)->test(Index::class)->assertSee(__('policies.new-version'));

        Livewire::actingAs($this->employee)->test(Show::class, ['document' => $document->id])
            ->assertSee(__('policies.agree-again-lead'))
            ->call('agree');

        $this->assertSame('agreed', $acks->standing($this->employee->profile, $document));
        $this->assertSame(1, PolicyAcknowledgement::query()->count());
    }

    public function test_renaming_or_deleting_the_file_renames_or_deletes_the_policy(): void
    {
        $document = $this->upload($this->docx());

        $page = Livewire::actingAs($this->hr)->test(ArchivedFilesModule::class, ['folderId' => $this->library()->id])
            ->call('startEditingFile', $document->media_id)
            ->set('editingFileName', 'سياسة الإجازات المعدّلة')
            ->call('saveEditedFile');
        $this->assertSame('سياسة الإجازات المعدّلة', $document->fresh()->title);

        $page->call('deleteItem', ['type' => 'file', 'id' => $document->media_id]);
        $this->assertSame(0, PolicyDocument::query()->count());
    }

    public function test_other_files_and_other_folders_are_not_policies(): void
    {
        $other = Folder::query()->forceCreate(['name' => 'Mine', 'user_id' => $this->hr->id]);

        Livewire::actingAs($this->hr)->test(ArchivedFilesModule::class, ['folderId' => $other->id])
            ->set('filesToUpload', [$this->docx('notes.docx')])
            ->assertHasNoErrors();

        $this->assertSame(0, PolicyDocument::query()->count());
    }

    public function test_a_pdf_that_cannot_be_read_tells_hr_why_and_shows_to_no_one(): void
    {
        config(['assistant.gemini.key' => null]);
        $folder = $this->library();
        $media = $folder->addMediaFromString('%PDF-1.7')->usingFileName('policy.pdf')->usingName('Travel policy.pdf')->toMediaCollection('archived_files', 'local');

        $document = PolicyDocument::query()->where('media_id', $media->id)->sole();
        $this->assertSame(PolicyDocument::FAILED, $document->read_status);
        $this->assertSame('unavailable', $document->read_error);

        Livewire::actingAs($this->hr)->test(Index::class)->assertSee(__('policies.error.unavailable'));
        Livewire::actingAs($this->hr)->test(RequiredPolicies::class)->assertSee(__('policies.error.unavailable'));
        Livewire::actingAs($this->employee)->test(Index::class)->assertDontSee('Travel policy');
    }

    public function test_hr_reaches_the_folder_and_the_settings_from_the_policies_page(): void
    {
        $folderUrl = ArchivedFilesModule::folderUrl($this->library()->id);

        Livewire::actingAs($this->hr)->test(Index::class)
            ->assertSeeHtml('data-testid="policies-manage"')->assertSeeHtml($folderUrl)
            ->assertSeeHtml(route('onboarding.required-policies'));
        Livewire::actingAs($this->employee)->test(Index::class)->assertDontSeeHtml('data-testid="policies-manage"');

        Livewire::actingAs($this->employee)->test(RequiredPolicies::class)->assertForbidden();
        Livewire::actingAs($this->hr)->test(RequiredPolicies::class)->assertSeeHtml($folderUrl);
    }

    public function test_a_text_only_policy_from_before_the_folder_still_reads_and_hr_can_remove_it(): void
    {
        $old = PolicyDocument::query()->create(['title' => 'Old policy', 'content' => "## Travel\nReceipts are required."]);

        Livewire::actingAs($this->employee)->test(Show::class, ['document' => $old->id])
            ->assertSeeHtml('<h2>Travel</h2>')->assertSee(__('policies.no-file'))->assertSeeHtml('dir="ltr"');
        $this->actingAs($this->employee)->get(route('policies.file', $old->id))->assertNotFound();

        Livewire::actingAs($this->hr)->test(RequiredPolicies::class)
            ->assertSeeHtml('data-testid="text-only-'.$old->id.'"')
            ->call('deleteTextOnly', $old->id);
        $this->assertSame(0, PolicyDocument::query()->count());
    }

    public function test_html_in_a_policy_is_shown_as_text(): void
    {
        $doc = PolicyDocument::query()->create(['title' => 'X', 'content' => "<script>alert(1)</script>\n[link](javascript:alert(1))"]);

        Livewire::actingAs($this->employee)->test(Show::class, ['document' => $doc->id])
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertDontSeeHtml('href="javascript:');
    }

    public function test_a_busy_gemini_is_tried_again_later_by_the_queue(): void
    {
        config(['assistant.gemini.key' => 'k', 'assistant.gemini.retry_wait' => 0, 'assistant.gemini.transcribe_attempts' => 1, 'assistant.gemini.fallback_models' => []]);
        \Illuminate\Support\Facades\Http::fake(['generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(['error' => ['message' => 'busy']], 503)]);
        \Illuminate\Support\Facades\Queue::fake();

        $media = $this->library()->addMediaFromString('%PDF-1.7')->usingFileName('p.pdf')->usingName('P.pdf')->toMediaCollection('archived_files', 'local');
        $document = PolicyDocument::query()->where('media_id', $media->id)->sole();
        \Illuminate\Support\Facades\Queue::assertPushed(ReadPolicyFile::class);

        $job = new ReadPolicyFile($document->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(\App\Services\Assistant\PolicyFileReader::class), app(PolicyAcknowledgements::class));

        $job->assertReleased();
        $this->assertSame(PolicyDocument::READING, $document->fresh()->read_status);
    }

    private function library(): Folder
    {
        return app(PolicyFolder::class)->library();
    }

    /** A real .docx: a title, a heading by style name, numbered clauses, bold, a table and a line that looks like a list. */
    private function docx(string $name = 'سياسة الإجازات.docx', string $extra = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($this->docxPath($extra)))
            ->mimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    private function docxPath(string $extra = ''): string
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $p = fn (string $text, string $ppr = '', string $rpr = '') => "<w:p><w:pPr>{$ppr}</w:pPr><w:r><w:rPr>{$rpr}</w:rPr><w:t xml:space=\"preserve\">{$text}</w:t></w:r></w:p>";
        $numbered = '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>';

        $body = $p('سياسة الإجازات', '<w:pStyle w:val="a3"/>')
            .$p('المادة الأولى: الإجازة السنوية', '<w:pStyle w:val="1"/>')
            .'<w:p><w:r><w:t xml:space="preserve">يستحق الموظف </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>واحداً وعشرين يوماً</w:t></w:r><w:r><w:t xml:space="preserve"> في السنة.</w:t></w:r></w:p>'
            .$p('تُطلب الإجازة عبر النظام.', $numbered)
            .$p('يوافق عليها المدير المباشر.', $numbered)
            .'<w:tbl><w:tr><w:tc>'.$p('النوع').'</w:tc><w:tc>'.$p('المدة').'</w:tc></w:tr><w:tr><w:tc>'.$p('مرضية').'</w:tc><w:tc>'.$p('10 أيام').'</w:tc></w:tr></w:tbl>'
            .$p('1. سطر يبدو قائمة لكنه فقرة')
            .$extra;

        $styles = "<w:styles {$w}><w:style w:type=\"paragraph\" w:styleId=\"a3\"><w:name w:val=\"Title\"/></w:style><w:style w:type=\"paragraph\" w:styleId=\"1\"><w:name w:val=\"heading 1\"/></w:style></w:styles>";
        $numbering = "<w:numbering {$w}><w:abstractNum w:abstractNumId=\"0\"><w:lvl w:ilvl=\"0\"><w:numFmt w:val=\"decimal\"/></w:lvl></w:abstractNum><w:num w:numId=\"1\"><w:abstractNumId w:val=\"0\"/></w:num></w:numbering>";

        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><w:document {$w}><w:body>{$body}</w:body></w:document>");
        $zip->addFromString('word/styles.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>{$styles}");
        $zip->addFromString('word/numbering.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>{$numbering}");
        $zip->close();

        return $path;
    }

    private function upload(UploadedFile $file): PolicyDocument
    {
        Livewire::actingAs($this->hr)->test(ArchivedFilesModule::class, ['folderId' => $this->library()->id])
            ->set('filesToUpload', [$file])
            ->assertHasNoErrors();

        return PolicyDocument::query()->latest('id')->firstOrFail();
    }
}
