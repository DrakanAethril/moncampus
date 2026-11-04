import { Controller } from '@hotwired/stimulus';

/**
 * An Apparier question during passation - click an item of the right-hand pool, then the slot it
 * belongs to (templates/program/_quiz_apparier_take.html.twig). Same click-not-drag interaction as
 * the word bank (quiz_blanks_take_controller.js) and the légende labels
 * (quiz_legende_take_controller.js), and for the same reasons: it works identically with a finger,
 * and needs no drag library. Clicking a slot that already holds an item takes that item back.
 * Associations are mirrored into hidden pairs[pairId] fields; the server re-validates both sides of
 * every association (ProgramQuizAttemptController::answer()).
 *
 * A right-hand column that repeats one label - a « classement » imported as Apparier, where seven
 * items fall into two or three categories - would show the same chip seven times over. Identical
 * text chips are drawn as one, carrying how many are left (« ×5 »): whichever of them is placed,
 * the server grades the association by what the chip says, never by which copy it was.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['slot', 'choice', 'fields', 'counter'];
    // fieldName is overridden by the "Tester" tab, which namespaces by question id ("pairs[42]")
    // since every question of the template sits on one page.
    static values = { countTemplate: String, fieldName: { type: String, default: 'pairs' } };

    connect() {
        // pairId => { key, chip } - the single source the fields and the chip states render from.
        this.associations = new Map();
        this.activeChoice = null;
        this.render();
    }

    choiceClicked(event) {
        const chip = event.currentTarget;
        if (chip.classList.contains('is-used')) {
            return;
        }

        this.activeChoice = this.activeChoice === chip ? null : chip;
        this.render();
    }

    slotClicked(event) {
        const slot = event.currentTarget;
        const pairId = slot.dataset.pairId;

        if (this.associations.has(pairId)) {
            // Second click on a filled slot: give the item back, whatever chip is active.
            this.associations.delete(pairId);
            this.render();
            return;
        }

        if (!this.activeChoice) {
            return;
        }

        this.associations.set(pairId, { key: this.activeChoice.dataset.choiceKey, chip: this.activeChoice });
        this.activeChoice = null;
        this.render();
    }

    render() {
        const usedChips = new Set([...this.associations.values()].map((association) => association.chip));

        // Same chip states as the word bank (is-selected / is-used), so the existing styles apply.
        this.choiceTargets.forEach((chip) => {
            chip.classList.toggle('is-used', usedChips.has(chip));
            chip.classList.toggle('is-selected', chip === this.activeChoice);
        });
        this.groupIdenticalChips(usedChips);

        this.slotTargets.forEach((slot) => {
            const association = this.associations.get(slot.dataset.pairId);
            const text = slot.querySelector('.cm-match__slot-text');
            slot.classList.toggle('is-filled', Boolean(association));
            if (text) {
                // Cloned rather than copied as a string: an image column's chip holds an <img>, and
                // textContent would drop it. Cloning is also what keeps the alt text on the way in.
                text.replaceChildren(...(association ? [...association.chip.cloneNode(true).childNodes] : []));
            }
        });

        this.fieldsTarget.innerHTML = '';
        this.associations.forEach((association, pairId) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `${this.fieldNameValue}[${pairId}]`;
            input.value = association.key;
            this.fieldsTarget.appendChild(input);
        });

        if (this.hasCounterTarget && this.countTemplateValue) {
            this.counterTarget.textContent = this.countTemplateValue
                .replace('%placed%', String(this.associations.size))
                .replace('%total%', String(this.slotTargets.length));
        }
    }

    // One chip per label: the first copy not placed yet stands for all of them, with what is left.
    groupIdenticalChips(usedChips) {
        const groups = new Map();
        this.choiceTargets.forEach((chip) => {
            if (chip.querySelector('img')) return;
            const label = chip.textContent.trim();
            if (!groups.has(label)) groups.set(label, []);
            groups.get(label).push(chip);
        });

        groups.forEach((chips) => {
            const left = chips.filter((chip) => !usedChips.has(chip));
            const shown = left.includes(this.activeChoice) ? this.activeChoice : (left[0] ?? chips[0]);
            chips.forEach((chip) => {
                chip.classList.toggle('is-duplicate', chips.length > 1 && chip !== shown);
                if (chips.length > 1 && chip === shown && left.length > 1) chip.dataset.left = `×${left.length}`;
                else delete chip.dataset.left;
            });
        });
    }
}
