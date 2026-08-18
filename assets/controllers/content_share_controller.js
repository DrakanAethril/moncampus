import { Controller } from '@hotwired/stimulus';

/**
 * The share modal's two live behaviours (design/validated/content-sharing-between-teachers.md).
 *
 * 1. The scope radios show the picker that scope needs, and only that one. A scope carries the
 *    audience it names and no other: what the teacher last read on screen is the sentence under the
 *    radio they chose, and the server drops the other list for the same reason.
 * 2. **The resolved member count is stated before the submit** - « ce partage sera visible de
 *    87 personnes ». The hierarchy's root is « campus », so ticking it shares with the whole
 *    establishment while looking like a small gesture, and the only honest place to say so is next
 *    to the picker. The figure is measured server-side, never guessed here from the tree.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['usersPanel', 'groupsPanel', 'groupCount'];

    static values = {
        countUrl: String,
        countLabel: String,
    };

    connect() {
        this.scopeChanged();
    }

    scopeChanged() {
        const scope = this.element.querySelector('input[name="scope"]:checked')?.value ?? 'users';

        // `hidden` alone loses to Bootstrap's `.d-flex` and friends, which carry !important - these
        // two panels are plain blocks, so the attribute is enough and no utility class is involved.
        this.usersPanelTarget.hidden = scope !== 'users';
        this.groupsPanelTarget.hidden = scope !== 'group';

        if (scope === 'group') {
            this.groupsChanged();
        }
    }

    groupsChanged() {
        const ids = [...this.element.querySelectorAll('input[name="groups[]"]:checked')].map((box) => box.value);

        if (ids.length === 0) {
            this.groupCountTarget.hidden = true;

            return;
        }

        const url = new URL(this.countUrlValue, window.location.origin);
        ids.forEach((id) => url.searchParams.append('groups[]', id));

        // Read once into a local: a Stimulus value re-parses on every access, and this one is used
        // inside the callback below.
        const label = this.countLabelValue;

        fetch(url)
            .then((response) => response.json())
            .then((data) => {
                this.groupCountTarget.textContent = label.replace('__COUNT__', String(data.count));
                this.groupCountTarget.hidden = false;
            })
            // A transient failure must not leave a stale figure on screen: the count is the whole
            // point of the line, and a wrong one is worse than none.
            .catch(() => {
                this.groupCountTarget.hidden = true;
            });
    }
}
