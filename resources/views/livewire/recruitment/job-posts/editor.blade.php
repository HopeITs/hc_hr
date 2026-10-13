<x-slot:title>{{ __('recruitment.job_post.title') }}</x-slot:title>

    <div
        data-job-post-editor
    x-data="{ format: 'story', downloading: false, downloadMessage: '' }"
    data-job-title="{{ $jobTitle }}"
    data-font-regular="{{ asset('fonts/SomarSans/SomarSans-Regular.otf') }}"
    data-font-bold="{{ asset('fonts/SomarSans/SOMARSANS-BOLD.OTF') }}"
    data-font-black="{{ asset('fonts/SomarSans/SOMARSANS-BLACK.OTF') }}"
    data-exporting="{{ __('recruitment.job_post.exporting') }}"
    data-downloaded="{{ __('recruitment.job_post.downloaded') }}"
    data-export-failed="{{ __('recruitment.job_post.export_failed') }}"
>
    <livewire:layout.breadcrumbs
        :title="__('recruitment.job_post.title')"
        :crumbs="[
            ['label' => __('recruitment-campaign.campaigns'), 'url' => route('recruitment.campaigns.index')],
            ['label' => __('recruitment-campaign.page.campaign_number', ['id' => $campaign->id]), 'url' => route('recruitment.campaigns.show', ['campaignId' => $campaign->id])],
            ['label' => __('recruitment.job_post.title')],
        ]"
    />

    <x-recruitment.editor-context
        :campaign="$campaign"
        :subtitle="__('recruitment.job_post.subtitle')"
        :version-number="$versionNumber"
        :version-status="$versionStatus"
        :published-version-number="$publishedVersionNumber"
        :just-published="$justPublished"
        :next="$nextStep"
    />

    <div class="grid overflow-hidden rounded-card border border-secondary-soft bg-white shadow-e1 xl:grid-cols-[360px_minmax(0,1fr)]">
        <section class="border-b border-secondary-soft p-5 xl:border-b-0 xl:border-e" aria-label="{{ __('recruitment.job_post.title') }}">
            <div class="space-y-4">
                <div>
                    <x-forms.input-label for="job-post-city" required>{{ __('recruitment.job_post.city') }}</x-forms.input-label>
                    <select id="job-post-city" wire:model.live="city" class="w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary" required>
                        @foreach ($cityOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    <x-error-span>{{ $errors->first('city') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="job-post-program" required>{{ __('recruitment.job_post.project') }}</x-forms.input-label>
                    <select id="job-post-program" wire:model.live="program" class="w-full rounded-control border-secondary-light text-body focus:border-primary focus:ring-primary" required>
                        @foreach ($programOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    <x-error-span>{{ $errors->first('program') }}</x-error-span>
                </div>

                <div>
                    <x-forms.input-label for="job-post-title" required>{{ __('recruitment.job_post.vacancy') }}</x-forms.input-label>
                    <x-forms.input
                        id="job-post-title"
                        model="jobTitle"
                        update="live.debounce.250ms"
                        dir="ltr"
                        required
                    />
                    <x-error-span>{{ $errors->first('jobTitle') }}</x-error-span>
                </div>

            </div>

            <button
                type="button"
                wire:click="resetFromApprovedRequest"
                class="mt-2 cursor-pointer text-small font-semibold text-primary underline-offset-4 hover:underline"
            >
                {{ __('recruitment.job_post.reset') }}
            </button>

            <div class="mt-6 grid grid-cols-2 gap-2" role="group" aria-label="{{ __('recruitment.job_post.preview') }}">
                <button
                    type="button"
                    x-on:click="format = 'story'"
                    x-bind:class="format === 'story' ? 'border-primary bg-primary text-white' : 'border-secondary-light bg-white text-secondary-medium'"
                class="cursor-pointer rounded-control border px-3 py-2 text-body font-semibold transition"
                >
                    {{ __('recruitment.job_post.story') }}
                </button>
                <button
                    type="button"
                    x-on:click="format = 'post'"
                    x-bind:class="format === 'post' ? 'border-primary bg-primary text-white' : 'border-secondary-light bg-white text-secondary-medium'"
                    class="cursor-pointer rounded-control border px-3 py-2 text-body font-semibold transition"
                >
                    {{ __('recruitment.job_post.post') }}
                </button>
            </div>

            <div class="mt-6 grid gap-2">
                <x-primary-button type="button" full wire:click="saveDraft" wire:target="saveDraft">
                    {{ __('recruitment.job_post.save_draft') }}
                </x-primary-button>

                <button
                    type="button"
                    x-on:click="$dispatch('open-recruitment-confirmation', {
                        scope: 'job-post-editor',
                        action: 'publish',
                        message: @js(__('recruitment.job_post.publish_confirm')),
                        confirmLabel: @js(__('recruitment.job_post.publish')),
                    })"
                    class="flex w-full cursor-pointer items-center justify-center rounded-control bg-secondary-dark px-4 py-2 text-body font-semibold text-white shadow-e1 transition hover:bg-secondary-dark"
                >
                    {{ __('recruitment.job_post.publish') }}
                </button>

                @if ($publishedVersionId)
                    <button
                        type="button"
                        x-on:click="$dispatch('open-recruitment-confirmation', {
                            scope: 'job-post-editor',
                            action: 'unpublish',
                            message: @js(__('recruitment.job_post.unpublish_confirm')),
                            confirmLabel: @js(__('recruitment.job_post.unpublish')),
                            tone: 'danger',
                        })"
                    class="flex w-full cursor-pointer items-center justify-center rounded-control border border-primary bg-white px-4 py-2 text-body font-semibold text-primary transition hover:bg-secondary-soft/30"
                    >
                        {{ __('recruitment.job_post.unpublish') }}
                    </button>
                @endif

                <button
                    type="button"
                    x-bind:disabled="downloading"
                    x-on:click="
                        downloading = true;
                        downloadMessage = $root.dataset.exporting;
                        window.HopeCenterJobPostExporter.downloadBoth($root)
                            .then(() => downloadMessage = $root.dataset.downloaded)
                            .catch(() => downloadMessage = $root.dataset.exportFailed)
                            .finally(() => downloading = false);
                    "
                    class="flex w-full cursor-pointer items-center justify-center rounded-control border border-primary bg-white px-4 py-2 text-body font-semibold text-primary transition hover:bg-primary/5 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <svg x-show="downloading" class="me-2 size-4 animate-spin" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
                    </svg>
                    {{ __('recruitment.job_post.download_both') }}
                </button>
            </div>

            <p class="mt-3 text-small text-secondary-medium">{{ __('recruitment.job_post.download_help') }}</p>

            @if ($hasConflict)
                <button type="button" wire:click="reloadLatest" class="mt-4 cursor-pointer text-small font-semibold text-primary underline">
                    {{ __('recruitment.job_post.reload_latest') }}
                </button>
            @endif

            <p x-show="downloadMessage" x-text="downloadMessage" class="mt-3 text-small text-secondary-medium" role="status" aria-live="polite"></p>
        </section>

        <section class="flex min-h-[640px] flex-col bg-secondary-soft/60 p-4 sm:p-6" aria-label="{{ __('recruitment.job_post.preview') }}">
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <h2 class="text-subtitle font-bold text-secondary-dark">{{ __('recruitment.job_post.preview') }}</h2>
                    <p class="text-small text-secondary-medium">{{ __('recruitment.job_post.exact_template') }}</p>
                </div>
                <span class="rounded-full bg-white px-3 py-1 text-small font-semibold text-secondary-medium shadow-e1" x-text="format === 'story' ? '2160 × 3840' : '2160 × 2160'"></span>
            </div>

            <div class="flex min-h-0 flex-1 items-center justify-center overflow-auto rounded-control">
                <div x-show="format === 'story'" x-cloak class="flex h-full w-full items-center justify-center">
                    <x-recruitment.hope-center-job-post-svg
                        id="hc-story-svg"
                        format="story"
                        :city="$city"
                        :program="$program"
                        :job-title="$jobTitle"
                        class="block max-h-[72vh] w-auto max-w-full shadow-[0_10px_40px_rgba(88,89,91,.28)]"
                    />
                </div>

                <div x-show="format === 'post'" x-cloak class="flex h-full w-full items-center justify-center">
                    <x-recruitment.hope-center-job-post-svg
                        id="hc-post-svg"
                        format="post"
                        :city="$city"
                        :program="$program"
                        :job-title="$jobTitle"
                        class="block max-h-[72vh] w-auto max-w-full shadow-[0_10px_40px_rgba(88,89,91,.28)]"
                    />
                </div>
            </div>
        </section>
    </div>
    <x-recruitment.confirmation-modal scope="job-post-editor" />
