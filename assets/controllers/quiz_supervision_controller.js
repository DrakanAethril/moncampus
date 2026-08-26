import { Controller } from '@hotwired/stimulus';

// The page-event journal of a supervised passation, browser side.
//
// It blocks nothing. No right-click disabled, no copy intercepted, no key swallowed: blocking copy
// in JavaScript is theatre - three seconds to work around, and a real nuisance to the student who
// needs to select in order to read. Journaling it costs nobody anything and is worth infinitely
// more.
//
// **Everything goes out through navigator.sendBeacon(), never fetch().** The event that matters
// most is the one fired as the tab closes, and that is exactly the request a browser abandons when
// it is a fetch. A beacon is queued by the browser and survives the page.
//
// The server writes the timestamps. What is sent is the *fact* and, only for a return, how long the
// client believes it was away - read only when the departure beacon was lost, and bounded there by
// instants the application knows (App\Service\QuizSupervisionJournal).
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        url: String,
        key: String,
        position: Number,
        // Below this, a window narrower than the screen is just a small screen, not a side-by-side.
        shrinkRatio: { type: Number, default: 0.6 },
    };

    connect() {
        this.leftAt = null;
        this.blurredAt = null;
        // Measured on the way in, so a window already small before the exam started is neutralised
        // rather than reported forever.
        this.wasWide = window.outerWidth >= window.screen.availWidth * this.shrinkRatioValue;
        this.shrunkReported = false;

        this.onVisibility = () => this.visibilityChanged();
        this.onBlur = () => this.windowBlurred();
        this.onFocus = () => this.windowFocused();
        this.onFullscreen = () => this.fullscreenChanged();
        this.onResize = () => this.resized();
        this.onPaste = () => this.send('paste');
        this.onCopy = () => this.send('statement_copied');

        document.addEventListener('visibilitychange', this.onVisibility);
        window.addEventListener('blur', this.onBlur);
        window.addEventListener('focus', this.onFocus);
        document.addEventListener('fullscreenchange', this.onFullscreen);
        window.addEventListener('resize', this.onResize);
        this.element.addEventListener('paste', this.onPaste);
        this.element.addEventListener('copy', this.onCopy);
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibility);
        window.removeEventListener('blur', this.onBlur);
        window.removeEventListener('focus', this.onFocus);
        document.removeEventListener('fullscreenchange', this.onFullscreen);
        window.removeEventListener('resize', this.onResize);
        this.element.removeEventListener('paste', this.onPaste);
        this.element.removeEventListener('copy', this.onCopy);
    }

    visibilityChanged() {
        if (document.visibilityState === 'hidden') {
            this.leftAt = Date.now();
            this.send('page_hidden');

            return;
        }
        this.send('page_visible', this.since(this.leftAt));
        this.leftAt = null;
    }

    // Another window coming in front WITHOUT changing tab - reading a site next to the exam.
    // visibilitychange never sees that one, which is why both families are listened to.
    windowBlurred() {
        this.blurredAt = Date.now();
        this.send('window_blur');
    }

    windowFocused() {
        this.send('window_focus', this.since(this.blurredAt));
        this.blurredAt = null;
    }

    fullscreenChanged() {
        if (!document.fullscreenElement) {
            this.send('fullscreen_exit');
        }
    }

    resized() {
        const isWide = window.outerWidth >= window.screen.availWidth * this.shrinkRatioValue;
        if (this.wasWide && !isWide && !this.shrunkReported) {
            this.shrunkReported = true;
            this.send('window_shrunk');
        }
        if (isWide) {
            this.shrunkReported = false;
        }
    }

    since(startedAt) {
        return startedAt === null ? null : Date.now() - startedAt;
    }

    send(type, durationMs = null) {
        const payload = JSON.stringify({
            key: this.keyValue,
            type,
            position: this.positionValue,
            durationMs,
        });

        // A Blob rather than a plain string: sendBeacon sends a string as text/plain, and the
        // endpoint reads JSON.
        navigator.sendBeacon?.(this.urlValue, new Blob([payload], { type: 'application/json' }));
    }
}
