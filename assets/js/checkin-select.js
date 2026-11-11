/*
 * Public check-in roster (#14, H1): when a name tile is chosen, the sticky footer reads
 * "Checking in as {label}" with the label in bold. The translated text around the label comes from
 * the status element's data-checkin-status-before/-after, the label from the radio's
 * data-checkin-label; the label goes into a <strong> as text (no HTML from data attributes).
 * Enhancement only: without this module the footer keeps its hint and the form submits as usual.
 */
const status = document.querySelector('[data-checkin-status]');
const inputs = document.querySelectorAll('input[data-checkin-label]');

const show = (input) => {
    if (!status || !input.checked || !input.dataset.checkinLabel) {
        return;
    }
    const name = document.createElement('strong');
    name.textContent = input.dataset.checkinLabel;
    status.replaceChildren(status.dataset.checkinStatusBefore ?? '', name, status.dataset.checkinStatusAfter ?? '');
};

for (const input of inputs) {
    input.addEventListener('change', () => show(input));
    show(input);
}
