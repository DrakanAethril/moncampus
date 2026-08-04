import { Controller } from '@hotwired/stimulus';

// Same rule as App\Util\BlankTextParser::BLANK_PATTERN - three or more dots, or the single ellipsis
// character editors substitute for them, counts as one blank. The two must never diverge: the
// counter shown here and the blanks the server grades against are meant to be the same thing.
const BLANK_PATTERN = /\.{3,}|…+/;

/**
 * Screens 2a/2b - the "Texte à trous" half of the question editor (1b). Owns everything that
 * depends on the statement's blanks: the highlighted "..." chips behind the textarea, the
 * "n trous détectés" counter, one answer field per blank in banque mode, one variant-chip group per
 * blank in libre mode, and the distractor chips.
 *
 * Kept apart from quiz_question_editor_controller.js on purpose: that one drives the QuizAnswer row
 * list, which a texte à trous has none of (its whole definition is one JSON column - see
 * App\Entity\QuizQuestionDefinitionTrait). The two only meet through the shared Type <select>, which decides
 * which of the two panels is on screen.
 *
 * Everything is submitted as raw blanks[...] request fields, resolved server-side by
 * App\Controller\QuizLibraryController::applyBlanks() - same reasoning as the answers rows.
 *
 * The blank count is *always* recomputed from the text, never stored: a teacher who inserts a "..."
 * in the middle of an existing statement must see the new blank appear in the right position, with
 * the answers already typed staying attached to the blanks they were written for (by index).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'highlight', 'count', 'answers', 'answersHint', 'distractors', 'distractorChips', 'libreOptions', 'modeInput'];
    static values = {
        blankLabel: String,
        countTemplate: String,
        countEmptyText: String,
        variantAddLabel: String,
        distractorAddLabel: String,
        answerPlaceholder: String,
        wordPlaceholder: String,
        answersHintBanque: String,
        answersHintLibre: String,
        // Saved state, re-read on connect so a validation re-render never loses what was typed.
        answers: Array,
        distractorList: Array,
    };

    connect() {
        // Stimulus Array/Object values are re-parsed from their DOM attribute on every access and
        // never cached - reading one in a loop silently rebuilds the array each time. Copy once.
        this.blankAnswers = (this.answersValue || []).map((variants) => [...variants]);
        this.distractors = [...(this.distractorListValue || [])];

        this.refresh();
    }

    // The single entry point: re-reads the text, then rebuilds every part that depends on it.
    refresh() {
        const blankCount = this.syncHighlight();

        while (this.blankAnswers.length < blankCount) {
            this.blankAnswers.push([]);
        }
        this.blankAnswers.length = blankCount;

        this.renderAnswers(blankCount);
        this.renderDistractors();
    }

    modeChanged() {
        this.refresh();
    }

    // Mirrors the textarea into the layer painted behind it, turning each "..." into a chip. Must
    // run on every input event: the layer is what the teacher actually reads (the textarea's own
    // text is transparent - see .cm-blanks-field in app.css).
    syncHighlight() {
        const parts = this.inputTarget.value.split(BLANK_PATTERN);

        this.highlightTarget.innerHTML = parts
            .map((part, index) => (index < parts.length - 1
                ? `${escapeHtml(part)}<span class="cm-blank-chip">...</span>`
                : escapeHtml(part)))
            // A trailing newline has no height of its own, so without the extra one the layer ends
            // a line short of the textarea and the last line drifts out of alignment.
            .join('') + '\n';

        const blankCount = parts.length - 1;
        this.countTarget.textContent = blankCount > 0
            ? this.countTemplateValue.replace('%count%', String(blankCount))
            : this.countEmptyTextValue;
        this.countTarget.classList.toggle('is-empty', blankCount === 0);

        return blankCount;
    }

    get mode() {
        return this.modeInputTargets.find((input) => input.checked)?.value || 'banque';
    }

    renderAnswers(blankCount) {
        const isBanque = this.mode === 'banque';

        this.answersHintTarget.textContent = isBanque ? this.answersHintBanqueValue : this.answersHintLibreValue;
        // "Ignorer majuscules et accents" / "Tolérer une faute de frappe" only mean something when
        // the student types the answer - screen 2a (banque de mots) does not show them at all.
        this.libreOptionsTarget.classList.toggle('d-none', isBanque);

        this.answersTarget.innerHTML = '';
        for (let index = 0; index < blankCount; index += 1) {
            this.answersTarget.appendChild(isBanque ? this.buildBanqueRow(index) : this.buildLibreGroup(index));
        }
    }

    // Banque mode: exactly one word per blank, so a plain text input carrying the first variant.
    buildBanqueRow(index) {
        const row = document.createElement('div');
        row.className = 'cm-blank-row';

        const label = document.createElement('span');
        label.className = 'cm-blank-index';
        label.textContent = this.blankLabelValue.replace('%number%', String(index + 1));

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.name = `blanks[answers][${index}][]`;
        input.placeholder = this.answerPlaceholderValue;
        input.value = this.blankAnswers[index][0] || '';
        input.addEventListener('input', () => {
            this.blankAnswers[index] = input.value.trim() === '' ? [] : [input.value.trim()];
        });

        row.append(label, input);

        return row;
    }

    // Libre mode: any number of accepted variants per blank, as removable green chips.
    buildLibreGroup(index) {
        const group = document.createElement('div');
        group.className = 'cm-blank-group';

        const head = document.createElement('div');
        head.className = 'cm-blank-group__head';

        const label = document.createElement('span');
        label.className = 'cm-blank-index';
        label.textContent = this.blankLabelValue.replace('%number%', String(index + 1));

        const context = document.createElement('span');
        context.className = 'cm-blank-context';
        context.innerHTML = this.contextFor(index);

        head.append(label, context);

        const chips = document.createElement('div');
        chips.className = 'cm-chip-list';
        this.blankAnswers[index].forEach((variant, variantIndex) => {
            chips.appendChild(this.buildChip(variant, `blanks[answers][${index}][]`, 'cm-chip--accepted', () => {
                this.blankAnswers[index].splice(variantIndex, 1);
                this.refresh();
            }));
        });
        chips.appendChild(this.buildAddControl(chips, this.variantAddLabelValue, (value) => {
            this.blankAnswers[index].push(value);
            this.refresh();
        }));

        group.append(head, chips);

        return group;
    }

    // The few words around the blank, as the mockup shows them greyed next to "Trou 2".
    contextFor(index) {
        const parts = this.inputTarget.value.split(BLANK_PATTERN);
        const before = words(parts[index]).slice(-3).join(' ');
        const after = words(parts[index + 1]).slice(0, 3).join(' ');

        if (before === '' && after === '') {
            return '';
        }

        return `« ${before ? `… ${escapeHtml(before)} ` : ''}<b>___</b>${after ? ` ${escapeHtml(after)} …` : ''} »`;
    }

    renderDistractors() {
        // Intrus only exist in banque mode - saisie libre has no bank to mix them into.
        this.distractorsTarget.classList.toggle('d-none', this.mode !== 'banque');
        if (this.mode !== 'banque') {
            return;
        }

        this.distractorChipsTarget.innerHTML = '';
        this.distractors.forEach((word, index) => {
            this.distractorChipsTarget.appendChild(this.buildChip(word, 'blanks[distractors][]', '', () => {
                this.distractors.splice(index, 1);
                this.refresh();
            }));
        });
        this.distractorChipsTarget.appendChild(this.buildAddControl(this.distractorChipsTarget, this.distractorAddLabelValue, (value) => {
            this.distractors.push(value);
            this.refresh();
        }));
    }

    buildChip(value, fieldName, extraClass, onRemove) {
        const chip = document.createElement('span');
        chip.className = `cm-chip ${extraClass}`.trim();
        chip.append(value);

        // The chip itself carries the submitted value - no separate hidden mirror to keep in sync.
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = fieldName;
        field.value = value;

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'cm-chip__remove';
        remove.textContent = '✕';
        remove.addEventListener('click', onRemove);

        chip.append(field, remove);

        return chip;
    }

    // "+ Variante" / "+ Ajouter un intrus": swaps itself for an inline field rather than opening a
    // window.prompt(), which would break the flow of an otherwise inline editor.
    buildAddControl(container, label, onCommit) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'cm-chip-add';
        button.textContent = label;

        button.addEventListener('click', () => {
            const field = document.createElement('input');
            field.type = 'text';
            field.className = 'form-control form-control-sm';
            field.style.width = '150px';
            field.placeholder = this.wordPlaceholderValue;

            // Committing re-renders the list, which tears this field out of the DOM and so fires
            // its own blur handler - without this guard, confirming with Enter would add the word
            // twice (once for the key, once for the blur that the re-render causes).
            let committed = false;
            const commit = () => {
                if (committed) {
                    return;
                }
                committed = true;

                const value = field.value.trim();
                // Either way the list is re-rendered, which puts the "+" button back.
                if (value !== '') {
                    onCommit(value);
                } else {
                    this.refresh();
                }
            };

            field.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    // Otherwise Enter inside a form submits the whole question editor.
                    event.preventDefault();
                    commit();
                } else if (event.key === 'Escape') {
                    committed = true;
                    this.refresh();
                }
            });
            field.addEventListener('blur', commit);

            container.replaceChild(field, button);
            field.focus();
        });

        return button;
    }
}

function words(value) {
    return (value || '').trim().split(/\s+/).filter(Boolean);
}

function escapeHtml(value) {
    return value.replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[character]));
}
