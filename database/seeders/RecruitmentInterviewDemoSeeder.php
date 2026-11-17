<?php

namespace Database\Seeders;

use App\Enums\RecruitmentInterviewStatus as Status;
use App\Models\RecruitmentApplicant;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewParticipant as Participant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local development only: fills the interviews board with candidates in
 * every lane (coming up, awaiting evaluation, held, cancelled or no-show),
 * several rounds for some candidates, times relative to now.
 *
 *   php artisan db:seed --class=RecruitmentInterviewDemoSeeder
 *
 * Built from the first existing application, vacancy and campaign, so it
 * needs a database that already went through one recruitment campaign.
 * Interviews are never deleted, so it refuses to run twice.
 */
class RecruitmentInterviewDemoSeeder extends Seeder
{
    /** The panel: the viewer first, then the colleagues who sit with them. */
    private const PANEL = [3, 546, 1, 2];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo interviews are for local development only.');
        }

        if (RecruitmentApplicant::query()->where('full_name', 'Lina Haddad')->exists()) {
            $this->command?->warn('Demo interviews are already seeded.');

            return;
        }

        $template = RecruitmentApplication::query()->with('applicant')->firstOrFail();
        // Vacancies come only from approved requests, so every demo candidate
        // applies to the template's own vacancy.
        $vacancies = [$template->vacancy_id];
        $now = now()->second(0);

        DB::transaction(function () use ($template, $vacancies, $now): void {
            // [candidate, vacancy index, [type, status, start, minutes, extras]...]
            $plan = [
                ['Lina Haddad', 1, [
                    ['hr', Status::Completed, $now->copy()->subDays(6)->setTime(10, 0), 45, ['closed' => true]],
                    ['technical', Status::Completed, $now->copy()->subDay()->setTime(12, 0), 60, []],
                    ['final', Status::Scheduled, $now->copy()->addDay()->setTime(11, 0), 45, []],
                ]],
                ['Omar Khoury', 1, [['hr', Status::Scheduled, $now->copy()->subMinutes(15), 45, []]]],
                ['Rasha Nasser', 2, [['technical', Status::Scheduled, $now->copy()->addHours(3)->minute(0), 60, ['online' => 'Microsoft Teams']]]],
                ['Fadi Mansour', 2, [['hr', Status::Scheduled, $now->copy()->subDays(2)->setTime(13, 30), 30, []]]],
                ['Maya Saliba', 0, [['hr', Status::Rescheduled, $now->copy()->addDay()->setTime(14, 0), 30, []]]],
                ['Elias Aboud', 1, [['hr', Status::Scheduled, $now->copy()->addDays(3)->setTime(9, 30), 30, ['online' => 'Zoom']]]],
                ['Nour Kassab', 2, [
                    ['hr', Status::Completed, $now->copy()->subDays(9)->setTime(10, 0), 30, ['closed' => true]],
                    ['technical', Status::Completed, $now->copy()->subDays(5)->setTime(11, 0), 60, ['closed' => true]],
                    ['final', Status::Scheduled, $now->copy()->addDays(6)->setTime(10, 0), 60, []],
                ]],
                ['George Hanna', 0, [['hr', Status::Cancelled, $now->copy()->subDays(3)->setTime(11, 0), 30, ['reason' => 'candidate_declined']]]],
                ['Sara Daher', 1, [
                    ['hr', Status::Completed, $now->copy()->subDays(8)->setTime(9, 0), 30, ['closed' => true]],
                    ['technical', Status::NoShow, $now->copy()->subDays(2)->setTime(10, 0), 60, []],
                ]],
                ['Tony Barakat', 2, [
                    ['hr', Status::Completed, $now->copy()->subDays(8)->setTime(12, 0), 30, ['closed' => true]],
                    ['technical', Status::Completed, $now->copy()->subDays(3)->setTime(14, 0), 45, []],
                ]],
            ];

            foreach ($plan as [$name, $vacancyIndex, $rounds]) {
                $application = $this->application($template, $name, $vacancies[$vacancyIndex % count($vacancies)]);

                foreach ($rounds as $index => [$type, $status, $start, $minutes, $extras]) {
                    $this->interview($application, $type, $status, $start, $minutes, $extras, $index);
                }
            }
        });

        $this->command?->info('Demo interviews seeded. Sign in as user #'.self::PANEL[0].' to see them as a panel member.');
    }

    private function application(RecruitmentApplication $template, string $name, int $vacancyId): RecruitmentApplication
    {
        $slug = Str::slug($name, '.');

        $applicant = $template->applicant->replicate();
        $applicant->forceFill([
            'full_name' => $name,
            'email_normalized' => $slug.'@example.test',
            'phone_normalized' => null,
            'phone_match_key' => null,
            'identity_key_hash' => hash('sha256', 'demo-'.$slug),
            'possible_duplicate_of_applicant_id' => null,
            'employee_id' => null,
        ])->save();

        $application = $template->replicate();
        $application->forceFill([
            'applicant_id' => $applicant->getKey(),
            'vacancy_id' => $vacancyId,
            'campaign_invite_id' => null,
            'submission_key_hash' => hash('sha256', 'demo-submission-'.$slug),
            'email_normalized' => $slug.'@example.test',
            'status' => 'interview_scheduled',
        ])->save();

        return $application;
    }

    /** @param  array<string, mixed>  $extras */
    private function interview(RecruitmentApplication $application, string $type, string $status, Carbon $start, int $minutes, array $extras, int $round): void
    {
        $recorded = in_array($status, [Status::Completed, Status::NoShow], true) ? $start->copy()->addMinutes($minutes + 20) : null;

        $interview = RecruitmentInterview::query()->create([
            'application_id' => $application->getKey(),
            'interview_type' => $type,
            'status' => $status,
            'scheduled_at' => $start,
            'ends_at' => $start->copy()->addMinutes($minutes),
            'timezone' => config('app.timezone'),
            'location_type' => isset($extras['online']) ? 'online' : 'in_person',
            'location_value' => $extras['online'] ?? 'Hope Center, meeting room '.($round + 1),
            'meeting_url' => isset($extras['online']) ? 'https://example.test/meet/demo-'.$application->getKey() : null,
            'slot_set_at' => $start->copy()->subDays(3),
            'reschedule_count' => $status === Status::Rescheduled ? 1 : 0,
            'created_by_user_id' => self::PANEL[0],
            'cancelled_at' => $status === Status::Cancelled ? $start->copy()->subDay() : null,
            'cancelled_by_user_id' => $status === Status::Cancelled ? self::PANEL[0] : null,
            'cancellation_reason' => $extras['reason'] ?? null,
            'outcome_recorded_at' => $recorded,
            'outcome_recorded_by_user_id' => $recorded ? self::PANEL[0] : null,
            'evaluations_closed_at' => ($extras['closed'] ?? false) ? $start->copy()->addDays(2) : null,
            'evaluations_closed_by_user_id' => ($extras['closed'] ?? false) ? self::PANEL[0] : null,
        ]);

        // The viewer on every panel; one or two colleagues, rotating.
        $panel = [self::PANEL[0] => Participant::ROLE_INTERVIEWER, self::PANEL[1 + $round % 3] => Participant::ROLE_INTERVIEWER];
        if ($type === 'final') {
            $panel[self::PANEL[(2 + $round) % 3 + 1]] = Participant::ROLE_OBSERVER;
        }

        foreach ($panel as $userId => $role) {
            Participant::query()->create([
                'interview_id' => $interview->getKey(),
                'user_id' => $userId,
                'role' => $role,
                'notification_status' => Participant::NOTIFICATION_SENT,
                'added_by_user_id' => self::PANEL[0],
            ]);
        }
    }
}
