/*
 * Add-credits and correct pages (#12, E2, PD6): when the amount (10 / 5 / Other) or the Other number
 * changes, update the "After" number (U+2212 for negatives) and its state class, the pass of the after
 * value (filled units, state class, aria-label from data-credit-pass-label) and, on the grant page,
 * the submit label (data-credit-label-one / -other with "{count}" / -generic). All text comes from
 * data attributes rendered by Twig. Enhancement only: without this module the server-rendered values
 * stay and the form submits as usual. No-op when the elements are missing.
 */
const change = document.querySelector('[data-credit-change]');
const form = change?.closest('form');
const after = change?.querySelector('[data-credit-after]');

if (change && form && after) {
    const base = Number(change.dataset.creditBase);
    const radios = form.querySelectorAll('input[type="radio"][name$="[amount]"]');
    const other = form.querySelector('input[name$="[otherAmount]"]');
    const passBox = change.querySelector('[data-credit-pass]');
    const submit = form.querySelector('[data-credit-submit]');
    const submitLabel = submit?.querySelector('.btn__label');

    const stateOf = (n) => (n >= 3 ? 'ok' : (n >= 1 ? 'low' : 'danger'));
    const print = (n) => (n < 0 ? `−${-n}` : String(n));

    const quantity = () => {
        const chosen = [...radios].find((radio) => radio.checked);
        if (!chosen) {
            return null;
        }
        if (chosen.value !== 'other') {
            return Number(chosen.value);
        }
        const value = Number(other?.value ?? '');
        const max = Number(other?.max || 100);

        return Number.isInteger(value) && value >= 1 && value <= max ? value : null;
    };

    const updatePass = (left) => {
        const pass = passBox?.querySelector('.pass');
        if (!pass) {
            return;
        }
        const units = pass.querySelectorAll('.pass__unit');
        const filled = Math.min(Math.max(left, 0), units.length);
        units.forEach((unit, index) => unit.classList.toggle('pass__unit--filled', index < filled));
        pass.classList.remove('pass--ok', 'pass--low', 'pass--danger');
        pass.classList.add(`pass--${stateOf(left)}`);
        if (passBox.dataset.creditPassLabel) {
            pass.setAttribute('aria-label', passBox.dataset.creditPassLabel.replace('{left}', String(left)));
        }
    };

    const update = () => {
        const q = quantity();
        if (submit && submitLabel) {
            const data = submit.dataset;
            if (q === null) {
                submitLabel.textContent = data.creditLabelGeneric ?? submitLabel.textContent;
            } else if (q === 1) {
                submitLabel.textContent = data.creditLabelOne ?? submitLabel.textContent;
            } else if (data.creditLabelOther) {
                submitLabel.textContent = data.creditLabelOther.replace('{count}', String(q));
            }
        }
        if (q === null) {
            return;
        }
        const left = base + q;
        after.textContent = print(left);
        after.classList.remove('credit-change__number--ok', 'credit-change__number--low', 'credit-change__number--danger');
        after.classList.add(`credit-change__number--${stateOf(left)}`);
        updatePass(left);
    };

    radios.forEach((radio) => radio.addEventListener('change', update));
    other?.addEventListener('input', update);
    update();
}
