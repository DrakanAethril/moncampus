import { Controller } from '@hotwired/stimulus';

// Bound to a plain GET <form> (the Alternances dashboard's filter bar) - each filter control gets
// data-action="change->auto-submit#submit" so picking a value reloads the page with the new query
// string, no ajax/DataTables involved (the dashboard is explicitly unpaginated - see the feature's
// plan doc, §5).
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
