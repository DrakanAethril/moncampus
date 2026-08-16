import { Controller } from '@hotwired/stimulus';

/**
 * The internal-link picker: the wiki's own pages, listed by the endpoint, inserted as a link at the
 * cursor.
 *
 * Linking one page to another is what makes this a wiki rather than a pile of pages. The list comes
 * from the server (App\Controller\Wiki\WikiContentController::pages()) rather than from the rail's
 * DOM, so it stays right whichever screen this is used from - and so the toolbar button that will
 * open the same list needs no second source.
 *
 * Insertion goes through HugeRTE's own API when the editor has taken over the textarea, and falls
 * back to writing into the textarea itself when it has not - progressive enhancement, same posture
 * as the editor's own wiring.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['panel'];
    static values = {
        url: String,
        editor: String,
    };

    async toggle() {
        if (!this.panelTarget.hidden) {
            this.panelTarget.hidden = true;

            return;
        }

        if (!this.loaded) {
            await this.load();
        }

        this.panelTarget.hidden = false;
    }

    async load() {
        this.panelTarget.textContent = '…';

        try {
            const response = await fetch(this.urlValue, { credentials: 'same-origin' });
            const data = await response.json();

            this.panelTarget.textContent = '';
            const list = document.createElement('ul');

            for (const page of data.results) {
                const item = document.createElement('li');
                item.style.paddingLeft = `${page.depth * 12}px`;

                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = page.title;
                button.addEventListener('click', () => this.insert(page));

                item.appendChild(button);
                list.appendChild(item);
            }

            this.panelTarget.appendChild(list);
            this.loaded = true;
        } catch {
            this.panelTarget.textContent = this.panelTarget.dataset.errorText || '⚠';
        }
    }

    insert(page) {
        const link = `<a href="${page.url}">${page.title}</a>`;
        const editor = window.hugerte?.get(this.editorValue);

        if (editor) {
            editor.insertContent(link);
            editor.save();
        } else {
            const textarea = document.getElementById(this.editorValue);

            if (textarea) {
                textarea.value += link;
            }
        }

        this.panelTarget.hidden = true;
    }
}
