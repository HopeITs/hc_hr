{{-- Same path as Escape and the header close: closes locally, or asks the dialog's close guard. --}}
<x-primary-button :showSpinner="false" type="button"  x-on:click="close()" class="!bg-secondary-light !w-auto">@lang('general.cancel')</x-primary-button>
