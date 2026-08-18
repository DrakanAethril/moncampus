import { Controller } from '@hotwired/stimulus';

/**
 * The one question the séance duplication asks: where does it go?
 *
 * A séance always lives in a séquence (`SeanceTemplate::$sequenceTemplate` is `nullable: false`), so
 * the recipient names one - or asks for a new one bearing the séance's own title. This only shows
 * the select when the first answer is the one chosen; the server drops the other either way.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['existingPanel'];

    connect() {
        this.targetChanged();
    }

    targetChanged() {
        const choice = this.element.querySelector('input[name="target"]:checked')?.value ?? 'existing';

        // A plain block, so the `hidden` attribute is enough - no Bootstrap display utility is in
        // play here, and those carry !important.
        this.existingPanelTarget.hidden = choice !== 'existing';
    }
}
