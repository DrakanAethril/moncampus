import { Controller } from '@hotwired/stimulus';

// Pre-fills the "value" field from the selected lesson type's effective cost for this program
// (override if set, else the structure default) - stays editable afterward, this is just a
// starting point for whoever fills the form in.
export default class extends Controller {
    static targets = ['lessonType', 'value'];
    static values = { costs: Object };

    applyDefault() {
        const cost = this.costsValue[this.lessonTypeTarget.value];

        if (cost !== undefined) {
            this.valueTarget.value = cost;
        }
    }
}
