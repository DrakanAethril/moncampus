import { Controller } from '@hotwired/stimulus';
import 'trix';

// Progressively enhances a hidden Symfony textarea into a Trix WYSIWYG editor - Trix reads/writes
// the element's value directly via its "input" attribute, so the underlying form field (and its
// Symfony validation/errors) work unchanged.
//
// Two opt-in values, both default false so the original usage (Program reports,
// templates/program/report_new.html.twig) keeps behaving exactly as before:
// - hideAttachments: removes Trix's built-in "Attach Files" toolbar button/group. Messaging
//   attaches files through its own separate uploader instead (design/validated/internal-messaging.md),
//   so Trix's inline attachment feature would just be a second, competing way to attach files.
// - emoji: adds a picker button into the toolbar that inserts a plain Unicode character at the
//   cursor - not a Trix attachment, just text. The picker library is only imported when this is
//   actually enabled, so pages that don't need it never pay for it.
export default class extends Controller {
    static values = {
        hideAttachments: { type: Boolean, default: false },
        emoji: { type: Boolean, default: false },
        // Local, vendored dataset (assets/emoji-picker-data/data.json) - the emoji-picker-element
        // package defaults to fetching this from jsDelivr at runtime, which this project's
        // "vendor, don't CDN-load" convention (see CLAUDE.md) rules out.
        emojiDataSource: { type: String, default: '' },
    };

    connect() {
        const editor = document.createElement('trix-editor');
        editor.setAttribute('input', this.element.id);
        this.editorElement = editor;

        // Trix builds its default <trix-toolbar> asynchronously after the <trix-editor> connects
        // (confirmed against the vendored build - toolbarElement is undefined immediately after
        // insertAdjacentElement, only "trix-initialize" guarantees it's ready), so the toolbar
        // customization below has to wait for that event rather than running right after mount.
        if (this.hideAttachmentsValue || this.emojiValue) {
            editor.addEventListener('trix-initialize', () => this.customizeToolbar(editor.toolbarElement), { once: true });
        }

        this.element.insertAdjacentElement('afterend', editor);
    }

    disconnect() {
        this.emojiPicker?.remove();
        this.editorElement?.remove();
    }

    customizeToolbar(toolbar) {
        if (this.hideAttachmentsValue) {
            toolbar.querySelector('[data-trix-action="attachFiles"]')?.closest('.trix-button-group')?.remove();
        }

        if (this.emojiValue) {
            this.addEmojiButton(toolbar);
        }
    }

    addEmojiButton(toolbar) {
        const group = document.createElement('span');
        group.className = 'trix-button-group';

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'trix-button';
        button.tabIndex = -1;
        button.textContent = '🙂';
        button.title = this.element.dataset.trixEditorEmojiLabel ?? 'Emoji';
        button.addEventListener('click', (event) => {
            event.preventDefault();
            this.toggleEmojiPicker(button);
        });

        group.appendChild(button);

        const spacer = toolbar.querySelector('.trix-button-group-spacer');
        spacer ? spacer.before(group) : toolbar.querySelector('.trix-button-row')?.appendChild(group);

        this.emojiButton = button;
    }

    async toggleEmojiPicker(anchor) {
        if (this.emojiPicker) {
            this.emojiPicker.remove();
            this.emojiPicker = null;
            return;
        }

        await import('emoji-picker-element');

        const picker = document.createElement('emoji-picker');
        picker.classList.add('shadow');
        picker.style.position = 'absolute';
        picker.style.zIndex = '1000';
        if ('' !== this.emojiDataSourceValue) {
            picker.dataSource = this.emojiDataSourceValue;
        }

        picker.addEventListener('emoji-click', (event) => {
            this.editorElement.editor.insertString(event.detail.unicode);
            this.editorElement.focus();
            picker.remove();
            this.emojiPicker = null;
        });

        this.emojiPicker = picker;
        anchor.insertAdjacentElement('afterend', picker);
    }
}
