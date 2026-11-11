/*
 * Show/hide for password fields (R7). The form theme prints a hidden
 * <button data-password-toggle aria-pressed="false"> next to each password input;
 * without this module the button stays hidden and the field works as usual.
 * The button keeps its name ("Show password") and reports its state with aria-pressed.
 */
for (const button of document.querySelectorAll('[data-password-toggle]')) {
    const input = button.parentElement?.querySelector('input');
    if (!input) {
        continue;
    }

    button.hidden = false;
    button.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
    });
}
