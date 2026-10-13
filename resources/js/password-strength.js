window.checkPasswordStrength = function (password) {
    const bars = document.querySelectorAll('#passwordStrengthBar span');

    let score = 0;
    if (password.length >= 8) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    // Brand colours only: a weak password is flagged in red, and the bar
    // darkens through the brand greys as it gets stronger.
    const scale = ['bg-primary', 'bg-primary', 'bg-secondary-light', 'bg-secondary-medium', 'bg-secondary-dark'];
    const colorClass = scale[score];

    bars.forEach((bar, index) => {
        bar.classList.remove(...new Set(scale), 'bg-neutral-200');
        bar.classList.add(index < score ? colorClass : 'bg-neutral-200');
    });
}
