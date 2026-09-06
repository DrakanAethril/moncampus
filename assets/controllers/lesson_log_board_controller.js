import { Controller } from '@hotwired/stimulus';

/**
 * The left column of the cahier de texte's period screen: what is unfolded, and which séance the
 * right-hand block describes.
 *
 * None of it goes back to the server. The whole week is rendered at once - every séance's block is
 * already on the page, hidden - so picking a row swaps two attributes rather than reloading, which
 * is what the handoff asks for ("clic sur une séance : pas de navigation de page").
 *
 * What DOES reload is the period and the grouping, and those are plain links: the first needs
 * séances this page does not hold, the second is remembered server-side for the next visit. The
 * selected séance is written back into the address bar so that following one of those links keeps
 * it - a segmented control that lost the selection would not be "ne change que la structure".
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['classItem', 'dayItem', 'seance', 'detail', 'placeholder'];

    /** Opening a class closes the one that was open: only ever one at a time. */
    toggleClass(event) {
        const item = event.currentTarget.closest('[data-lesson-log-board-target~="classItem"]');
        const opening = !item.classList.contains('is-open');

        this.classItemTargets.forEach((candidate) => this.setOpen(candidate, false));
        this.setOpen(item, opening);
    }

    /** Days fold on their own, unlike classes - several may be open at once. */
    toggleDay(event) {
        const item = event.currentTarget.closest('[data-lesson-log-board-target~="dayItem"]');
        this.setOpen(item, !item.classList.contains('is-open'));
    }

    select(event) {
        const row = event.currentTarget;
        const id = row.dataset.seanceId;

        this.seanceTargets.forEach((candidate) => {
            candidate.classList.toggle('is-selected', candidate === row);
        });
        this.detailTargets.forEach((detail) => {
            detail.hidden = detail.dataset.seanceId !== id;
        });
        // The "nothing selected" card, when the screen opened on a day with no séance.
        this.placeholderTargets.forEach((placeholder) => {
            placeholder.hidden = true;
        });

        this.rememberSelection(id);
    }

    setOpen(item, open) {
        item.classList.toggle('is-open', open);

        const body = item.querySelector('[data-body]');
        if (body) {
            body.hidden = !open;
        }

        const caret = item.querySelector('[data-caret]');
        if (caret) {
            // The characters the handoff draws, rather than a rotated glyph: ▴ open, ▾ closed.
            caret.textContent = open ? '▴' : '▾';
        }
    }

    /**
     * Keeps `?seance=` in step with what is on screen, without adding a history entry - the back
     * button must still leave the screen rather than walk back through every row that was clicked.
     *
     * `date` goes at the same time: it is what opened the screen on one day, and it would otherwise
     * pull the selection back to that day's first séance at the next reload.
     */
    rememberSelection(id) {
        const url = new URL(window.location.href);
        url.searchParams.set('seance', id);
        url.searchParams.delete('date');
        window.history.replaceState({}, '', url);
    }
}
