// Delegated from the document, so toggles on pages swapped in by wire:navigate
// (where DOMContentLoaded never fires again) work without re-binding.
document.addEventListener("click", function (event) {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;

    const inputId = button.getAttribute("data-password-toggle");
    const input = document.getElementById(inputId);
    const icon = document.getElementById(inputId + "_icon");

    if (input.type === "password") {
        input.type = "text";
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    } else {
        input.type = "password";
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    }
});
