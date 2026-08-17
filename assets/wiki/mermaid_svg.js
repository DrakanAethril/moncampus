/**
 * Turns what Mermaid renders into a **self-contained** SVG: one that carries its own appearance in
 * `style` attributes and uses only the elements the wiki's sanitizer keeps.
 *
 * This is the fix for "the preview is right, the saved page is wrong", and the reason is structural
 * rather than accidental. Mermaid styles its diagram with a `<style>` block embedded in the SVG, and
 * `config/packages/html_sanitizer.yaml` cannot keep that element - the component drops it
 * unconditionally, whatever `allow_elements` says (App\Tests\Functional\WikiSanitizerTest pins it).
 * So the editor showed a styled picture and the database received an unstyled one: shapes with no
 * fill (black), labels in the wrong font and therefore off their boxes, and - on the diagram types
 * Mermaid builds out of `<symbol>` or `<filter>` - whole pieces missing. Reproducing that stylesheet
 * by hand in app.css only ever covered flowcharts, which is why every other kind came out wrong.
 *
 * Measured, and what makes this work: the sanitizer keeps a `style` **attribute** whole, including
 * `url(#marker)` references and quoted font names. So every declaration is moved there while the
 * stylesheet is still applied, and the stylesheet is then dropped here rather than server-side.
 *
 * Two properties follow from doing it in the browser, and both matter:
 *
 *  - **the preview equals the saved page**, because the editor is handed the same normalized markup
 *    the server will store - nothing is left for the sanitizer to remove;
 *  - **nothing is needed to read it**: no stylesheet, no JavaScript, which is what keeps the diagram
 *    identical inside Gotenberg's Chromium for the PDF export.
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Mirrors the `app.wiki_page_body` allowlist deliberately: what is dropped here is exactly what the
 * server would drop. `symbol`, `filter`/`feDropShadow` and `clipPath` are the ones Mermaid actually
 * emits - drop shadows and clips are decoration, and a clip that survived while its `<clipPath>`
 * did not would hide the shape it was clipping.
 */
const KEPT_ELEMENTS = new Set([
    'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
    'text', 'tspan', 'defs', 'marker',
]);

/** Everything that decides how a shape or a label looks. Geometry is left in its own attributes. */
const INLINED_PROPERTIES = [
    'fill', 'fill-opacity', 'fill-rule',
    'stroke', 'stroke-width', 'stroke-dasharray', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity',
    'opacity', 'color', 'visibility',
    'marker-start', 'marker-mid', 'marker-end',
    'font-family', 'font-size', 'font-style', 'font-weight',
    'text-anchor', 'dominant-baseline', 'letter-spacing', 'paint-order',
];

/**
 * Written even when they match the document's default, on text only. The default is read from the
 * page the editor runs in, and the diagram is later read somewhere else entirely - another theme, or
 * Gotenberg's Chromium, which has neither the app's stylesheet nor its fonts. A label whose size is
 * left to be inherited moves off the box Mermaid measured for it.
 */
const ALWAYS_INLINED = new Set(['font-family', 'font-size', 'font-weight']);
const TEXT_ELEMENTS = new Set(['text', 'tspan']);

/** References to elements this app does not store - keeping them would hide the shape that has them. */
const DROPPED_ATTRIBUTES = ['filter', 'clip-path', 'mask'];

function offscreenHost() {
    const host = document.createElement('div');
    // Off to the side rather than hidden: `display:none` gives every computed style the value of a
    // box that is not laid out, and `visibility:hidden` would be inherited and then inlined.
    host.setAttribute('style', 'position:absolute;left:-10000px;top:0;width:1400px;pointer-events:none');

    return host;
}

/**
 * What the same element would look like with no stylesheet at all - the yardstick that keeps the
 * output small. Without it every element would carry two dozen declarations it did not need.
 */
function defaultsFor(tag, cache, host) {
    if (!cache.has(tag)) {
        const canvas = document.createElementNS(SVG_NS, 'svg');
        const probe = document.createElementNS(SVG_NS, tag);
        canvas.appendChild(probe);
        host.appendChild(canvas);

        const computed = window.getComputedStyle(probe);
        const values = {};
        INLINED_PROPERTIES.forEach((property) => { values[property] = computed.getPropertyValue(property); });

        host.removeChild(canvas);
        cache.set(tag, values);
    }

    return cache.get(tag);
}

/** The declarations already written on the element that this pass does not own (Mermaid's own). */
function keptInlineDeclarations(element) {
    const declarations = [];
    const inline = element.style;

    for (let index = 0; index < inline.length; index += 1) {
        const property = inline.item(index);

        if (!INLINED_PROPERTIES.includes(property)) {
            declarations.push(`${property}:${inline.getPropertyValue(property)}`);
        }
    }

    return declarations;
}

