import { Controller } from '@hotwired/stimulus';

/**
 * The light/dark theme switcher shown in the main header (App\Entity\User::$themePreference) and
 * on the login page (unauthenticated - see security/login.html.twig). Applies instantly client-
 * side (no page reload) and always sets a "theme" cookie so templates/base.html.twig can render
 * the right data-bs-theme server-side on the next request, avoiding a flash of the wrong theme.
 *
 * When authenticatedValue is true, the choice is additionally persisted to the database via
 * App\Controller\ProfileController::updateTheme() - the login page has no user to persist to yet,
 * so it relies on the cookie alone (see base.html.twig's fallback for anonymous visitors).
 *
 * Same X-CSRF-Token header + session-bound csrf_token() pattern as other ajax actions in this app
 * (e.g. datatable_controller.js's performAction()), not the form-only csrf-protection controller.
 */
export default class extends Controller {
    static values = {
        authenticated: Boolean,
        saveUrl: String,
        token: String,
    };

    setDark(event) {
        event.preventDefault();
        this.setTheme('dark');
    }

    setLight(event) {
        event.preventDefault();
        this.setTheme('light');
    }

    toggle(event) {
        event.preventDefault();
        const current = document.documentElement.getAttribute('data-bs-theme');
        this.setTheme(current === 'dark' ? 'light' : 'dark');
    }

    setTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        document.cookie = `theme=${theme}; path=/; max-age=31536000; samesite=lax`;

        if (this.authenticatedValue) {
            fetch(this.saveUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify({ theme }),
            });
        }
    }
}
