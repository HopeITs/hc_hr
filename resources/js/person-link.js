// Any person's name (<x-person>) opens their profile drawer. In the capture
// phase, so a click on the name never also fires the table row or card around it.
document.addEventListener('click', (event) => {
    const person = event.target instanceof Element && event.target.closest('[data-person]');
    if (!person) return;

    event.preventDefault();
    event.stopPropagation();
    hideCard();
    window.dispatchEvent(new CustomEvent('open-profile', { detail: { employeeId: Number(person.dataset.person) } }));
}, true);

// Resting the mouse on a name shows a small card about the person. Only with a
// real pointer: on a phone the tap opens the drawer. The data is fetched once
// per person and built with textContent, so nothing in it can run as markup.
const HOVER_DELAY = 300;
const cards = new Map();
let box = null;
let timer = null;
let current = null;

function hideCard() {
    clearTimeout(timer);
    current = null;
    if (box) box.hidden = true;
}

function load(id) {
    if (!cards.has(id)) {
        const base = document.documentElement.lang === 'ar' ? '' : `/${document.documentElement.lang}`;
        cards.set(id, fetch(`${base}/people/${id}/card`, { headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : null))
            .catch(() => null));
    }

    return cards.get(id);
}

function fill(data) {
    box ??= Object.assign(document.body.appendChild(document.createElement('div')), {
        className: 'fixed z-[60] flex w-72 items-center gap-3 rounded-surface border border-line bg-card p-3 text-start text-ink shadow-e3',
        role: 'tooltip',
    });
    box.dataset.testid = 'person-card';

    const photo = Object.assign(document.createElement('img'), { src: data.avatar, alt: '', className: 'size-14 shrink-0 rounded-full object-cover' });
    const text = document.createElement('div');
    text.className = 'min-w-0';
    const line = (value, className) => {
        const el = document.createElement('p');
        el.className = className;
        el.dir = 'auto';
        el.textContent = value;
        text.append(el);
    };
    line(data.name, 'truncate text-body font-bold');
    if (data.title) line(data.title, 'truncate text-small text-ink-2');
    if (data.details.length) line(data.details.join(' · '), 'truncate text-caption text-ink-3');

    box.replaceChildren(photo, text);
}

function place(anchor) {
    box.hidden = false;
    const name = anchor.getBoundingClientRect();
    const card = box.getBoundingClientRect();
    const below = name.bottom + 8 + card.height <= window.innerHeight;

    box.style.top = `${below ? name.bottom + 8 : Math.max(8, name.top - card.height - 8)}px`;
    box.style.left = `${Math.min(Math.max(8, name.left), window.innerWidth - card.width - 8)}px`;
}

document.addEventListener('pointerover', (event) => {
    if (event.pointerType !== 'mouse') return;
    const person = event.target instanceof Element && event.target.closest('[data-person]');
    if (!person || person === current) return;

    hideCard();
    current = person;
    timer = setTimeout(async () => {
        const data = await load(person.dataset.person);
        if (data && current === person && person.isConnected) {
            fill(data);
            place(person);
        }
    }, HOVER_DELAY);
});

document.addEventListener('pointerout', (event) => {
    if (current && !current.contains(event.relatedTarget)) hideCard();
});

document.addEventListener('scroll', hideCard, true);
document.addEventListener('livewire:navigating', hideCard);
