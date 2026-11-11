/*
 * Toasts (P7). Loaded on every teacher page (layout_scripts).
 * - Every .toast gets its dismiss button un-hidden; a click removes the toast.
 * - A toast without an action ([data-toast-autohide]) hides after 6 s; the timer pauses while the toast
 *   is hovered or holds focus and restarts in full afterwards (WCAG 2.2.1). A toast with an action stays.
 * Without this module toasts simply stay on the page. Dismissible alerts are handled by alert.js.
 */
const HIDE_AFTER_MS = 6000;

function removeKeepingFocus(element) {
    const hadFocus = element.contains(document.activeElement);
    element.remove();
    if (hadFocus) {
        document.getElementById('main')?.focus();
    }
}

for (const toast of document.querySelectorAll('.toast')) {
    const dismiss = toast.querySelector('[data-toast-dismiss]');
    if (dismiss) {
        dismiss.hidden = false;
        dismiss.addEventListener('click', () => removeKeepingFocus(toast));
    }

    if (!toast.hasAttribute('data-toast-autohide')) {
        continue;
    }

    let hovered = false;
    let focused = false;
    let timer = null;
    const schedule = () => {
        clearTimeout(timer);
        if (!hovered && !focused) {
            timer = setTimeout(() => toast.remove(), HIDE_AFTER_MS);
        }
    };

    toast.addEventListener('pointerenter', () => { hovered = true; schedule(); });
    toast.addEventListener('pointerleave', () => { hovered = false; schedule(); });
    toast.addEventListener('focusin', () => { focused = true; schedule(); });
    toast.addEventListener('focusout', (event) => { focused = toast.contains(event.relatedTarget); schedule(); });
    schedule();
}
