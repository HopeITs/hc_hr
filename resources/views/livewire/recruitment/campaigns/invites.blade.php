<x-slot:title>{{ __('recruitment-applicants.invites_title') }}</x-slot:title>

<div class="space-y-6">
    <livewire:layout.breadcrumbs :title="__('recruitment-applicants.invites_title')" :crumbs="[['label' => __('recruitment.navigation_section')], ['label' => __('recruitment-campaign.campaigns'), 'route' => 'recruitment.campaigns.index'], ['label' => __('recruitment-applicants.invites_title')]]" />
    <p class="-mt-4 text-secondary-medium">{{ __('recruitment-applicants.invites_description') }}</p>


    <form wire:submit="add" class="flex flex-wrap items-end gap-4 rounded-control border border-secondary-soft bg-white p-4 shadow-e1">
        <div class="min-w-[220px]">
            <x-forms.input-label for="full_name" required :value="__('recruitment-applicants.invite_full_name')" />
            <x-forms.input id="full_name" model="full_name" required />
            <x-forms.input-error field="full_name" input-id="full_name" />
        </div>

        <div class="min-w-[260px]">
            <x-forms.input-label for="email" required :value="__('recruitment-applicants.invite_email')" />
            <x-forms.input id="email" type="email" model="email" required />
            <x-forms.input-error field="email" input-id="email" />
        </div>

        <x-primary-button type="submit">
            {{ __('recruitment-applicants.invite_add') }}
        </x-primary-button>
    </form>

    <div class="flex flex-wrap items-center gap-3">
        <button type="button" wire:click="sendPending" wire:loading.attr="disabled" wire:target="sendPending"
            class="rounded-control border border-secondary-light px-4 py-2 text-small font-semibold text-secondary-dark hover:bg-secondary-soft/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
            {{ __('recruitment-applicants.invite_send_pending') }}
        </button>
        <x-forms.input-error field="send" />
    </div>

    <livewire:recruitment.campaigns.invite-table :campaign-id="$campaign->id" />
</div>
