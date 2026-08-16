import { Controller } from '@hotwired/stimulus';

/**
 * The wiki's editor - a separate controller from hugerte_editor_controller.js on purpose.
 *
 * That one already carries four modes (default, signature, messaging, documentation-image) chosen
 * by boolean values; a fifth would make its toolbar expression unreadable, and this one differs in
 * kind rather than in degree: it turns the **menubar** on, which is what separates a rich field
 * from an editor, and it adds three buttons of its own plus callouts, KaTeX and Mermaid.
 *
 * The vendored plugin set is deliberate (design/validated/wiki.md, "The editor"), and so is what is
 * missing from it: **no `media`**. There is no video in a wiki by decision, which is what keeps
 * `iframe` out of the sanitizer entirely - and the sanitizer, not this file, is what enforces it.
 *
 * KaTeX and Mermaid are both rendered **at insert time** and stored as their output, with the
 * source kept in a data- attribute so the author can edit it again. Reading a page and printing it
 * then cost nothing: no library runs on the read side, KaTeX needing only its stylesheet, which is
 * also why it works unchanged inside Gotenberg's Chromium.
 */

const SCRIPT_URL = '/hugerte/hugerte.min.js';
const KATEX_URL = '/katex/katex.min.js';
const MERMAID_URL = '/mermaid/mermaid.min.js';

let hugerteLoadPromise = null;
const externalLoads = new Map();

function isDarkTheme() {
    return 'dark' === document.documentElement.getAttribute('data-bs-theme');
}

/** Loads a plain <script> once, whoever asks and however many editors are on the page. */
function loadScript(url, isReady) {
    if (isReady()) {
        return Promise.resolve();
    }

    if (!externalLoads.has(url)) {
        externalLoads.set(url, new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = url;
            script.addEventListener('load', () => resolve());
            script.addEventListener('error', () => reject(new Error(`Failed to load ${url}`)));
            document.head.appendChild(script);
        }));
    }

    return externalLoads.get(url);
}

