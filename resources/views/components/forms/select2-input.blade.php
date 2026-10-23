@props(['disabled' => false, 'hideSearch' => false])

<div class="w-full" 
    x-data="{
        value: @entangle($attributes->wire('model')),
        // The one place a value is normalized: '' and null both mean 'nothing
        // chosen', and ids compare as strings (the server may hold 13, the DOM '13').
        normalize(value) {
            return value === null || value === undefined || value === '' ? null : String(value);
        },
        // A placeholder <option> without value='' reports its own text ('Select',
        // 'اختيار') as its value, which then reached the server as an id.
        chosen(select) {
            let option = select[0].selectedOptions[0];
            return option && option.getAttribute('value') ? option.value : null;
        },
        show(select, value) {
            value = this.normalize(value);
            if (value === null) {
                // Show the placeholder rather than an empty box.
                let options = [...select[0].options];
                select[0].selectedIndex = options.findIndex(o => ! o.getAttribute('value'));
            } else {
                select.val(value);
            }
            select.trigger('change.select2');
        },
        initSelect2() {
            let select = $(this.$refs.select);

            // Inside a modal, render the dropdown into the modal root: on <body>
            // it would sit outside the dialog's focus trap and its search box
            // could never keep focus.
            let modalRoot = $(this.$el).closest('[data-modal-root]').children('[data-modal-dropdowns]');

            select.select2({
                width: '100%',
                dropdownParent: modalRoot.length ? modalRoot : $(document.body),
                dir: document.documentElement.dir || 'rtl',
                minimumResultsForSearch: {{ $hideSearch ? 'Infinity' : 0 }}, // hide search only when hideSearch is set
                templateSelection: function (data) {
                    // This prevents long text from breaking the layout
                    return data.text;
                }
            });


            this.show(select, this.value);

            // select2 names its combobox only by the current value (e.g. Select),
            // so the field's own <label for> never reaches a screen reader.
            // Name it by the label first, then the value.
            let label = select.attr('id') && document.querySelector(`label[for='${select.attr('id')}']`);
            let selection = select.next('.select2').find('.select2-selection');
            if (label && selection.length) {
                label.id ||= `${select.attr('id')}-label`;
                let rendered = selection.find('.select2-selection__rendered').attr('id');
                selection.attr('aria-labelledby', [label.id, rendered].filter(Boolean).join(' '));
            }
            

            select.prop('disabled', {{ $disabled ? 'true' : 'false' }});


            select.on('change', () => {
                let chosen = this.chosen(select);
                if (chosen !== this.normalize(this.value)) {
                    this.value = chosen;
                }
            });

            this.$watch('value', (newValue) => {
                if (this.chosen(select) !== this.normalize(newValue)) {
                    this.show(select, newValue);
                }
            });


            select.on('select2:select', () => {
                $('.select2-selection__rendered').removeAttr('title');
            });
            $('.select2-selection__rendered').removeAttr('title');
        }
    }" 
    x-init="initSelect2()"
>
    <div wire:ignore class="w-full">
        <select x-ref="select" {{ $attributes->whereDoesntStartWith('wire:model')->merge(['disabled' => $disabled]) }}>
            {{ $slot }}
        </select>
    </div>
</div> 