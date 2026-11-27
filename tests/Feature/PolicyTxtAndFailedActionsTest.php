<?php

namespace Tests\Feature;

use App\Livewire\Archives\ArchivedFilesModule;
use App\Livewire\Policies\Index;
use App\Models\PolicyDocument;
use App\Models\User;
use App\Services\Policies\PolicyFolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/** TXT policies in the Policies folder, rename without extension, and Delete/Replace on a failed policy. */
class PolicyTxtAndFailedActionsTest extends TestCase
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

    public function test_txt_shows_in_the_folder_downloads_and_renames_without_doubling_the_extension(): void
    {
        $document = $this->uploadTxt('Policy Travel.txt');
        $media = $document->media;

        $this->assertSame('txt', $media->extension);

        $folder = Livewire::actingAs($this->hr)->test(ArchivedFilesModule::class, ['folderId' => $this->library()->id]);
        $folder->assertSee('Policy Travel.txt');

        $this->actingAs($this->hr)->get(route('folder-files.download', $media))->assertOk();
        $this->actingAs($this->employee)->get(route('folder-files.download', $media))->assertOk();

        // Typed with or without the extension, it is kept once; Arabic and odd characters are safe.
        foreach (['Policy Travel 2' => 'Policy Travel 2.txt', 'Policy Travel 3.TXT' => 'Policy Travel 3.txt', 'سياسة السفر' => 'سياسة السفر.txt', 'a/b<>c' => 'bc.txt'] as $typed => $expected) {
            $folder->call('startEditingFile', $media->id)->set('editingFileName', $typed)->call('saveEditedFile')->assertHasNoErrors();
            $this->assertSame($expected, $media->fresh()->name);
            $this->assertSame($media->file_name, $media->fresh()->file_name, 'the stored file is untouched');
            $this->assertFileExists($media->fresh()->getPath());
        }

        $this->assertSame('bc', $document->fresh()->title);
    }

    public function test_the_rename_box_shows_the_name_without_extension_and_keeps_the_files_own(): void
    {
        $document = $this->uploadTxt('x.txt');
        $media = $document->media;
        $media->forceFill(['name' => 'Policy Travel.pdf'])->save();

        Livewire::actingAs($this->hr)->test(ArchivedFilesModule::class, ['folderId' => $this->library()->id])
            ->call('startEditingFile', $media->id)
            ->assertSet('editingFileName', 'Policy Travel')
            ->set('editingFileName', 'Policy Travel.pdf')
            ->call('saveEditedFile');

        // The extension is the file's own (txt), so a typed ".pdf" is part of the name, not stripped.
        $this->assertSame('Policy Travel.pdf.txt', $media->fresh()->name);
    }

    public function test_a_binary_file_named_txt_is_refused(): void
    {
        Livewire::actingAs($this->hr)->test(Index::class)
            ->set('newPolicy', UploadedFile::fake()->createWithContent('bad.txt', "abc\0def"))
            ->assertHasErrors('newPolicy');

        $this->assertSame(0, PolicyDocument::query()->count());
    }

    public function test_a_failed_policy_can_be_replaced_in_place_and_read_again(): void
    {
        $document = $this->uploadTxt('Leave.txt');
        $document->forceFill(['read_status' => PolicyDocument::FAILED, 'read_error' => 'unreadable'])->save();
        $oldMedia = $document->media_id;

        $page = Livewire::actingAs($this->hr)->test(Index::class)
            ->assertSeeHtml('data-testid="policy-replace-'.$document->id.'"')
            ->assertSeeHtml('data-testid="policy-remove-'.$document->id.'"')
            ->call('startReplace', $document->id)
            ->assertSee('Leave')
            ->set('replacement', UploadedFile::fake()->createWithContent('Leave v2.txt', 'Second version of the leave policy.'))
            ->assertHasNoErrors();

        $this->assertNull($page->get('replacingId'));
        $document->refresh();
        $this->assertSame(1, PolicyDocument::query()->count());
        $this->assertNotSame($oldMedia, $document->media_id);
        $this->assertNull(Media::query()->find($oldMedia));
        $this->assertSame(PolicyDocument::READY, $document->read_status);
        $this->assertStringContainsString('Second version', $document->content);
    }

    public function test_a_failed_replacement_keeps_the_existing_policy(): void
    {
        $document = $this->uploadTxt('Leave.txt');
        $document->forceFill(['read_status' => PolicyDocument::FAILED, 'read_error' => 'unreadable'])->save();
        $media = $document->media_id;

        Livewire::actingAs($this->hr)->test(Index::class)
            ->call('startReplace', $document->id)
            ->set('replacement', UploadedFile::fake()->create('pic.png', 5))
            ->assertHasErrors('replacement');

        $document->refresh();
        $this->assertSame($media, $document->media_id);
        $this->assertFileExists(Media::query()->findOrFail($media)->getPath());
        $this->assertSame(1, Media::query()->where('collection_name', 'archived_files')->count());
    }

    public function test_a_failed_policy_can_be_deleted_from_the_page_with_its_file(): void
    {
        $document = $this->uploadTxt('Leave.txt');
        $document->forceFill(['read_status' => PolicyDocument::FAILED, 'read_error' => 'unreadable'])->save();
        $path = $document->media->getPath();

        Livewire::actingAs($this->hr)->test(Index::class)->call('remove', $document->id);

        $this->assertSame(0, PolicyDocument::query()->count());
        $this->assertSame(0, Media::query()->count());
        $this->assertFileDoesNotExist($path);
    }

    public function test_others_cannot_manage_policies(): void
    {
        $document = $this->uploadTxt('Leave.txt');

        Livewire::actingAs($this->employee)->test(Index::class)->assertForbidden();
        $this->assertFalse(PolicyFolder::canManage($this->employee));
        $this->assertSame(1, PolicyDocument::query()->count());
    }

    private function library()
    {
        return app(PolicyFolder::class)->library();
    }

    private function uploadTxt(string $name): PolicyDocument
    {
        Livewire::actingAs($this->hr)->test(Index::class)
            ->set('newPolicy', UploadedFile::fake()->createWithContent($name, "First version of the policy.\n"))
            ->assertHasNoErrors();

        return PolicyDocument::query()->latest('id')->firstOrFail();
    }
}