function loadHugerte() {
    if (window.hugerte) {
        return Promise.resolve(window.hugerte);
    }

    if (!hugerteLoadPromise) {
        // Same pre-init as the shared controller: HugeRTE fetches its own skins/plugins/icons by
        // relative HTTP at runtime, which is why it is vendored outside AssetMapper.
        window.hugeRTEPreInit = { base: '/hugerte', suffix: '.min' };

        hugerteLoadPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = SCRIPT_URL;
            script.addEventListener('load', () => resolve(window.hugerte));
            script.addEventListener('error', () => reject(new Error(`Failed to load ${SCRIPT_URL}`)));
            document.head.appendChild(script);
        });
    }

    return hugerteLoadPromise;
}

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        height: { type: Number, default: 520 },
        imageUploadUrl: { type: String, default: '' },
        imageUploadToken: { type: String, default: '' },
        pagesUrl: { type: String, default: '' },
        // Labels, so every string the editor shows stays in translations/ rather than here.
        labels: { type: Object, default: {} },
    };

    async connect() {
        const hugerte = await loadHugerte();
        const dark = isDarkTheme();

        const [editor] = await hugerte.init({
            target: this.element,
            base_url: '/hugerte',
            suffix: '.min',
            skin: dark ? 'oxide-dark' : 'oxide',
            content_css: dark ? 'dark' : 'default',
            // The menubar is the point: Fichier / Édition / Insertion / Format / Tableau is what
            // separates a rich field from an editor, more than a long toolbar does.
            menubar: 'file edit insert format table',
            statusbar: true,
            height: this.heightValue,
            branding: false,
            convert_urls: false,
            plugins: 'accordion advlist anchor autolink autosave charmap code codesample fullscreen'
                + ' image insertdatetime link lists nonbreaking pagebreak quickbars searchreplace'
                + ' table visualblocks wordcount',
            toolbar: 'bold italic strikethrough forecolor | blocks | bullist numlist outdent indent'
                + ' | blockquote codesample | wikilink link image | katex mermaid | callouts'
                + ' | table | searchreplace visualblocks code fullscreen',
            // A draft restored after an accidental close - the single most valuable addition on a
            // wiki, where a page is written over an hour rather than a minute.
            autosave_ask_before_unload: true,
            autosave_interval: '20s',
            autosave_retention: '1440m',
            quickbars_insert_toolbar: 'image codesample table',
            quickbars_selection_toolbar: 'bold italic link blockquote',
            block_formats: 'Paragraph=p;Titre 1=h1;Titre 2=h2;Titre 3=h3;Titre 4=h4;Préformaté=pre',
            // pagebreak's marker is read by the PDF export as a real page break.
            pagebreak_separator: '<div class="cm-wiki-pagebreak"></div>',
            setup: (setupEditor) => {
                setupEditor.on('change input undo redo', () => setupEditor.save());
                this.registerCallouts(setupEditor);
                this.registerWikiLink(setupEditor);
                this.registerKatex(setupEditor);
                this.registerMermaid(setupEditor);
            },
            ...(this.imageUploadUrlValue ? this.imageOptions() : {}),
        });

        this.editor = editor;
    }

    disconnect() {
        this.editor?.remove();
    }

    label(key, fallback) {
        return this.labelsValue[key] ?? fallback;
    }

    // --- Callouts ------------------------------------------------------------------------

    /**
     * Info / Attention / Astuce blocks, offered in the toolbar as a menu. Styled with the app's own
     * --cm-* custom properties, so they render identically on screen, in dark mode and in the PDF -
     * which is the reason they are plain divs with a class rather than anything cleverer.
     */
    registerCallouts(editor) {
        const kinds = [
            ['info', this.label('calloutInfo', 'Info')],
            ['warning', this.label('calloutWarning', 'Attention')],
            ['tip', this.label('calloutTip', 'Astuce')],
        ];

        editor.ui.registry.addMenuButton('callouts', {
            text: this.label('callouts', 'Encadré'),
            fetch: (callback) => callback(kinds.map(([kind, text]) => ({
                type: 'menuitem',
                text,
                onAction: () => {
                    const selected = editor.selection.getContent({ format: 'html' });
                    editor.insertContent(
                        `<div class="cm-callout cm-callout--${kind}"><p>${selected || text}</p></div><p></p>`,
                    );
                },
            }))),
        });
    }

    // --- Link to a wiki page -------------------------------------------------------------

    /**
     * The button that makes this a wiki rather than a pile of pages - without it the rest is
     * decoration. The list comes from the same endpoint the edit screen's own picker uses, so
     * there is one source of truth for "what pages exist here".
     */
    registerWikiLink(editor) {
        editor.ui.registry.addButton('wikilink', {
            text: this.label('wikiLink', 'Lien wiki'),
            tooltip: this.label('wikiLinkTooltip', 'Lien vers une page de ce wiki'),
            onAction: async () => {
                let pages = [];

                try {
                    const response = await fetch(this.pagesUrlValue, { credentials: 'same-origin' });
                    pages = (await response.json()).results;
                } catch {
                    editor.notificationManager.open({ text: this.label('loadFailed', 'Chargement impossible.'), type: 'error' });

                    return;
                }

                editor.windowManager.open({
                    title: this.label('wikiLinkTooltip', 'Lien vers une page de ce wiki'),
                    body: {
                        type: 'panel',
                        items: [{
                            type: 'selectbox',
                            name: 'page',
                            label: this.label('page', 'Page'),
                            items: pages.map((page) => ({
                                value: `${page.url}|${page.title}`,
                                text: `${'  '.repeat(page.depth)}${page.title}`,
                            })),
                        }],
                    },
                    buttons: [
                        { type: 'cancel', text: this.label('cancel', 'Annuler') },
                        { type: 'submit', text: this.label('insert', 'Insérer'), primary: true },
                    ],
                    onSubmit: (dialog) => {
                        const [url, title] = dialog.getData().page.split('|');
                        const selected = editor.selection.getContent({ format: 'text' });
                        editor.insertContent(`<a href="${url}">${selected || title}</a>`);
                        dialog.close();
                    },
                });
            },
        });
    }

    // --- KaTeX ---------------------------------------------------------------------------

    registerKatex(editor) {
        editor.ui.registry.addButton('katex', {
            text: 'TeX',
            tooltip: this.label('katexTooltip', 'Formule mathématique'),
            onAction: () => this.openSourceDialog(editor, {
                title: this.label('katexTooltip', 'Formule mathématique'),
                placeholder: 'e^{i\\pi} + 1 = 0',
                initial: this.sourceUnderCursor(editor, 'katex'),
                render: async (source) => {
                    await loadScript(KATEX_URL, () => window.katex);
                    // Rendered here and stored as its output: the read side then needs only the
                    // stylesheet, which is also what makes it work inside Gotenberg's Chromium.
                    const html = window.katex.renderToString(source, { throwOnError: true, displayMode: false });

                    return `<span class="cm-katex" data-katex="${this.escapeAttribute(source)}">${html}</span>`;
                },
            }),
        });
    }

    // --- Mermaid -------------------------------------------------------------------------

    registerMermaid(editor) {
        editor.ui.registry.addButton('mermaid', {
            text: this.label('mermaid', 'Schéma'),
            tooltip: this.label('mermaidTooltip', 'Diagramme Mermaid'),
            onAction: () => this.openSourceDialog(editor, {
                title: this.label('mermaidTooltip', 'Diagramme Mermaid'),
                placeholder: 'graph TD;\n  A-->B;',
                initial: this.sourceUnderCursor(editor, 'mermaid'),
                multiline: true,
                render: async (source) => {
                    await loadScript(MERMAID_URL, () => window.mermaid);
                    window.mermaid.initialize({
                        startOnLoad: false,
                        // htmlLabels:false is what lets the sanitizer refuse <foreignObject>
                        // without losing every label: labels come back as <text> instead.
                        htmlLabels: false,
                        flowchart: { htmlLabels: false },
                        securityLevel: 'strict',
                        theme: isDarkTheme() ? 'dark' : 'default',
                    });

                    const { svg } = await window.mermaid.render(`mermaid-${Date.now()}`, source);

                    return `<div class="cm-mermaid" data-mermaid="${this.escapeAttribute(source)}">${svg}</div><p></p>`;
                },
            }),
        });
    }

    // --- Shared dialog for the two source-carrying blocks ---------------------------------

    openSourceDialog(editor, { title, placeholder, initial, multiline = false, render }) {
        editor.windowManager.open({
            title,
            body: {
                type: 'panel',
                items: [{
                    type: 'textarea',
                    name: 'source',
                    label: title,
                    placeholder,
                    maximized: multiline,
                }],
            },
            initialData: { source: initial ?? '' },
            buttons: [
                { type: 'cancel', text: this.label('cancel', 'Annuler') },
                { type: 'submit', text: this.label('insert', 'Insérer'), primary: true },
            ],
            onSubmit: async (dialog) => {
                const source = dialog.getData().source.trim();

                if ('' === source) {
                    dialog.close();

                    return;
                }

                try {
                    const html = await render(source);
                    // Replacing rather than appending when the cursor sits on an existing block is
                    // what makes these editable instead of write-once.
                    this.replaceBlockUnderCursor(editor, html);
                    dialog.close();
                } catch (error) {
                    editor.notificationManager.open({ text: String(error.message ?? error), type: 'error' });
                }
            },
        });
    }

    /** The source of the KaTeX/Mermaid block the cursor is inside, when there is one. */
    sourceUnderCursor(editor, kind) {
        return editor.dom.getParent(editor.selection.getNode(), `[data-${kind}]`)?.getAttribute(`data-${kind}`) ?? '';
    }

    replaceBlockUnderCursor(editor, html) {
        const existing = editor.dom.getParent(editor.selection.getNode(), '[data-katex],[data-mermaid]');

        if (existing) {
            editor.selection.select(existing);
        }

        editor.insertContent(html);
        editor.save();
    }

    escapeAttribute(value) {
        return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // --- The image button, same contract as the Base documentaire's ------------------------

    imageOptions() {
        return {
            automatic_uploads: true,
            images_upload_credentials: true,
            image_description: true,
            images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
                const body = new FormData();
                body.append('file', blobInfo.blob(), blobInfo.filename());

                fetch(this.imageUploadUrlValue, {
                    method: 'POST',
                    body,
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-Token': this.imageUploadTokenValue },
                })
                    .then((response) => (response.ok ? response.json() : Promise.reject(new Error(String(response.status)))))
                    .then((data) => resolve(data.location))
                    .catch((error) => reject({ message: String(error.message ?? error), remove: true }));
            }),
        };
    }
}
