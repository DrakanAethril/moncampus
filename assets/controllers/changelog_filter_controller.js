import { Controller } from '@hotwired/stimulus';

// Filters the changelog by kind of change. Everything stays in the DOM - the server sends the whole
// log once and this only hides rows, so switching filters costs nothing and a shared URL still shows
// the same page to everybody.
//
// Two behaviours that are not obvious from the markup:
// - A release whose every entry is filtered out hides itself. Leaving an empty card behind would
//   read as "nothing shipped that day", which is the opposite of the truth.
// - Filtering on "interne" opens the <details> that normally keeps those entries folded. Asking for
//   the technical points and getting a closed block would be a dead end.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['pill', 'release', 'entry', 'internal', 'empty'];
    static values = { active: { type: String, default: '' } };

    filter(event) {
        const asked = event.params.type ?? '';

        // Clicking the active pill clears the filter - one less "Tout" round trip for the mouse.
        this.activeValue = asked === this.activeValue ? '' : asked;
    }

    activeValueChanged() {
        const active = this.activeValue;

        this.entryTargets.forEach((entry) => {
            entry.hidden = active !== '' && entry.dataset.type !== active;
        });

        let visibleReleases = 0;
        this.releaseTargets.forEach((release) => {
            const keeps = release.querySelectorAll('[data-changelog-filter-target="entry"]:not([hidden])').length > 0;
            release.hidden = !keeps;
            if (keeps) {
                visibleReleases += 1;
            }
        });

        this.internalTargets.forEach((details) => {
            details.open = active === 'interne';
        });

        this.pillTargets.forEach((pill) => {
            const mine = (pill.dataset.changelogFilterTypeParam ?? '') === active;
            pill.classList.toggle('is-active', mine);
            pill.setAttribute('aria-pressed', mine ? 'true' : 'false');
        });

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visibleReleases > 0;
        }
    }
}
