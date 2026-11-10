<?php

namespace App\Livewire\Recruitment\Campaigns;

use App\Livewire\Concerns\WithFeedback;
use App\Models\HiringCampaign;
use App\Models\RecruitmentCampaignInvite;
use App\Services\RecruitmentCampaignInviteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Filters\SelectFilter;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

/**
 * The invite list for one campaign: filterable by status, globally
 * searchable by name/email, with a per-row Cancel action.
 *
 * The Cancel button is a plain computed column with a real wire:click method
 * (ContractTypeTable's pattern), not the package's Action/ActionColumn
 * bulk-select mechanism — that dropdown relies on an `item` Alpine binding
 * that never resolved correctly here, and it would also have added a
 * row-selection checkbox column nobody asked for. Dropping actions()
 * entirely also removes those checkboxes, since HasSelection::canSelect()
 * only enables them when a non-standalone action exists.
 */
class InviteTable extends LivewireTable
{
    use WithFeedback;

    public int $campaignId;

    protected string $model = RecruitmentCampaignInvite::class;

    public function mount(int $campaignId): void
    {
        $this->campaignId = $campaignId;
        $this->authorizeScreen();
    }

    public function confirmCancel(int $id): void
    {
        $this->confirm('cancelInvite', ['id' => $id],
            title: __('recruitment-applicants.invite_cancel'),
            message: __('recruitment-applicants.invite_cancel_confirm'),
            confirmLabel: __('recruitment-applicants.invite_cancel'),
        );
    }

    public function sendInvite(int $id): void
    {
        $invite = RecruitmentCampaignInvite::query()
            ->where('campaign_id', $this->campaignId)
            ->findOrFail($id);

        try {
            app(RecruitmentCampaignInviteService::class)->send(auth()->user(), $invite);

            $this->toast(__('recruitment-applicants.invite_sent_toast'));
        } catch (ValidationException $exception) {
            $this->toast(collect($exception->errors())->collapse()->first(), 'error');
        }
    }

    public function cancelInvite(array $payload): void
    {
        $invite = RecruitmentCampaignInvite::query()
            ->where('campaign_id', $this->campaignId)
            ->findOrFail($payload['id']);

        try {
            app(RecruitmentCampaignInviteService::class)->cancel(auth()->user(), $invite);

            $this->toast(__('recruitment-applicants.invite_cancelled'));
        } catch (ValidationException $exception) {
            // A used invite can't be cancelled — surfaced as a toast rather
            // than a fatal error.
            $this->toast(collect($exception->errors())->collapse()->first(), 'error');
        }
    }

    protected function query(): Builder
    {
        $this->authorizeScreen();

        // Scoping to this campaign is what makes cancelInvite() safe against
        // a tampered id: findOrFail() on this query can never resolve a row
        // belonging to a different campaign.
        return RecruitmentCampaignInvite::query()->where('campaign_id', $this->campaignId);
    }

    protected function columns(): array
    {
        return [
            Column::make(__('recruitment-applicants.invite_full_name'), 'full_name')->sortable()->searchable(),
            Column::make(__('recruitment-applicants.invite_email'), 'email_normalized')->sortable()->searchable(),
            Column::make(__('recruitment-applicants.invite_status'), 'status')
                ->displayUsing(fn ($value, Model $invite): string => $invite->hasDeliveryFailed()
                    ? __('recruitment-applicants.invite_status_failed').' — '.__('recruitment-applicants.invite_failure.'.$invite->failure_reason)
                    : __('recruitment-applicants.invite_status_'.$value))
                ->sortable(),
            Column::make(__('recruitment-applicants.invite_used_at'), 'used_at')
                ->displayUsing(fn ($value) => $value?->format('d-m-Y H:i') ?? '—')
                ->sortable(),
            Column::make(__('general.actions'), function (Model $invite): string {
                if (in_array($invite->status, [RecruitmentCampaignInvite::STATUS_CANCELLED, RecruitmentCampaignInvite::STATUS_USED], true)) {
                    return '';
                }

                return Blade::render(
                    '<div class="flex flex-wrap items-center gap-x-4 gap-y-1">'
                    .'<button type="button" wire:click="sendInvite('.$invite->getKey().')" class="text-primary hover:underline text-body">{{ __(\'recruitment-applicants.invite_\'.($status === \'sent\' || $failed ? \'resend\' : \'send\')) }}</button>'
                    .'<button type="button" wire:click="confirmCancel('.$invite->getKey().')" class="text-primary hover:underline text-body">{{ __(\'recruitment-applicants.invite_cancel\') }}</button>'
                    .'</div>',
                    ['status' => $invite->status, 'failed' => $invite->hasDeliveryFailed()],
                );
            })->asHtml()->clickable(false),
        ];
    }

    protected function filters(): array
    {
        return [
            SelectFilter::make(__('recruitment-applicants.invite_status'), 'status')->options([
                RecruitmentCampaignInvite::STATUS_PENDING => __('recruitment-applicants.invite_status_pending'),
                RecruitmentCampaignInvite::STATUS_SENT => __('recruitment-applicants.invite_status_sent'),
                RecruitmentCampaignInvite::STATUS_USED => __('recruitment-applicants.invite_status_used'),
                RecruitmentCampaignInvite::STATUS_CANCELLED => __('recruitment-applicants.invite_status_cancelled'),
            ]),
        ];
    }

    private function authorizeScreen(): void
    {
        $campaign = HiringCampaign::query()->findOrFail($this->campaignId);

        Gate::authorize('manageForCampaign', [RecruitmentCampaignInvite::class, $campaign]);
    }
}
