<?php

namespace App\Jobs;

use App\Mail\RecruitmentResponseReceiptMail;
use App\Models\RecruitmentApplication;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendRecruitmentResponseReceipt implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $applicationId) {}

    public function handle(): void
    {
        $application = RecruitmentApplication::query()
            ->with(['applicant', 'campaign.vacancy.position', 'formVersion', 'answers', 'files'])
            ->find($this->applicationId);

        if ($application === null || ! data_get($application->consent_payload_json, 'response_copy', false)) {
            return;
        }

        $email = $application->email_normalized ?: $application->applicant?->email_normalized;
        if (! is_string($email) || $email === '') {
            return;
        }

        Mail::to($email)->send(new RecruitmentResponseReceiptMail($application));
    }
}