</div>

@script
<script>
    window.HopeCenterJobPostExporter ??= (() => {
        const resourceCache = new Map();

        const asDataUrl = async (url) => {
            if (resourceCache.has(url)) {
                return resourceCache.get(url);
            }

            const promise = fetch(url, { credentials: 'same-origin' })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`Unable to load export resource: ${url}`);
                    }

                    return response.blob();
                })
                .then((blob) => new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onload = () => resolve(reader.result);
                    reader.onerror = reject;
                    reader.readAsDataURL(blob);
                }));

            resourceCache.set(url, promise);

            return promise;
        };

        const inlineSvgResources = async (source, root) => {
            const svg = source.cloneNode(true);
            const images = [...svg.querySelectorAll('image')];

            await Promise.all(images.map(async (image) => {
                const href = image.getAttribute('href');
                if (href && !href.startsWith('data:')) {
                    image.setAttribute('href', await asDataUrl(new URL(href, window.location.href).href));
                }
            }));

            const [regular, bold, black] = await Promise.all([
                asDataUrl(root.dataset.fontRegular),
                asDataUrl(root.dataset.fontBold),
                asDataUrl(root.dataset.fontBlack),
            ]);
            const namespace = 'http://www.w3.org/2000/svg';
            const definitions = document.createElementNS(namespace, 'defs');
            const style = document.createElementNS(namespace, 'style');
            style.textContent = [
                `@font-face{font-family:'SomarSans';font-weight:400;font-style:normal;src:url("${regular}") format('opentype');}`,
                `@font-face{font-family:'SomarSans';font-weight:700;font-style:normal;src:url("${bold}") format('opentype');}`,
                `@font-face{font-family:'SomarSans';font-weight:900;font-style:normal;src:url("${black}") format('opentype');}`,
            ].join('');
            definitions.appendChild(style);
            svg.prepend(definitions);

            return new XMLSerializer().serializeToString(svg);
        };

        const renderPng = async (svg, root) => {
            const serialized = await inlineSvgResources(svg, root);
            const svgUrl = URL.createObjectURL(new Blob([serialized], { type: 'image/svg+xml;charset=utf-8' }));

            try {
                const image = await new Promise((resolve, reject) => {
                    const target = new Image();
                    target.onload = () => resolve(target);
                    target.onerror = () => reject(new Error('The SVG preview could not be rendered.'));
                    target.src = svgUrl;
                });
                const canvas = document.createElement('canvas');
                canvas.width = Number(svg.dataset.exportWidth);
                canvas.height = Number(svg.dataset.exportHeight);
                const context = canvas.getContext('2d');
                context.drawImage(image, 0, 0, canvas.width, canvas.height);

                return await new Promise((resolve, reject) => {
                    canvas.toBlob(
                        (blob) => blob ? resolve(blob) : reject(new Error('PNG encoding failed.')),
                        'image/png',
                    );
                });
            } finally {
                URL.revokeObjectURL(svgUrl);
            }
        };

        const safeFilePart = (value) => {
            const cleaned = (value || 'Hiring')
                .trim()
                .replace(/[<>:"/\\|?*\u0000-\u001F]/g, '')
                .replace(/\s+/g, '_');

            return cleaned || 'Hiring';
        };

        const download = (blob, filename) => {
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        };

        const pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

        return {
            async downloadBoth(root) {
                const filePart = safeFilePart(root.dataset.jobTitle);
                const story = root.querySelector('#hc-story-svg');
                const post = root.querySelector('#hc-post-svg');

                download(await renderPng(story, root), `HopeCenter_${filePart}_story.png`);
                await pause(500);
                download(await renderPng(post, root), `HopeCenter_${filePart}_post.png`);
            },
        };
    })();
</script>
@endscript
