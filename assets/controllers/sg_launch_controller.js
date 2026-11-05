import { Controller } from '@hotwired/stimulus';

/**
 * Launches a serious game full screen (the user's decision of 2026-10-18,
 * design/validated/serious-games.md §6.1).
 *
 * A browser grants its full screen only from a user gesture, and the gesture is the « Jouer »
 * click - lost once the game's page has loaded. So the click itself asks for it, on the whole
 * document, before Turbo carries the player to the game; the host page then lays the game over the
 * whole screen (serious_game_host_controller.js), which it does anyway when the browser refused.
 *
 *   <div data-controller="sg-launch"> … <a href="/serious-games/play/…" data-action="sg-launch#enter">Jouer</a>
 */
const SMALLEST_SCREEN = 1100;

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    // A game played on a computer only needs 1 100 px (.cm-sg-card--desktop). What decides is the
    // screen the game will fill, not this window: an installed app (beaup-app) often opens narrower
    // than its screen, and the game goes full screen anyway.
    connect() {
        const largest = Math.max(window.screen?.width ?? 0, window.screen?.height ?? 0);
        this.element.classList.toggle('cm-sg-small-screen', largest > 0 && largest < SMALLEST_SCREEN);
    }

    enter(event) {
        if (this.element.classList.contains('cm-sg-small-screen') && event.currentTarget.closest('.cm-sg-card--desktop')) {
            event.preventDefault();
            return;
        }
        // A click that opens elsewhere (a new tab, a download) is not a launch.
        if (event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const root = document.documentElement;
        if (document.fullscreenElement ?? document.webkitFullscreenElement) {
            return;
        }

        const request = root.requestFullscreen ?? root.webkitRequestFullscreen;
        Promise.resolve(request?.call(root)).catch(() => {});
    }
}
