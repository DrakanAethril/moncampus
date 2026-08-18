import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';
import { DIAGRAM_THEME, selfContainedSvg } from '../wiki/mermaid_svg.js';

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
 *
 * A Mermaid diagram goes through assets/wiki/mermaid_svg.js before being inserted, which is what
 * makes it survive the trip to the database: read that file before touching anything about how a
 * diagram is produced.
 */

const SCRIPT_URL = '/hugerte/hugerte.min.js';
const KATEX_URL = '/katex/katex.min.js';
const MERMAID_URL = '/mermaid/mermaid.min.js';

let hugerteLoadPromise = null;
const externalLoads = new Map();

/** Escapes text going into an HTML string - a page title is user input and reaches the body. */
function escapeHtml(text) {
    const holder = document.createElement('span');
    holder.textContent = text;

    return holder.innerHTML;
}

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

        // Eagerly, and not on demand like Mermaid: renderKatexOnSave() runs inside the editor's own
        // synchronous serialization hook, so the library has to be there already. It is ~300 KB
        // against Mermaid's 3.4 MB, which is why only one of the two is worth loading up front.
        await loadScript(KATEX_URL, () => window.katex).catch(() => {});

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
            // The editing area is an iframe and does not load app.css, so a diagram's affordance has
            // to be declared here: it is an object to click, not text to walk into.
            content_style: '.cm-mermaid { cursor: pointer; }',
            // HugeRTE validates content against its own HTML schema, which knows nothing about
            // SVG - so a Mermaid diagram was silently stripped *in the editor*, before the server's
            // sanitizer ever saw it. Measured, not guessed: the stored page came back holding
            // data-mermaid and no picture.
            //
            // The list mirrors the server's allowlist (config/packages/html_sanitizer.yaml) and
            // deliberately omits the same things - no `use`, no `image`, no `foreignObject`, and no
            // `style`. Omitting `style` matters for a reason beyond safety: the server drops it
            // whatever happens, so letting the editor keep it would show the author a styled
            // diagram that turns plain the moment it is saved.
            //
            // `[*]` on each element is not a second opinion about attributes - the server's
            // enumerated list is what decides. This only has to stop the editor from throwing the
            // elements away.
            extended_valid_elements: 'svg[*],g[*],path[*],rect[*],circle[*],ellipse[*],line[*],'
                + 'polyline[*],polygon[*],text[*],tspan[*],defs[*],marker[*]',
            valid_children: '+div[svg]',
            setup: (setupEditor) => {
                // save() writes the serialized HTML into the textarea; renderKatexIntoField()
                // immediately rebuilds the formulas the serializer flattened on the way past.
                setupEditor.on('change input undo redo', () => {
                    setupEditor.save();
                    this.renderKatexIntoField();
                });
                this.registerCallouts(setupEditor);
                this.registerWikiLink(setupEditor);
                this.registerKatex(setupEditor);
                this.registerMermaid(setupEditor);
                this.registerMermaidEditing(setupEditor);
            },
            ...(this.imageUploadUrlValue ? this.imageOptions() : {}),
        });

        this.editor = editor;

        // Repairs the diagrams of pages written before the styles were inlined. Deliberately not
        // awaited: it pulls Mermaid in (3.4 MB) and must not hold up an editor whose page has none.
        this.refreshStaleMermaidBlocks(editor).catch(() => {});

        // The last word before the page posts. Deliberately **synchronous** and without
        // preventDefault: it only rewrites the field the form is about to read, so none of the
        // re-submit trap described on renderKatexIntoField() applies. It exists because the
        // per-change rebuild is driven by editor events, and a save that follows a programmatic
        // setContent - or any path that writes the field without a change event - would otherwise
        // post the flattened markup.
        this.form = this.element.closest('form');
        this.onSubmit = () => {
            this.editor?.save();
            this.renderKatexIntoField();
        };
        this.form?.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.form?.removeEventListener('submit', this.onSubmit);
        this.editor?.remove();
    }

    /**
     * Rebuilds every KaTeX block from its source, in the textarea, right after the editor has
     * written to it.
     *
     * Measured, and the reason this exists at all: HugeRTE's serializer removes empty inline
     * elements, and KaTeX's layout is built out of empty `<span class="katex-strut">`s carrying
     * only a height. Storing KaTeX's output directly therefore gave a formula whose exponent had
     * come loose. Neither `mceNonEditable` nor `contenteditable="false"` protects them - the
     * serializer walks everything - and the server's sanitizer is not the culprit either: it
     * preserves an empty span untouched, which was checked separately.
     *
     * Two tidier-looking hooks were tried first and both are traps, so neither should be
     * reintroduced:
     *
     *   - **the form's `submit` event**: preventing the default and re-submitting from an async
     *     handler leaves the form silently unsent - no POST goes out and nothing reports it;
     *   - **the editor's `SaveContent` event**: the listener does fire and `event.content` really
     *     does come back rewritten, but this version writes the textarea from the *unmodified*
     *     content anyway, so the rewrite is discarded.
     *
     * Writing the textarea directly depends on no hook semantics at all. The stored page is then
     * what the design asked for: rendered output that needs no JavaScript to read, and that works
     * unchanged inside Gotenberg's Chromium, which only loads the stylesheet.
     */
    renderKatexIntoField() {
        const html = this.element.value;

        if (!html.includes('data-katex') || !window.katex) {
            return;
        }

        const holder = document.createElement('div');
        holder.innerHTML = html;

        holder.querySelectorAll('[data-katex]').forEach((block) => {
            // throwOnError false: a formula the author has since broken must not be what stops them
            // saving the rest of the page.
            block.innerHTML = window.katex.renderToString(block.getAttribute('data-katex'), { throwOnError: false });
        });

        this.element.value = holder.innerHTML;
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
     * there is one source of truth for "what pages exist here", and that endpoint is scoped to a
     * single wiki: no other wiki's pages can appear in it.
     *
     * The field is a Tom Select rather than HugeRTE's own `selectbox`, which is a bare <select>: on
     * a wiki of any size the page you want sits somewhere in a hundred-line list, and scrolling one
     * is not finding one. Tom Select is this app's picker everywhere else, so it is also the one the
     * author already knows.
     *
     * It is mounted through an `htmlpanel` because HugeRTE has no searchable field of its own, and
     * that has two consequences worth knowing before touching this. The value cannot come from
     * `dialog.getData()` - an htmlpanel carries no form data - so the instance is read directly. And
     * the dropdown must stay *inside* the dialog rather than being appended to <body>: HugeRTE's
     * modal traps focus, and anything outside it would be unreachable.
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

                const fieldId = `wiki-link-page-${editor.id}`;
                let picker = null;

                editor.windowManager.open({
                    title: this.label('wikiLinkTooltip', 'Lien vers une page de ce wiki'),
                    body: {
                        type: 'panel',
                        items: [{
                            type: 'htmlpanel',
                            html: `<label class="cm-wiki-linkfield__label" for="${fieldId}">`
                                + `${escapeHtml(this.label('page', 'Page'))}</label>`
                                + `<div class="cm-wiki-linkfield"><select id="${fieldId}"></select></div>`,
                        }],
                    },
                    buttons: [
                        { type: 'cancel', text: this.label('cancel', 'Annuler') },
                        { type: 'submit', text: this.label('insert', 'Insérer'), primary: true },
                    ],
                    onSubmit: (dialog) => {
                        const page = pages.find((candidate) => String(candidate.id) === picker?.getValue());

                        if (!page) {
                            // Nothing chosen: the dialog stays open rather than inserting an empty
                            // link the author would have to find again later.
                            return;
                        }

                        const selected = editor.selection.getContent({ format: 'text' });
                        editor.insertContent(`<a href="${page.url}">${selected || escapeHtml(page.title)}</a>`);
                        dialog.close();
                    },
                    onClose: () => {
                        picker?.destroy();
                        picker = null;
                    },
                });

                // The panel's markup only exists once the dialog has been put into the DOM.
                const field = document.getElementById(fieldId);

                if (field) {
                    picker = new TomSelect(field, {
                        options: pages.map((page) => ({
                            value: String(page.id),
                            // The indent is what turns the flat list back into the tree it came
                            // from. Non-breaking spaces, or the browser collapses them away.
                            text: `${'  '.repeat(page.depth)}${page.title}`,
                        })),
                        maxOptions: null,
                        placeholder: this.label('page', 'Page'),
                        // On <body>, not inside the field: HugeRTE's dialog clips and stacks its own
                        // content, and the list is simply not visible from within it - measured
                        // several ways before settling here. The dialog's focus handling is a Tab
                        // cycle, and the text input stays inside the dialog, so nothing is lost.
                        dropdownParent: 'body',
                        dropdownClass: 'ts-dropdown cm-wiki-linkfield__dropdown',
                    });
                    picker.focus();
                }
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
                target: this.blockUnderCursor(editor, 'katex'),
                kind: 'katex',
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
            // On a selected diagram the button edits it rather than inserting a second one below.
            onAction: () => this.openMermaidDialog(editor, this.blockUnderCursor(editor, 'mermaid')),
        });
    }

    /** The one dialog for both cases: `target` null inserts, `target` set rewrites that diagram. */
    openMermaidDialog(editor, target) {
        this.openSourceDialog(editor, {
            title: this.label('mermaidTooltip', 'Diagramme Mermaid'),
            placeholder: 'graph TD;\n  A-->B;',
            target,
            kind: 'mermaid',
            multiline: true,
            // Only on insertion: an empty paragraph after the block is what leaves somewhere to
            // type. Re-adding it on every edit would pile blank lines under the diagram.
            trailing: '<p></p>',
            render: (source) => this.renderMermaid(source),
        });
    }

    /**
     * Renders one diagram into the markup that is both shown and stored.
     *
     * `selfContainedSvg()` is not a detail: without it Mermaid's stylesheet - which is where a
     * diagram's whole appearance lives - is thrown away by the sanitizer on the way to the database,
     * and the author gets a right-looking preview and a wrong-looking page. See
     * assets/wiki/mermaid_svg.js.
     */
    async renderMermaid(source) {
        await loadScript(MERMAID_URL, () => window.mermaid);
        window.mermaid.initialize({
            startOnLoad: false,
            // htmlLabels:false is what lets the sanitizer refuse <foreignObject>
            // without losing every label: labels come back as <text> instead.
            htmlLabels: false,
            flowchart: { htmlLabels: false },
            securityLevel: 'strict',
            ...DIAGRAM_THEME,
        });

        const { svg } = await window.mermaid.render(`mermaid-${Date.now()}`, source);

        return `<div class="cm-mermaid" data-mermaid="${this.escapeAttribute(source)}">${selfContainedSvg(svg)}</div>`;
    }

    /**
     * Redraws the diagrams of pages written before the styles were inlined, when the editor opens.
     *
     * Their source is intact in `data-mermaid` - only the picture beside it is wrong - so re-running
     * it through the current pipeline repairs the page, and the author has nothing to find or
     * redo. Nothing is written until they save, which is also why this is not a migration: a page
     * nobody edits keeps the diagram it has.
     *
     * The staleness test is the one thing the old markup cannot fake: a label with no font-size of
     * its own. Mermaid used to set it in the stylesheet the sanitizer removed, so every diagram
     * stored before this has bare `<text>` elements, and every one stored after carries it inline.
     */
    async refreshStaleMermaidBlocks(editor) {
        const stale = [...editor.dom.select('[data-mermaid]')].filter(
            (block) => !block.querySelector('svg') || !block.querySelector('text[style*="font-size"]'),
        );

        if (0 === stale.length) {
            return;
        }

        for (const block of stale) {
            try {
                // Sequentially: Mermaid renders through a single shared container and interleaved
                // renders come back with each other's geometry.
                // eslint-disable-next-line no-await-in-loop
                const html = await this.renderMermaid(block.getAttribute('data-mermaid'));
                editor.dom.setOuterHTML(block, html);
            } catch {
                // A diagram whose source no longer parses keeps the picture it had: this runs on
                // opening a page, and must never be what stops somebody editing its text.
            }
        }

        this.markMermaidObjects(editor);
        editor.save();
        this.renderKatexIntoField();
    }

    /**
     * What makes an inserted diagram modifiable at all - the toolbar button alone was not enough,
     * and this is the bug it fixes: **a caret cannot be placed inside an `<svg>`**. Wherever the
     * author clicked on the picture, the browser put the selection in the nearest text position,
     * which is the paragraph beside the diagram, so `blockUnderCursor()` found nothing and the
     * dialog always opened empty - the diagram looked write-once even though its source was there
     * all along, in `data-mermaid`.
     *
     * Marking the wrapper `contenteditable="false"` turns it into a single object instead: one click
     * selects the whole diagram, Suppr deletes it, a context toolbar offers Modifier / Supprimer,
     * and a double click opens the source straight away. That is the behaviour an author already
     * knows from an image, which is the point - nothing here has to be learned or explained.
     *
     * The attribute exists **in the editor only**: it is stamped when content is parsed and taken
     * off again when it is serialized, so the stored page keeps exactly the markup it had before and
     * no migration is needed for the pages already written. (The server's sanitizer would drop it
     * anyway - `contenteditable` is not in `app.wiki_page_body`'s allowed attributes - but relying on
     * that would mean storing markup we know is wrong and trusting something else to clean it.)
     */
    registerMermaidEditing(editor) {
        editor.on('PreInit', () => {
            editor.parser.addAttributeFilter('data-mermaid', (nodes) => nodes.forEach((node) => {
                node.attr('contenteditable', 'false');
                node.attr('title', this.label('mermaidEditHint', 'Double-cliquez pour modifier ce schéma'));
            }));
            editor.serializer.addAttributeFilter('data-mermaid', (nodes) => nodes.forEach((node) => {
                node.attr('contenteditable', null);
                node.attr('title', null);
            }));
        });

        // The shortest path of the three, and the one most authors will find on their own.
        editor.on('dblclick', (event) => {
            const block = event.target?.closest?.('[data-mermaid]');

            if (block) {
                this.openMermaidDialog(editor, block);
            }
        });

        editor.ui.registry.addButton('mermaidedit', {
            text: this.label('mermaidEdit', 'Modifier'),
            onAction: () => this.openMermaidDialog(editor, this.blockUnderCursor(editor, 'mermaid')),
        });
        editor.ui.registry.addButton('mermaidremove', {
            text: this.label('mermaidRemove', 'Supprimer'),
            onAction: () => {
                const block = this.blockUnderCursor(editor, 'mermaid');

                if (block) {
                    editor.undoManager.transact(() => editor.dom.remove(block));
                    editor.save();
                    editor.nodeChanged();
                }
            },
        });
        // Floating over the selected diagram, the way the image and table toolbars already do.
        editor.ui.registry.addContextToolbar('mermaidactions', {
            predicate: (node) => !!node?.getAttribute?.('data-mermaid'),
            items: 'mermaidedit mermaidremove',
            position: 'node',
            scope: 'node',
        });
    }

    /** Re-stamps the editor-only attributes on blocks that arrived after the parser ran. */
    markMermaidObjects(editor) {
        editor.dom.select('[data-mermaid]').forEach((block) => {
            editor.dom.setAttrib(block, 'contenteditable', 'false');
            editor.dom.setAttrib(block, 'title', this.label('mermaidEditHint', 'Double-cliquez pour modifier ce schéma'));
        });
    }

    // --- Shared dialog for the two source-carrying blocks ---------------------------------

    openSourceDialog(editor, { title, placeholder, target, kind, multiline = false, trailing = '', render }) {
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
            initialData: { source: target?.getAttribute(`data-${kind}`) ?? '' },
            buttons: [
                { type: 'cancel', text: this.label('cancel', 'Annuler') },
                {
                    type: 'submit',
                    // Naming the action is half of what tells the author which of the two they are
                    // doing - the pre-filled source being the other half.
                    text: target ? this.label('update', 'Mettre à jour') : this.label('insert', 'Insérer'),
                    primary: true,
                },
            ],
            onSubmit: async (dialog) => {
                const source = dialog.getData().source.trim();

                if ('' === source) {
                    dialog.close();

                    return;
                }

                try {
                    const html = await render(source);

                    if (target?.parentNode) {
                        // Rewriting the block **by reference**, not through the selection: that is
                        // what makes editing work at all, since a diagram is selected as an object
                        // and the caret is never inside it. setOuterHTML bypasses the content
                        // parser, so the editor-only attributes are stamped back on afterwards.
                        editor.undoManager.transact(() => editor.dom.setOuterHTML(target, html));
                        this.markMermaidObjects(editor);
                    } else {
                        editor.insertContent(html + trailing);
                    }

                    editor.save();
                    this.renderKatexIntoField();
                    editor.nodeChanged();
                    dialog.close();
                } catch (error) {
                    editor.notificationManager.open({ text: String(error.message ?? error), type: 'error' });
                }
            },
        });
    }

    /** The KaTeX/Mermaid block the selection sits in or on, when there is one. */
    blockUnderCursor(editor, kind) {
        return editor.dom.getParent(editor.selection.getNode(), `[data-${kind}]`);
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
