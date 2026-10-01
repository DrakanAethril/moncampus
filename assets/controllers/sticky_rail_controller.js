import { Controller } from '@hotwired/stimulus';

/*
 * Keeps a side rail in view while the page scrolls, without ever giving it a scrollbar of its own.
 *
 * A plain `position: sticky; top: 12px` is only safe on a rail shorter than the window: a taller one
 * sticks with its foot below the fold, and that foot stays out of reach until the very end of the
 * page. A bounded rail (`max-height` + `overflow`) fixes that by scrolling inside itself, which is
 * exactly what a filter form must not do.
 *
 * So the offset is computed: the rail's top sticks at `margin` while it fits in the window, and at
 * `window height - rail height - margin` (a negative top) once it does not - the rail then scrolls
 * with the page until its foot reaches the bottom of the window, and stays pinned there. Both ends
 * are reachable by the page's own scrollbar: the foot on the way down, the top on the way back up.
 *
 * The rail is only made sticky through the `is-sticky` class this controller adds, so without
 * JavaScript it simply flows with the page. Where the stylesheet keeps it static (the narrow,
 * one-column layout), the class is harmless.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { margin: { type: Number, default: 12 } };

    connect() {
        this.onResize = () => this.#place();
        this.observer = new ResizeObserver(this.onResize);
        this.observer.observe(this.element);
        window.addEventListener('resize', this.onResize);
        this.element.classList.add('is-sticky');
        this.#place();
    }

    disconnect() {
        this.observer.disconnect();
        window.removeEventListener('resize', this.onResize);
        this.element.classList.remove('is-sticky');
        this.element.style.top = '';
    }

    #place() {
        const margin = this.marginValue;
        const top = Math.min(margin, window.innerHeight - this.element.offsetHeight - margin);

        this.element.style.top = `${top}px`;
    }
}
