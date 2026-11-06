<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractAmendment;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The signed copy of a contract amendment, from the private disk. It carries
 * the pay clause whenever pay changed, so it is held to the contract export's
 * rule (ContractPolicy::exportAmendment) and refused like an unknown file.
 */
class ContractAmendmentSignedController extends Controller
{
    public function __invoke(Contract $contract, ContractAmendment $amendment): BinaryFileResponse
    {
        abort_unless(Gate::allows('exportAmendment', $amendment), Response::HTTP_NOT_FOUND);

        $media = $amendment->getFirstMedia('signed');

        abort_unless($media && is_file($media->getPath()), Response::HTTP_NOT_FOUND);

        $response = response()->download($media->getPath(), $media->file_name, [
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