/**
 * Makes the picture fit its column instead of being cut off at the edge. Mermaid writes an inline
 * `max-width` equal to the diagram's natural width, which is what truncated the wide ones: an inline
 * declaration beats app.css, so `.cm-mermaid svg { max-width: 100% }` never applied. `min()` keeps
 * both intentions - never wider than the column, never blown up past its own size.
 */
function normalizeRoot(svg) {
    // The viewBox first and the attribute only as a fallback: Mermaid writes `width="100%"`, and
    // reading that as a number gives a natural width of 100 - a diagram capped at 100 pixels, which
    // is exactly what it looked like.
    const viewBox = svg.getAttribute('viewBox');
    const width = svg.getAttribute('width') ?? '';
    const natural = (viewBox ? Number.parseFloat(viewBox.split(/[\s,]+/)[2]) : 0)
        || (width.endsWith('%') ? 0 : Number.parseFloat(width));

    svg.setAttribute('width', '100%');
    svg.removeAttribute('height');

    const declarations = keptInlineDeclarations(svg).filter((declaration) => !declaration.startsWith('max-width'));
    declarations.push(natural ? `max-width:min(100%, ${Math.ceil(natural)}px)` : 'max-width:100%');
    declarations.push('height:auto');
    svg.setAttribute('style', declarations.join(';'));
}

/**
 * @param {string} markup the `<svg>…</svg>` string Mermaid just rendered
 * @returns {string} the same diagram, carrying its own styles and nothing the sanitizer refuses
 */
export function selfContainedSvg(markup) {
    const host = offscreenHost();
    document.body.appendChild(host);

    try {
        host.insertAdjacentHTML('beforeend', markup);
        const svg = host.querySelector('svg');

        if (!svg) {
            return markup;
        }

        // Read everything first: the stylesheet has to still be there while the styles are computed,
        // and the elements carrying them have to still be in the document.
        const cache = new Map();
        const styles = new Map();

        svg.querySelectorAll('*').forEach((element) => {
            const tag = element.tagName.toLowerCase();

            if (!KEPT_ELEMENTS.has(tag)) {
                return;
            }

            const computed = window.getComputedStyle(element);
            const defaults = defaultsFor(tag, cache, host);
            const declarations = keptInlineDeclarations(element);

            INLINED_PROPERTIES.forEach((property) => {
                const value = computed.getPropertyValue(property);

                if ('' === value) {
                    return;
                }

                if (value !== defaults[property] || (TEXT_ELEMENTS.has(tag) && ALWAYS_INLINED.has(property))) {
                    declarations.push(`${property}:${value}`);
                }
            });

            styles.set(element, declarations.join(';'));
        });

        // Then rewrite: styles on, superseded attributes off, refused elements out.
        styles.forEach((declarations, element) => {
            INLINED_PROPERTIES.forEach((property) => element.removeAttribute(property));
            DROPPED_ATTRIBUTES.forEach((attribute) => element.removeAttribute(attribute));

            if ('' === declarations) {
                element.removeAttribute('style');
            } else {
                element.setAttribute('style', declarations);
            }
        });

        [...svg.querySelectorAll('*')]
            .filter((element) => !KEPT_ELEMENTS.has(element.tagName.toLowerCase()))
            .forEach((element) => element.remove());

        DROPPED_ATTRIBUTES.forEach((attribute) => svg.removeAttribute(attribute));
        normalizeRoot(svg);

        return svg.outerHTML;
    } finally {
        host.remove();
    }
}

/**
 * The appearance every stored diagram is rendered with - **always this one**, whatever theme the
 * author happens to be working in.
 *
 * The colours are baked into the picture at insert time, so they are also the colours it is read
 * with: in light mode, in dark mode and in the PDF. Rendering Mermaid's `dark` theme for an author
 * working at night therefore did not produce a dark-mode diagram, it produced a permanently dark
 * one - which is where the black boxes came from. Dark mode gives the diagram a light surface to sit
 * on instead (`.cm-mermaid` in app.css), the way it would with a photograph.
 *
 * The values are the light `--cm-*` tokens, the same four that templates/wiki/pdf/export.html.twig
 * already spells out.
 */
export const DIAGRAM_THEME = {
    theme: 'base',
    themeVariables: {
        background: '#ffffff',
        primaryColor: '#eef5fb',
        primaryBorderColor: '#1B6BA8',
        primaryTextColor: '#1b2430',
        secondaryColor: '#f2f5f7',
        tertiaryColor: '#f7f9fa',
        lineColor: '#5b6c79',
        textColor: '#1b2430',
        fontFamily: '"Source Sans 3", system-ui, -apple-system, sans-serif',
        fontSize: '14px',
    },
};
