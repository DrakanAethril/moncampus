import { Controller } from '@hotwired/stimulus';

/**
 * Submits the form a field belongs to as soon as that field changes.
 *
 * It exists for the fields that decide **which other fields exist**. On the batch wizard, the
 * program says which options and which saved sets can be offered and the shape says which of them
 * are relevant at all, and both are rendered server-side - so until the form had been submitted
 * once, the group and the options were simply not on the page. Choosing them "before previewing"
 * was not merely awkward, it was impossible: the preview was how you revealed them.
 *
 * A change is a submit rather than a fetch because the whole form is a GET whose answer is the page
 * - "show me who this would deploy to" changes nothing, so re-asking it costs a render and no more.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    submit() {
        this.element.form?.requestSubmit();
    }
}
