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
 *
 * The form is found with `closest`, because the two binding shapes in use are both legitimate and
 * only one of them has a `.form` property: `data-controller` sits on the field itself (the batch
 * wizard's two deciding lists), or on the <form> of a whole filter bar whose every control submits
 * it. `this.element.form` reads undefined on a <form> - and on the wrapping <div> of a switch -
 * which silently disabled every filter bar on the platform, progressions included.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    submit() {
        this.element.closest('form')?.requestSubmit();
    }
}
