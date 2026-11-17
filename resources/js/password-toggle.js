// Delegated from the document, so toggles on pages swapped in by wire:navigate
// (where DOMContentLoaded never fires again) work without re-binding.
document.addEventListener("click", function (event) {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;

    const inputId = button.getAttribute("data-password-toggle");
    const input = document.getElementById(inputId);

    input.type = input.type === "password" ? "text" : "password";

    button.querySelector('[data-password-icon="hidden"]').classList.toggle("hidden", input.type === "text");
    button.querySelector('[data-password-icon="shown"]').classList.toggle("hidden", input.type === "password");
});
