import { Controller } from '@hotwired/stimulus';

// Data-model screen (/technical/data-model): switches between the four notation panels and turns
// the server-generated Mermaid sources into SVG. Mermaid is vendored under assets/mermaid/ and
// loaded by a plain deferred <script> in the page template (it is far too heavy to sit in the
// importmap for every page), so the first render waits for the global to appear.
//
// Each diagram is a <template data-data-model-target="source"> next to an empty output <div> -
// going through template.content decodes the HTML-escaped source without ever parsing it as DOM.
// Panels are shown with the plain `hidden` attribute; none of these nodes carries a Bootstrap
// display utility, which would override it.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['tab', 'panel', 'source'];

    connect() {
        this.rendered = new Set();
        this.sequence = 0;
        // Re-render every already-drawn diagram when the user flips the theme: Mermaid bakes its
        // colors into the SVG at render time, nothing there follows CSS variables.
        this.themeObserver = new MutationObserver(() => {
            this.rendered.clear();
            this.withMermaid((mermaid) => this.renderVisible(mermaid));
        });
        this.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
        this.withMermaid((mermaid) => this.renderVisible(mermaid));
    }

    disconnect() {
        this.themeObserver.disconnect();
    }

    select(event) {
        const key = event.currentTarget.dataset.key;
        this.tabTargets.forEach((tab) => tab.classList.toggle('is-active', tab.dataset.key === key));
        this.panelTargets.forEach((panel) => { panel.hidden = panel.dataset.key !== key; });
        this.withMermaid((mermaid) => this.renderVisible(mermaid));
    }

    // The vendored bundle exposes window.mermaid once its deferred script has run; poll briefly
    // rather than racing it.
    withMermaid(callback, attempt = 0) {
        if (window.mermaid) {
            callback(window.mermaid);
        } else if (attempt < 100) {
            setTimeout(() => this.withMermaid(callback, attempt + 1), 50);
        }
    }

    async renderVisible(mermaid) {
        const dark = document.documentElement.dataset.bsTheme === 'dark';
        mermaid.initialize({
            startOnLoad: false,
            theme: dark ? 'dark' : 'neutral',
            securityLevel: 'strict',
            maxTextSize: 500000,
            maxEdges: 2000,
            flowchart: { useMaxWidth: false, htmlLabels: true },
            er: { useMaxWidth: false },
            class: { useMaxWidth: false },
        });
        for (const source of this.sourceTargets) {
            const panel = source.closest('[data-data-model-target="panel"]');
            if ((panel && panel.hidden) || this.rendered.has(source)) {
                continue;
            }
            const output = source.nextElementSibling;
            try {
                const { svg } = await mermaid.render(`dataModelDiagram${this.sequence++}`, source.content.textContent.trim());
                output.innerHTML = svg;
                this.rendered.add(source);
            } catch (error) {
                // A diagram that fails to parse must not take the panel down with it - show the
                // source instead so the page stays useful (and debuggable).
                output.innerHTML = '';
                const fallback = document.createElement('pre');
                fallback.textContent = source.content.textContent.trim();
                output.append(fallback);
                this.rendered.add(source);
            }
        }
    }
}
