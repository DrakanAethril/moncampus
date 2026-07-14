import { Controller } from '@hotwired/stimulus';

// Self-hosted "classic script" install (see public/hugerte/ and CLAUDE.md) - HugeRTE is
// TinyMCE-shaped, not a tree-shakeable ES module: hugerte.min.js is a self-initializing global
// that, at runtime, fetches its skins/themes/plugins/icons as plain relative HTTP requests from
// wherever it was loaded, which is incompatible with AssetMapper's content-hashed filenames. It's
// loaded here as a plain <script> instead of an importmap entry.
const SCRIPT_URL = '/hugerte/hugerte.min.js';

// Module-level (not per-instance) so multiple editors on the same page - or Turbo Drive
// reconnecting this controller after navigation - only ever fetch the script once.
let scriptLoadPromise = null;

function loadHugerte() {
    if (window.hugerte) {
        return Promise.resolve(window.hugerte);
    }

    if (!scriptLoadPromise) {
        // hugeRTEPreInit tells HugeRTE where its own sibling asset folders live without relying on
        // document.currentScript-based auto-detection, which isn't reliable for a script inserted
        // dynamically like this (mirrors TinyMCE's identical tinyMCEPreInit mechanism).
        window.hugeRTEPreInit = { base: '/hugerte', suffix: '.min' };

        scriptLoadPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = SCRIPT_URL;
            script.addEventListener('load', () => resolve(window.hugerte));
            script.addEventListener('error', () => reject(new Error(`Failed to load ${SCRIPT_URL}`)));
            document.head.appendChild(script);
        });
    }

    return scriptLoadPromise;
}

// Progressively enhances a hidden Symfony textarea into a HugeRTE editor. HugeRTE keeps the
// original textarea in the DOM and listens for the containing form's "submit" event to sync its
// content back into it, so the underlying form field (and its Symfony validation/errors) work
// unchanged - same shape as the previous Trix integration.
//
// One opt-in value, default false so the original usage (Program reports,
// templates/program/report_new.html.twig) keeps behaving exactly as before:
// - emoji: adds a picker button into the toolbar that inserts a plain Unicode character at the
//   cursor - not a HugeRTE attachment, just text. Unlike Trix (which shipped a file-attach button
//   by default that had to be surgically removed from the toolbar after the fact), the
//   plugins/toolbar below are built explicitly and never include an image/file plugin in the
//   first place, so there's nothing to hide for messaging's separate attachment uploader
//   (design/validated/internal-messaging.md) to compete with.
export default class extends Controller {
    static values = {
        emoji: { type: Boolean, default: false },
        // Local, vendored dataset (assets/emoji-picker-data/data.json) - the emoji-picker-element
        // package defaults to fetching this from jsDelivr at runtime, which this project's
        // "vendor, don't CDN-load" convention (see CLAUDE.md) rules out.
        emojiDataSource: { type: String, default: '' },
        emojiLabel: { type: String, default: 'Emoji' },
    };

    async connect() {
        const hugerte = await loadHugerte();

        // Matches Trix's previous stock toolbar (bold/italic/strikethrough/heading/quote/
        // preformatted/lists/link) - not an opportunity to add capabilities Trix didn't have.
        const toolbar = 'bold italic strikethrough | blocks | blockquote | bullist numlist | link'
            + (this.emojiValue ? ' | emoji' : '');

        const [editor] = await hugerte.init({
            target: this.element,
            base_url: '/hugerte',
            suffix: '.min',
            menubar: false,
            statusbar: false,
            plugins: 'lists link',
            toolbar,
            block_formats: 'Paragraph=p;Heading 1=h1;Preformatted=pre',
            setup: (setupEditor) => {
                // HugeRTE only syncs its content back into the underlying textarea by default on
                // the form's "submit" event - too late here, since the textarea is hidden
                // (class="d-none") and Symfony renders it "required" (from the body/description
                // field's NotBlank constraint). The browser's native required-field validation
                // runs *before* that submit-time sync and can't focus a hidden field to report
                // it, so it silently blocks the submit entirely. Sync on every change instead,
                // matching how Trix kept the textarea continuously up to date via its "input"
                // attribute.
                setupEditor.on('change input undo redo', () => setupEditor.save());

                if (this.emojiValue) {
                    setupEditor.ui.registry.addButton('emoji', {
                        text: '🙂',
                        tooltip: this.emojiLabelValue,
                        onAction: () => this.toggleEmojiPicker(setupEditor),
                    });
                }
            },
        });

        this.editor = editor;
    }

    disconnect() {
        this.emojiPicker?.remove();
        this.editor?.remove();
    }

    async toggleEmojiPicker(editor) {
        if (this.emojiPicker) {
            this.emojiPicker.remove();
            this.emojiPicker = null;
            return;
        }

        await import('emoji-picker-element');

        const picker = document.createElement('emoji-picker');
        picker.classList.add('shadow');
        // position: fixed + an explicit rect from the toolbar, rather than inserting the picker
        // into the editor's own DOM (position: absolute there ends up positioned relative to
        // whatever ancestor happens to be the nearest positioned one, which - depending on the
        // page - can land the picker behind the editor's large iframe or overlapping unrelated
        // content above the field entirely). Fixed positioning off a measured rect sidesteps all
        // of that.
        const toolbarRect = (editor.getContainer().querySelector('.tox-editor-header') ?? editor.getContainer()).getBoundingClientRect();
        picker.style.position = 'fixed';
        picker.style.top = `${toolbarRect.bottom}px`;
        picker.style.left = `${toolbarRect.left}px`;
        picker.style.zIndex = '1000';
        if ('' !== this.emojiDataSourceValue) {
            picker.dataSource = this.emojiDataSourceValue;
        }

        picker.addEventListener('emoji-click', (event) => {
            editor.insertContent(event.detail.unicode);
            editor.focus();
            picker.remove();
            this.emojiPicker = null;
        });

        this.emojiPicker = picker;
        document.body.appendChild(picker);
    }
}
