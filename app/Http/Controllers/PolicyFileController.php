<?php

namespace App\Http\Controllers;

use App\Models\PolicyDocument;
use App\Services\Policies\PolicyFolder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A policy's original file, for every signed-in employee (who all read the
 * policies): a PDF opens in the browser, anything else, or ?download=1,
 * downloads under the policy's own name. Only a file still in the Policies
 * folder is served.
 */
class PolicyFileController extends Controller
{
    public function __invoke(Request $request, PolicyDocument $document, PolicyFolder $folder): BinaryFileResponse
    {
        $media = $document->media;

        abort_if($media === null || ! is_file($media->getPath()), 404);
        abort_unless($folder->contains($media->model), 404);

        $extension = strtolower((string) $media->extension);
        $name = str_replace(['"', '\\', '/', "\r", "\n"], '', $document->title).'.'.$extension;

        if ($extension === 'pdf' && ! $request->boolean('download')) {
            return response()->file($media->getPath(), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode($name),
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return response()->download($media->getPath(), $name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
