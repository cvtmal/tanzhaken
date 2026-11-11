/*
 * Dialogs and sheets (R7, P6). A trigger carries data-dialog-open="<dialog id>".
 * - A submit button of a form: the click is held back, the dialog opens with showModal() and the form is remembered;
 *   a [data-dialog-confirm] button in the dialog closes it and submits that form with the trigger as submitter.
 *   Without this module the trigger simply submits its form (no confirmation, R7).
 * - Any other trigger just opens the dialog.
 * Escape and the close button close it natively; focus then returns to the trigger.
 */
const opened = new WeakMap();

for (const trigger of document.querySelectorAll('[data-dialog-open]')) {
    const dialog = document.getElementById(trigger.dataset.dialogOpen);
    if (!(dialog instanceof HTMLDialogElement) || typeof dialog.showModal !== 'function') {
        continue;
    }

    trigger.addEventListener('click', (event) => {
        const isSubmit = (trigger instanceof HTMLButtonElement || trigger instanceof HTMLInputElement)
            && trigger.type === 'submit';
        const form = isSubmit ? trigger.form : null;
        if (form) {
            event.preventDefault();
        }

        opened.set(dialog, { trigger, form });
        dialog.showModal();
    });
}

for (const dialog of document.querySelectorAll('dialog')) {
    dialog.addEventListener('close', () => {
        opened.get(dialog)?.trigger.focus();
    });

    for (const confirm of dialog.querySelectorAll('[data-dialog-confirm]')) {
        confirm.addEventListener('click', (event) => {
            event.preventDefault();
            const state = opened.get(dialog);
            dialog.close();
            state?.form?.requestSubmit(state.trigger);
        });
    }
}
