<?php

namespace App\Services\Policies;

use App\Jobs\ReadPolicyFile;
use App\Models\Folder;
use App\Models\PolicyDocument;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Keeps the policies in step with the files of the Policies folder; called by
 * the media observer, so an upload, a rename or a deletion in Folders is all
 * it takes.
 *
 *  - A PDF, Word or text file arriving is a new policy, read in the background
 *    (ReadPolicyFile). Until then it shows as "being read" to HR only.
 *  - Replacing a file (replace()) keeps the policy, its settings and its
 *    agreements; the old text stays readable until the new one is in, and then
 *    the version moves on and everyone who had agreed is asked again.
 *  - Renaming the file renames the policy; deleting it deletes the policy.
 */
class PolicySync
{
    public const COLLECTION = 'archived_files';

    public function __construct(private PolicyFolder $folder) {}

    public function isPolicyFile(Media $media): bool
    {
        if ($media->model_type !== Folder::class || $media->collection_name !== self::COLLECTION) {
            return false;
        }

        return PolicyFolder::isReadable($media->extension)
            && $this->folder->contains(Folder::query()->find($media->model_id));
    }

    public function added(Media $media): void
    {
        if (! $this->isPolicyFile($media) || PolicyDocument::query()->where('media_id', $media->id)->exists()) {
            return;
        }

        // Replacing hands over the policy itself (replace()); a file marked so is not a new policy.
        if ($media->getCustomProperty('replaces_policy') !== null) {
            return;
        }

        $document = PolicyDocument::query()->create([
            'media_id' => $media->id,
            'title' => $this->title($media),
            'mime_type' => $media->mime_type,
            'read_status' => PolicyDocument::READING,
        ]);

        ReadPolicyFile::dispatch($document->id)->afterCommit();
    }

    /** The new file takes over the policy; the old file goes. */
    public function replace(PolicyDocument $document, Media $new): void
    {
        $old = $document->media;

        $document->forceFill([
            'media_id' => $new->id,
            'title' => $this->title($new),
            'mime_type' => $new->mime_type,
            'read_status' => PolicyDocument::READING,
            'read_error' => null,
        ])->save();

        $old?->delete();

        ReadPolicyFile::dispatch($document->id, newVersion: true)->afterCommit();
    }

    public function renamed(Media $media): void
    {
        PolicyDocument::query()->where('media_id', $media->id)->update(['title' => $this->title($media)]);
    }

    public function deleted(Media $media): void
    {
        // A replaced file's policy points at the new file by now.
        PolicyDocument::query()->where('media_id', $media->id)->delete();
    }

    private function title(Media $media): string
    {
        return trim(preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', (string) $media->name) ?: (string) $media->name);
    }
}
