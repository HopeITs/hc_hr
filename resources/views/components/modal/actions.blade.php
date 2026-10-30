{{--
    The Save/Cancel row of an editable dialog (M1). On desktop it is an ordinary right-aligned row.
    On a phone sheet it is pinned to the bottom of the screen, above the safe area, so the submit control
    stays reachable while a long form scrolls; the modal root's scroll padding (sheet:scroll-pb-28) keeps a
    focused field clear of it, and `.modal-body:has([data-sheet-actions])` drops the body padding under it.
    Buttons share the width on phones. Put it as the last child of a `sheet:flex-1 sheet:flex sheet:flex-col`
    form, or directly in the dialog body.
--}}
<div data-sheet-actions {{ $attributes->class([
    'flex justify-end gap-2 pt-4',
    'sheet:mt-auto sheet:sticky sheet:bottom-0 sheet:z-10 sheet:-mx-4 sheet:border-t sheet:border-line-soft sheet:bg-card sheet:px-4 sheet:pt-3 sheet:pb-[max(0.75rem,env(safe-area-inset-bottom))]',
    'sheet:[&>*]:flex-1',
]) }}>
    {{ $slot }}
</div>
