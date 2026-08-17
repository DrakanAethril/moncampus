import { Controller } from '@hotwired/stimulus';

// « Reprendre le contenu de la séance » under one part of a cahier de texte: the text the séance of
// the progression already carries for that part, pasted into its editor in one click.
//
// Only offered while the part is empty. The server has already answered the other half of the
// question - whether the séance says anything at all about this part - so what is left here is the
// live one: an editor the teacher has just typed into is no longer empty, and the offer must
// withdraw itself rather than propose to overwrite what was written a second ago.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        // id of the <textarea> HugeRTE was mounted on. HugeRTE registers its editors under that id,
        // which is how this controller reaches an editor built by a controller it knows nothing of.
        field: String,
        content: String,
    };

    connect() {
        this.textarea = document.getElementById(this.fieldValue);
        this.refresh();
        this.watchEditor();
    }

    disconnect() {
        if (this.retryTimer) {
            clearTimeout(this.retryTimer);
        }

        this.editor?.off('change input undo redo', this.refreshListener);
    }

    paste() {
        const editor = this.editor ?? this.findEditor();

        if (editor) {
            editor.setContent(this.contentValue);
            // The textarea is what the form submits, and HugeRTE only syncs it on its own events -
            // none of which a programmatic setContent() raises.
            editor.save();
        } else if (this.textarea) {
            // The editor is lazy: before it is up, the textarea is still the field itself.
            this.textarea.value = this.contentValue;
        }

        this.refresh();
    }

    // HugeRTE mounts asynchronously (it fetches its own script), so the editor is usually not there
    // yet when this controller connects. Look for it until it shows up, then follow its changes.
    watchEditor(attempt = 0) {
        const editor = this.findEditor();

        if (editor) {
            this.editor = editor;
            this.refreshListener = () => this.refresh();
            editor.on('change input undo redo', this.refreshListener);
            this.refresh();

            return;
        }

        if (attempt < 40) {
            this.retryTimer = setTimeout(() => this.watchEditor(attempt + 1), 250);
        }
    }

    findEditor() {
        return window.hugerte?.get(this.fieldValue) ?? null;
    }

    refresh() {
        this.element.hidden = this.saysSomething(this.editor ? this.editor.getContent() : this.textarea?.value);
    }

    // Same rule as App\Service\SeanceContentResolver::saysSomething(): an editor cleared by hand
    // keeps a <p><br></p>, and that is not content.
    saysSomething(html) {
        const text = (html ?? '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ');

        return '' !== text.trim();
    }
}
