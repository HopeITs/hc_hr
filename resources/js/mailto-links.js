// Every mailto: link, anywhere, opens a new message in the mail program.
//
// Left to the browser, a mailto: click only worked on some pages: inside the
// dashboard's cards and tabs (same-site iframes) the frame itself tried to
// navigate to mailto:, which the browser drops silently, and Livewire or other
// click handlers could swallow it on the way. Handing the address to the top
// window launches the mail program from there and leaves every page in place.
document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target instanceof Element ? e.target.closest('a[href]') : null;
    const href = a?.getAttribute('href')?.trim();
    if (! href || ! /^mailto:/i.test(href)) return;

    e.preventDefault();
    let win = window;
    try {
        // Same site, so the top window is reachable; a foreign parent is not.
        void window.top.location.href;
        win = window.top;
    } catch (err) {
        win = window;
    }
    win.location.href = href;
});
