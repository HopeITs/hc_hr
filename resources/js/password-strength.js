window.checkPasswordStrength = function (password) {
    const bars = document.querySelectorAll('#passwordStrengthBar span');

    let score = 0;
    if (password.length >= 12) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    let colorClass = 'bg-red-500';
    if (score === 2) colorClass = 'bg-yellow-400';
    else if (score === 3) colorClass = 'bg-blue-400';
    else if (score === 4) colorClass = 'bg-green-500';

    bars.forEach((bar, index) => {
        bar.classList.remove('bg-red-500', 'bg-yellow-400', 'bg-blue-400', 'bg-green-500', 'bg-gray-200');
        bar.classList.add(index < score ? colorClass : 'bg-gray-200');
    });
}
