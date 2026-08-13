import { Controller } from '@hotwired/stimulus';

/**
 * Assembles the "Prompt IA pour le format" of the quiz import screen out of the question types the
 * teacher ticked, plus the references of the images they deposited.
 *
 * There is no per-type prompt stored anywhere: one ticked type produces exactly the "prompt for that
 * type", several produce the multi-type one. The reason it is a builder rather than twelve fixed
 * texts is length - the twelve fragments concatenated make a prompt nobody can use, so ticking is
 * what keeps it usable (see design/comparaison/conception_import_quiz_ia.md, section 3).
 *
 * The fragments themselves stay in the template, in French and inline: they are the text sent to the
 * model, not a message about it, and translating them would change what comes back.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'type', 'output', 'count', 'allButton', 'liveButton',
        'contextObjectives', 'contextPhases', 'contextCount',
    ];

    static values = {
        envelope: String,
        closing: String,
        fragments: Object,
        images: Array,
        countTemplate: String,
        imagesTitle: String,
        empty: String,
        // The four assembled variants of the course block, keyed "<objectives><phases>" - built by
        // App\Service\QuizSourceContext and handed over whole. This controller never assembles the
        // block, which is what keeps the counter equal to the text that is sent.
        contextVariants: Object,
        contextMax: Number,
        contextCountTemplate: String,
        contextOverTemplate: String,
    };

    connect() {
        this.refresh();
    }

    // Stimulus Object/Array values are re-parsed on every access - read once into a local
    // (documented gotcha; this controller reads both on every keystroke-free refresh).
    refresh() {
        const fragments = this.fragmentsValue;
        const images = this.imagesValue;
        const checked = this.typeTargets.filter((input) => input.checked);

        this.countTarget.textContent = this.countTemplateValue.replace('%count%', String(checked.length));
        this.allButtonTarget.classList.toggle('is-active', checked.length === this.typeTargets.length);
        this.liveButtonTarget.classList.toggle('is-active', this.matchesLiveOnly(checked));

        if (checked.length === 0) {
            this.outputTarget.textContent = this.emptyValue;
            return;
        }

        const blocks = [this.envelopeValue.trim(), ''];
        blocks.push("# Les types autorisés, et QUAND les employer");
        checked.forEach((input) => {
            const fragment = fragments[input.value];
            if (fragment) {
                blocks.push(fragment.trim());
            }
        });

        if (images.length > 0) {
            blocks.push('', this.imagesTitleValue.trim());
            images.forEach((image) => blocks.push(`${image.ref} = ${image.name}`));
        }

        const context = this.refreshContext();
        if (context !== '') {
            blocks.push('', context);
        }

        blocks.push('', this.closingValue.trim());
        this.outputTarget.textContent = blocks.join('\n');
    }

    /**
     * The course block, and the line that counts it.
     *
     * Nothing is ever cut here: over the cap the line switches to the "over" wording, which names the
     * two levers (untick the déroulé, narrow the scope to one séance), and the whole text still goes
     * into the prompt. A silent truncation would produce questions about the first half of a séquence
     * with nothing on screen to explain why.
     */
    refreshContext() {
        if (!this.hasContextCountTarget) {
            return '';
        }

        const variants = this.contextVariantsValue;
        const key = `${this.isTicked(this.contextObjectivesTarget) ? '1' : '0'}${this.isTicked(this.contextPhasesTarget) ? '1' : '0'}`;
        const text = variants[key] || '';
        const length = [...text].length;

        const over = this.contextMaxValue > 0 && length > this.contextMaxValue;
        this.contextCountTarget.textContent = (over ? this.contextOverTemplateValue : this.contextCountTemplateValue)
            .replace('%count%', String(length))
            .replace('%max%', String(this.contextMaxValue));
        this.contextCountTarget.classList.toggle('is-over', over);

        return text;
    }

    // A box the course has nothing to put in is rendered disabled rather than absent, so the two
    // levers stay visible - a disabled box is never counted as ticked.
    isTicked(input) {
        return input !== undefined && input !== null && input.checked && !input.disabled;
    }

    selectAll() {
        this.typeTargets.forEach((input) => {
            input.checked = true;
        });
        this.refresh();
    }

    /**
     * The live-contest filter is the application's own answer (QuestionType::isAvailableInLiveContest),
     * carried on each row - not a list this file would have to keep in step with the enum.
     */
    selectLive() {
        this.typeTargets.forEach((input) => {
            input.checked = input.dataset.live === '1';
        });
        this.refresh();
    }

    matchesLiveOnly(checked) {
        const live = this.typeTargets.filter((input) => input.dataset.live === '1');

        return checked.length === live.length && checked.every((input) => input.dataset.live === '1');
    }
}
