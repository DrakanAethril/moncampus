import { Controller } from '@hotwired/stimulus';

// Attachments of a practice application (design_handoff_postulation_redaction, screens 8b and 8f).
//
// One way in - "Joindre un fichier" - and as many files as the student wants, each removable by its
// own ×. The chips are painted here rather than by Twig because the files exist nowhere but in the
// browser until the form is posted: there is no upload round-trip to re-render from.
//
// The list is kept in a plain array and written back into the field through a DataTransfer, which
// is the only way to make `input.files` anything other than what the file dialog last returned -
// removing a chip would otherwise leave the removed file in the post.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'chips'];

    static values = { removeLabel: String };

    connect() {
        this.files = [];
    }

    pick() {
        this.inputTarget.click();
    }

    added() {
        // Same file picked twice - two dialogs, or one after removing it - joins the list once.
        for (const file of this.inputTarget.files) {
            if (!this.files.some((joined) => joined.name === file.name && joined.size === file.size)) {
                this.files.push(file);
            }
        }

        this.sync();
    }

    remove(event) {
        this.files.splice(Number(event.currentTarget.dataset.index), 1);
        this.sync();
    }

    sync() {
        const transfer = new DataTransfer();
        this.files.forEach((file) => transfer.items.add(file));
        this.inputTarget.files = transfer.files;
        this.render();
    }

    // The add button stays where the markup put it, last: the chips are inserted before it, so a
    // newly joined file appears next to the ones already there rather than after the way in.
    render() {
        this.chipsTarget.querySelectorAll('.cm-postul__chip').forEach((chip) => chip.remove());

        const button = this.chipsTarget.querySelector('.cm-postul__attach');
        this.files.forEach((file, index) => this.chipsTarget.insertBefore(this.buildChip(file, index), button));
    }

    buildChip(file, index) {
        const chip = document.createElement('span');
        chip.className = 'cm-postul__chip';

        const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        icon.setAttribute('width', '12');
        icon.setAttribute('height', '12');
        icon.setAttribute('viewBox', '0 0 24 24');
        icon.setAttribute('fill', 'none');
        icon.setAttribute('stroke', 'currentColor');
        icon.setAttribute('stroke-width', '2');
        icon.setAttribute('stroke-linecap', 'round');
        icon.innerHTML = '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path>';
        chip.append(icon);

        const name = document.createElement('span');
        name.textContent = file.name;
        chip.append(name);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'cm-postul__chipremove';
        remove.title = this.removeLabelValue;
        remove.setAttribute('aria-label', `${this.removeLabelValue} ${file.name}`);
        remove.dataset.index = String(index);
        remove.dataset.action = 'training-attachments#remove';
        remove.textContent = '×';
        chip.append(remove);

        return chip;
    }
}
