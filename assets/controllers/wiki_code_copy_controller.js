import { Controller } from '@hotwired/stimulus';

/**
 * A one-click copy button on every code block of a wiki page - the same gesture as the machines
 * list's IP addresses, down to the icon that turns into a tick (`cm-copybtn`).
 *
 * It decorates the *rendered* body rather than the stored HTML, and that is the whole design: a
 * page written before this existed gets the button too, the sanitizer's element list stays closed,
 * and the PDF export - which renders the same stored HTML without any JavaScript - keeps a clean
 * <pre> with no button printed into it.
 *
 * Sibling of copy_block_controller.js (one button, one target declared in the markup) and
 * copy_value_controller.js (many buttons, the value carried as an action param). This one is the
 * case where neither can apply: the buttons do not exist until the page is read.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { label: String };

    connect() {
        this.element.querySelectorAll('pre').forEach((block) => this.decorate(block));
    }

    // Turbo restores a cached page by putting the previous DOM back, so a controller can connect
    // onto a body that already carries its buttons. The wrapper is the flag.
    decorate(block) {
        if (block.parentElement?.classList.contains('cm-codeblock')) {
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'cm-codeblock';
        block.replaceWith(wrapper);
        wrapper.append(block, this.buildButton());
    }

    buildButton() {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'cm-copybtn cm-codeblock__copy';
        button.title = this.labelValue;
        button.setAttribute('aria-label', this.labelValue);
        button.dataset.action = 'click->wiki-code-copy#copy';
        button.innerHTML = '<svg class="cm-copybtn__icon" width="14" height="14" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<rect x="8" y="8" width="12" height="12" rx="2"></rect>'
            + '<path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"></path></svg>'
            + '<svg class="cm-copybtn__icon cm-copybtn__icon--done" width="14" height="14" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M5 12l5 5L20 7"></path></svg>';

        return button;
    }

    async copy(event) {
        const button = event.currentTarget;
        const block = button.parentElement.querySelector('pre');

        // textContent rather than innerText: a code sample is copied to be pasted into a terminal,
        // so its own line breaks and indentation are the content, not presentation to normalise.
        await navigator.clipboard.writeText(block.textContent.replace(/\s+$/, ''));

        button.classList.add('cm-copybtn--done');
        window.setTimeout(() => button.classList.remove('cm-copybtn--done'), 1500);
    }
}
