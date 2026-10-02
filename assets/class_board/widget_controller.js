import { Controller } from '@hotwired/stimulus';

// The base of every widget controller of the virtual board (design/validated/tableau-virtuel.md).
//
// A widget's element carries its stored configuration in `data-config`; the board controller reads
// it, with the position, at every save. A widget never saves anything itself: it writes its new
// configuration back with store() and the board decides when to send the whole document.
//
// Three things arrive from the board controller, all as events on the widget's own element or on
// the window, so that a widget never needs to know the board exists:
// - `class-board:tick` (window) - the board's one clock loop, five times a second; a widget that
//   moves with time implements tick();
// - `class-board:shortcut` - a key of the board meant for the last widget of this type touched
//   (Space for a timer, T for a draw, the arrows for the session plan); implement shortcut(key);
// - `class-board:configured` - a setting changed in the gear panel; implement configure().
export default class WidgetController extends Controller {
    connect() {
        // Read once: a Stimulus-style value would re-parse the attribute at every access.
        this.config = readConfig(this.element);
        this.reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        this.onTick = () => this.tick?.();
        this.onShortcut = (event) => this.shortcut?.(event.detail.key);
        this.onConfigured = () => {
            this.config = readConfig(this.element);
            this.configure?.();
        };
        window.addEventListener('class-board:tick', this.onTick);
        this.element.addEventListener('class-board:shortcut', this.onShortcut);
        this.element.addEventListener('class-board:configured', this.onConfigured);

        this.setup?.();
    }

    disconnect() {
        window.removeEventListener('class-board:tick', this.onTick);
        this.element.removeEventListener('class-board:shortcut', this.onShortcut);
        this.element.removeEventListener('class-board:configured', this.onConfigured);
        this.teardown?.();
    }

    // Writes part of the configuration back onto the element and tells the board there is
    // something to save.
    store(patch) {
        this.config = { ...this.config, ...patch };
        this.element.dataset.config = JSON.stringify(this.config);
        this.element.dispatchEvent(new CustomEvent('class-board:changed', { bubbles: true }));
    }

    // A change the server has to draw - a saved draw or a lot chosen: the board fetches the widget
    // again and puts it in place of this one.
    redraw(patch) {
        this.config = { ...this.config, ...patch };
        this.element.dataset.config = JSON.stringify(this.config);
        this.element.dispatchEvent(new CustomEvent('class-board:rerender', { bubbles: true }));
    }
}

export function readConfig(element) {
    try {
        const config = JSON.parse(element.dataset.config || '{}');
        return config && typeof config === 'object' && !Array.isArray(config) ? config : {};
    } catch {
        return {};
    }
}

export const pad = (number) => String(number).padStart(2, '0');

export function minutesSeconds(seconds) {
    const total = Math.max(0, Math.ceil(seconds));
    return `${pad(Math.floor(total / 60))}:${pad(total % 60)}`;
}

let audioContext = null;

// Three short beeps - the timer's alarm. An audio context may be refused before the page has been
// touched; the flashing widget is then the only alarm, which is why there is one.
export function beep() {
    try {
        audioContext ||= new (window.AudioContext || window.webkitAudioContext)();
        [0, 0.35, 0.7].forEach((offset) => {
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            const start = audioContext.currentTime + offset;
            oscillator.frequency.value = 880;
            oscillator.connect(gain);
            gain.connect(audioContext.destination);
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.25, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.25);
            oscillator.start(start);
            oscillator.stop(start + 0.3);
        });
    } catch {
        // No sound available: the widget still flashes.
    }
}

export function randomItem(list) {
    return list[Math.floor(Math.random() * list.length)];
}
