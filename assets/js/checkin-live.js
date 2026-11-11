/*
 * Live teacher QR page (checkin_manage, #16, D10). Loaded by checkin/manage/_shell.html.twig (page_scripts).
 * Does nothing unless the open state's root [data-checkin-live] is on the page. Every text comes from the
 * page (catalogue strings rendered by Twig), so no English lives here (S7).
 * - Polls [data-checkin-status-url] every 5 s (S8), skips while the tab is hidden, waits 15 s after an error;
 *   updates the count ([data-checkin-count] from its data-count-template, %checked% / %enrolled%) and the dots
 *   ([data-checkin-dots]); on state "closed" reloads the page once, so the server renders the closed state.
 * - Countdown: [data-checkin-countdown] ("Closes at 7:42 PM" without JS) becomes a ticking mm:ss from
 *   data-checkin-expires-at, corrected by the status's secondsLeft; its "until it closes" label is un-hidden.
 * - Copy: [data-checkin-copy] is un-hidden only when the Clipboard API exists; it copies [data-checkin-url]
 *   and shows data-copied-label for a moment. Without it the readonly input stays selectable.
 * - Full screen: [data-checkin-fullscreen] is un-hidden only when requestFullscreen exists; it toggles.
 */
const POLL_MS = 5000;
const ERROR_MS = 15000;
const COPIED_MS = 2000;
const MAX_DOTS = 40;

const root = document.querySelector('[data-checkin-live]');

if (root) {
    const statusUrl = root.dataset.checkinStatusUrl;
    const count = root.querySelector('[data-checkin-count]');
    const dots = root.querySelector('[data-checkin-dots]');
    const countdown = root.querySelector('[data-checkin-countdown]');
    const countdownLabel = root.querySelector('[data-checkin-countdown-label]');
    const numbers = new Intl.NumberFormat(document.documentElement.lang || undefined);

    let deadline = Date.parse(root.dataset.checkinExpiresAt ?? '');
    let pollTimer = null;
    let reloading = false;
    let zeroPolled = false;

    const pad = (value) => String(value).padStart(2, '0');

    const tick = () => {
        if (!countdown || Number.isNaN(deadline)) {
            return;
        }
        const left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
        countdown.textContent = `${pad(Math.floor(left / 60))}:${pad(left % 60)}`;
        if (left === 0 && !zeroPolled) {
            zeroPolled = true;
            pollNow();
        }
    };

    const renderCount = (checkedIn, enrolled) => {
        const template = count?.dataset.countTemplate;
        if (template) {
            count.textContent = template
                .replace('%checked%', numbers.format(checkedIn))
                .replace('%enrolled%', numbers.format(enrolled));
        }
    };

    const renderDots = (checkedIn, enrolled) => {
        if (!dots) {
            return;
        }
        if (enrolled !== dots.children.length && enrolled <= MAX_DOTS) {
            dots.replaceChildren(...Array.from({length: enrolled}, () => {
                const dot = document.createElement('span');
                dot.className = 'checkin-dots__dot';
                return dot;
            }));
        }
        Array.from(dots.children).forEach((dot, index) => {
            dot.classList.toggle('checkin-dots__dot--on', index < checkedIn);
        });
    };

    const schedule = (delay) => {
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(poll, delay);
    };

    async function poll() {
        if (reloading) {
            return;
        }
        if (document.hidden) {
            schedule(POLL_MS);
            return;
        }
        try {
            const response = await fetch(statusUrl, {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const status = await response.json();
            if (status.state === 'closed') {
                reloading = true;
                window.location.reload();
                return;
            }
            if (typeof status.secondsLeft === 'number') {
                deadline = Date.now() + status.secondsLeft * 1000;
                zeroPolled = status.secondsLeft === 0;
            }
            renderCount(status.checkedIn, status.enrolled);
            renderDots(status.checkedIn, status.enrolled);
            schedule(POLL_MS);
        } catch {
            schedule(ERROR_MS);
        }
    }

    function pollNow() {
        if (!reloading && !document.hidden) {
            schedule(0);
        }
    }

    if (countdown && !Number.isNaN(deadline)) {
        countdown.classList.add('is-ticking');
        if (countdownLabel) {
            countdownLabel.hidden = false;
        }
        tick();
        window.setInterval(tick, 1000);
    }

    if (statusUrl) {
        schedule(POLL_MS);
    }

    const copy = root.querySelector('[data-checkin-copy]');
    const url = root.querySelector('[data-checkin-url]');
    if (copy && url && navigator.clipboard?.writeText) {
        const label = copy.querySelector('.btn__label') ?? copy;
        const original = label.textContent;
        let resetTimer = null;
        copy.hidden = false;
        copy.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(url.value);
                label.textContent = copy.dataset.copiedLabel ?? original;
                window.clearTimeout(resetTimer);
                resetTimer = window.setTimeout(() => {
                    label.textContent = original;
                }, COPIED_MS);
            } catch {
                url.select();
            }
        });
    }

    const fullscreen = root.querySelector('[data-checkin-fullscreen]');
    if (fullscreen && document.documentElement.requestFullscreen) {
        fullscreen.hidden = false;
        fullscreen.addEventListener('click', () => {
            if (document.fullscreenElement) {
                document.exitFullscreen?.();
            } else {
                document.documentElement.requestFullscreen().catch(() => {});
            }
        });
    }
}
