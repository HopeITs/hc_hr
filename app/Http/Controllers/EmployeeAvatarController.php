<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves employee photographs, which are stored on the private disk.
 *
 * There are deliberately two entry points, because there are two legitimate
 * audiences with nothing in common:
 *
 *  - internal(): a signed-in HR user, authorized by EmployeePolicy, which
 *    enforces city scope. This is the path every in-app <img> uses.
 *  - digitalId(): the public ID card at /ID/{card}. That page has no session
 *    — anyone holding the printed QR code may view it — so it cannot use the
 *    authenticated endpoint, and the authenticated endpoint must not be
 *    relaxed to accommodate it. It instead receives a short-lived signed URL
 *    minted only after the card link resolved to an active employee's
 *    current, enabled card.
 *
 * Neither response reveals a storage path, and neither is cacheable by a
 * shared proxy.
 */
class EmployeeAvatarController extends Controller
{
    public function internal(Employee $employee): BinaryFileResponse|StreamedResponse
    {
        // A face photo isn't as sensitive as the rest of an employee record,
        // so this checks the lighter 'viewAvatar' ability (any signed-in
        // user, still bounded by HR city scope) rather than 'view'.
        Gate::authorize('viewAvatar', $employee);

        return $this->stream($employee, 'private, max-age=300, must-revalidate');
    }

    public function digitalId(Request $request, Employee $employee): BinaryFileResponse|StreamedResponse
    {
        // The signature covers the employee id and the expiry, so a valid link
        // cannot be edited to point at a different employee, and it is minted
        // only after ShowDigitalId resolved the card. Deactivating the employee
        // or disabling the card revokes it even while an unexpired link is in
        // flight.
        abort_unless($request->hasValidSignature(), Response::HTTP_FORBIDDEN);
        abort_unless(
            $employee->is_active && $employee->digital_id_enabled,
            Response::HTTP_NOT_FOUND,
        );

        return $this->stream($employee, 'private, max-age=300, must-revalidate');
    }

    private function stream(Employee $employee, string $cacheControl): BinaryFileResponse|StreamedResponse
    {
        $media = $employee->getFirstMedia('avatar');

        // No photo on file: fall back to the packaged placeholder rather than
        // 404, so callers cannot distinguish "no photo" from "no such
        // employee" and layouts do not break.
        $path = $media && is_file($media->getPath())
            ? $media->getPath()
            : public_path('images/default-avatar.png');

        $mime = $media && is_file($path)
            ? ($media->mime_type ?: 'image/jpeg')
            : 'image/png';

        abort_unless(is_file($path), Response::HTTP_NOT_FOUND);

        // Only image types this application produces may be echoed back; the
        // stored mime is server-generated, but pinning it here means a
        // tampered media row still cannot turn this endpoint into an HTML or
        // SVG sink.
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $mime = 'application/octet-stream';
        }

        $response = response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);

        // BinaryFileResponse writes its own public Cache-Control during
        // preparation, so passing it in the header array above is not enough —
        // it has to be set on the response object afterwards.
        $response->headers->set('Cache-Control', $cacheControl);

        return $response;
    }
}
