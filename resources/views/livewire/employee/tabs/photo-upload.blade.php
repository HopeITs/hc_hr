
{{--@if ($form->photo instanceof Livewire\Features\SupportFileUploads\TemporaryUploadedFile)--}}
{{--    <img class="h-20 w-20 rounded-full object-cover" src="{{ $form->photo->temporaryUrl() }}" alt="New profile photo" />--}}
{{--@elseif ($form->employee?->photo)--}}
{{--    <img class="h-20 w-20 rounded-full object-cover" src="{{ asset('storage/' . $form->employee->photo) }}" alt="Current profile photo" />--}}
{{--@else--}}
{{--    <div class="h-20 w-20 rounded-full bg-surface flex items-center justify-center">--}}
{{--        <svg class="h-12 w-12 text-ink-3" xmlns="http://www.w3.org/2000/svg" fill="none"--}}
{{--            viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">--}}
{{--            <path stroke-linecap="round" stroke-linejoin="round"--}}
{{--                d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />--}}
{{--        </svg>--}}
{{--    </div>--}}
{{--@endif--}}
