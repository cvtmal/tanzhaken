/*
 * Dismissible alerts (the _alert "dismissible" option). Loaded on every teacher page (layout_scripts);
 * any other page with a dismissible alert sets page_scripts = ['alert'].
 * Every [data-alert-dismiss] button is un-hidden; a click removes its .alert. A removed alert that held
 * focus moves focus to #main. Without this module the button stays hidden and the alert stays on the page.
 */
for (const button of document.querySelectorAll('[data-alert-dismiss]')) {
    const alert = button.closest('.alert');
    if (!alert) {
        continue;
    }

    button.hidden = false;
    button.addEventListener('click', () => {
        const hadFocus = alert.contains(document.activeElement);
        alert.remove();
        if (hadFocus) {
            document.getElementById('main')?.focus();
        }
    });
}
