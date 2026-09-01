import { Controller } from '@hotwired/stimulus';

/**
 * Assembles the prompt of the survey import assistant out of the question types the author ticked,
 * plus « Ma demande » as they type it.
 *
 * A controller of its own rather than a reuse of quiz-prompt-builder: that one owns an image
 * deposit, a course block with its character budget and the « concours live » filter, none of which
 * a survey has, and its targets are required rather than optional. What the two do share - the shape
 * of the demand block - is shared where it belongs, in PHP: both substitute into a %token% template
 * built by their catalogue, and neither decides which lines exist.
 *
 * The fragments themselves stay in PHP, in French: they are the text sent to the model, not a
 * message about it, and translating them would change what comes back.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['type', 'output', 'count', 'demandField'];

    static values = {
        envelope: String,
        closing: String,
        fragments: Object,
        countTemplate: String,
        empty: String,
        // « Ma demande », as a template with %token% holes plus the bracketed fallbacks - both built
        // by App\Service\Survey\SurveyPromptCatalog. Empty on the transposition path, where there is
        // no demand to state.
        demandTemplate: String,
        demandPlaceholders: Object,
        // The "# Précisions" heading travels with its value: a heading followed by nothing reads to a
        // model as an instruction that went missing.
        demandExtraHeading: String,
    };

    connect() {
        this.refresh();
    }

    // Stimulus Object values are re-parsed on every access - read once into a local (documented
    // gotcha; this runs on every keystroke).
    refresh() {
        const fragments = this.fragmentsValue;
        const checked = this.typeTargets.filter((input) => input.checked);

        this.countTarget.textContent = this.countTemplateValue.replace('%count%', String(checked.length));

        if (checked.length === 0) {
            this.outputTarget.textContent = this.emptyValue;
            return;
        }

        const blocks = [this.envelopeValue.trim(), ''];
        blocks.push('# Les types autorisés, et QUAND les employer');
        checked.forEach((input) => {
            const fragment = fragments[input.value];
            if (fragment) {
                blocks.push(fragment.trim());
            }
        });

        blocks.push('', this.closingValue.trim());

        const demand = this.refreshDemand();
        if (demand !== '') {
            blocks.push('', demand);
        }

        this.outputTarget.textContent = blocks.join('\n');
    }

    /**
     * « Ma demande », filled from the fields on the left as they are typed.
     *
     * The template comes from PHP with %token% holes; all this does is put a value or its bracketed
     * example in each hole, exactly as SurveyPromptCatalog::demandValues() does server-side. A field
     * left alone therefore keeps its example, so the worst case is a prompt that reads as an
     * instruction rather than as a blank.
     */
    refreshDemand() {
        if (this.demandTemplateValue === '') {
            return '';
        }

        const placeholders = this.demandPlaceholdersValue;
        const typed = {};
        this.demandFieldTargets.forEach((field) => {
            typed[field.dataset.demandKey] = field.value.trim();
        });

        let demand = this.demandTemplateValue;
        Object.keys(placeholders).forEach((key) => {
            demand = demand.replace(`%${key}%`, typed[key] || placeholders[key]);
        });

        const extra = (typed.extra || '').trim();
        demand = demand.replace('%extra%', extra === '' ? '' : `\n\n${this.demandExtraHeadingValue}\n${extra}`);

        return demand.trim();
    }

    selectAll() {
        this.typeTargets.forEach((input) => {
            input.checked = true;
        });
        this.refresh();
    }
}
