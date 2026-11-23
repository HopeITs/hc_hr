{{-- Courses recorded on employee files. A course counts when approved, in progress or finished. --}}
<div class="space-y-4">
    <x-slot:title>{{ __('Training') }}</x-slot:title>

    <x-page-header :title="__('Training')" :lead="__('Courses by category, month and status, cost, and who has had none.')">
        <x-slot:actions>
            <x-button type="button" size="sm" variant="outline" wire:click="export">{{ __('Export to Excel') }}</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="block text-small text-ink-2">
                <span class="mb-1 block">{{ __('Year') }}</span>
                <select wire:model.live="year" class="w-full rounded-lg border border-line bg-card p-2 text-ink" data-testid="training-year">
                    <option value="">{{ __('All years') }}</option>
                    @foreach ($years as $y)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-small text-ink-2">
                <span class="mb-1 block">{{ __('Category') }}</span>
                <select wire:model.live="categoryId" class="w-full rounded-lg border border-line bg-card p-2 text-ink">
                    <option value="">{{ __('All') }}</option>
                    @foreach ($categories as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <p class="mt-2 text-small text-ink-3">{{ __('Courses without a start date are not placed in any year.') }}</p>
    </x-card>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-card :title="__('Courses')"><span class="num text-3xl font-extrabold text-ink" data-testid="training-courses">{{ number_format($r['courses']) }}</span></x-card>
        <x-card :title="__('People trained')">
            <span class="num text-3xl font-extrabold text-ink" data-testid="training-trained">{{ number_format($r['trained']) }}</span>
            <p class="mt-1 text-small text-ink-2">{{ $r['coveragePercent'] === null ? '-' : __(':n% of :m people', ['n' => $r['coveragePercent'], 'm' => $r['people']]) }}</p>
        </x-card>
        <x-card :title="__('Cost')"><span class="num text-3xl font-extrabold text-ink" data-testid="training-cost">{{ number_format($r['cost'], 2) }}</span></x-card>
        <x-card :title="__('With a certificate')"><span class="num text-3xl font-extrabold text-ink">{{ number_format($r['certificates']) }}</span></x-card>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-card :title="__('By category')"><x-report.bars :rows="$r['byCategory']" /></x-card>
        <x-card :title="__('By status')"><x-report.bars :rows="$r['byStatus']" /></x-card>
        <x-card :title="__('By city')"><x-report.bars :rows="$r['byCity']" /></x-card>
        <x-card :title="__('Most frequent providers')">
            @forelse ($r['byOrganization'] as $name => $count)
                <div class="flex items-baseline justify-between gap-2 border-b border-line-soft py-2 text-small last:border-0">
                    <span class="text-ink">{{ $name }}</span><span class="num text-ink-2">{{ $count }}</span>
                </div>
            @empty
                <p class="text-small text-ink-3">{{ __('Nothing to show for this selection.') }}</p>
            @endforelse
        </x-card>
    </div>

    @if ($r['byMonth']->isNotEmpty())
        @php $mMax = max(1, $r['byMonth']->max()); @endphp
        <x-card :title="__('By month')">
            <div class="flex h-32 items-end gap-1" dir="ltr">
                @foreach ($r['byMonth'] as $month => $count)
                    <div class="flex h-full flex-1 items-end" title="{{ $month }}: {{ $count }}">
                        <div class="w-full rounded-t bg-ink" style="height: {{ $count / $mMax * 100 }}%"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-1 flex justify-between text-caption text-ink-3 num" dir="ltr">
                <span>{{ $r['byMonth']->keys()->first() }}</span><span>{{ $r['byMonth']->keys()->last() }}</span>
            </div>
        </x-card>
    @endif

    <x-card :title="__('People with no course in this selection')">
        <p class="mb-2 text-small text-ink-2" data-testid="training-untrained-count">{{ __(':n people', ['n' => $r['untrainedCount']]) }}</p>
        @foreach ($r['untrained']->take(\App\Services\Reports\TrainingReport::UNTRAINED_LIMIT) as $p)
            <div class="flex items-baseline justify-between gap-2 border-b border-line-soft py-2 text-small last:border-0">
                <a href="{{ route('employees.show', $p['username']) }}" class="text-ink underline" wire:navigate>{{ $p['name'] }}</a>
                <span class="text-ink-3">{{ $p['city'] }}</span>
            </div>
        @endforeach
        @if ($r['untrainedCount'] > \App\Services\Reports\TrainingReport::UNTRAINED_LIMIT)
            <p class="mt-2 text-small text-ink-3">{{ __('Showing :a of :b. The Excel file has them all.', ['a' => \App\Services\Reports\TrainingReport::UNTRAINED_LIMIT, 'b' => $r['untrainedCount']]) }}</p>
        @endif
    </x-card>
</div>
