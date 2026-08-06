import { Controller } from '@hotwired/stimulus';

/*
 * Keeps the navbar's nested submenus (`.dropend` inside a `.dropdown-menu`) inside the viewport.
 *
 * Bootstrap deliberately skips Popper positioning for any dropdown inside a `.navbar`
 * (`Dropdown._detectNavbar()` disables Popper's `applyStyles` modifier and stamps the menu with
 * `data-bs-popper="static"`), so nested submenus are placed by CSS alone - `.dropend .dropdown-menu`
 * is pinned to `top: 0` of its item and always grows downward. On a long level-2 list (e.g. Campus >
 * one entry per Program), an item low in that list opens a ~500px tall submenu well past the bottom
 * of the screen, with no flip/preventOverflow behaviour to save it since Popper never applies styles.
 *
 * The fix shifts the submenu up by exactly what overflows rather than flipping it bottom-aligned to
 * its item: those submenus are nearly as tall as the viewport itself, so a plain flip would just move
 * the overflow to the top edge. Shifting caps at the top of the screen, and a submenu taller than the
 * screen gets a max-height and scrolls.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { margin: { type: Number, default: 8 } };

    connect() {
        // Bootstrap events bubble, so a single listener on the navbar covers every nesting level.
        this.onShown = (event) => this.#fit(event.target);
        this.element.addEventListener('shown.bs.dropdown', this.onShown);
    }

    disconnect() {
        this.element.removeEventListener('shown.bs.dropdown', this.onShown);
    }

    #fit(toggle) {
        const wrapper = toggle.parentElement;

        if (!wrapper || !wrapper.classList.contains('dropend')) {
            return;
        }

        const menu = wrapper.querySelector(':scope > .dropdown-menu');

        // Below the navbar-expand breakpoint the collapsed menu stacks statically (accordion style):
        // nothing to reposition there, and an offset would break the stacking.
        if (!menu || window.getComputedStyle(menu).position !== 'absolute') {
            return;
        }

        menu.style.top = '';
        menu.style.maxHeight = '';
        menu.style.overflowY = '';

        const margin = this.marginValue;
        const available = window.innerHeight - margin * 2;
        const anchorTop = wrapper.getBoundingClientRect().top;
        let height = menu.offsetHeight;

        if (height > available) {
            menu.style.maxHeight = `${available}px`;
            menu.style.overflowY = 'auto';
            height = available;
        }

        const overflow = anchorTop + height - (window.innerHeight - margin);

        if (overflow > 0) {
            menu.style.top = `-${Math.min(overflow, anchorTop - margin)}px`;
        }
    }
}
