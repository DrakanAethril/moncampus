import { Controller } from '@hotwired/stimulus';

/**
 * Makes a slow PDF-export link say so. Bound to the link itself (see the "Exporter en PDF" buttons
 * of the Livret Alternant, whose server side renders the booklet through Gotenberg and can take
 * several seconds): the click is taken over, the file is fetched in the background, and the button
 * shows a spinner plus a "génération en cours" label until the bytes are in - instead of looking
 * dead while the browser waits on a navigation that shows nothing.
 *
 * The download itself is then handed back to the browser through an object URL, so the user still
 * gets their normal "save file" behaviour and the server-side filename
 * (Content-Disposition) is preserved.
 *
 * Anything that isn't a PDF coming back - the export's own Gotenberg-unavailable redirect, which
 * answers with the HTML page carrying an error flash, or any network failure - falls back to
 * following the link for real, so that flash is actually shown rather than swallowed here.
 *
 * It also drives a menu of exports rather than a single link: bound to the wrapping element, with
 * the action on each entry, it exports whichever entry was clicked and shows the spinner on the
 * `trigger` target - the "Export partiel PDF" dropdown of the Livret Alternant, whose menu closes
 * on click and would otherwise take the only feedback down with it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['spinner', 'label', 'trigger'];

    static values = {
        loadingLabel: String,
        fallbackFilename: { type: String, default: 'document.pdf' },
    };

    async download(event) {
        if (this.loading) {
            return;
        }

        // The clicked entry when this controller wraps a menu, the element itself when it IS the
        // link - the same value in that case, since the action is then bound to the link.
        const href = event.currentTarget?.href ?? this.element.href;

        // Also what keeps Turbo Drive out of it: Turbo skips clicks whose default was prevented.
        event.preventDefault();
        this.#setLoading(true);

        try {
            const response = await fetch(href, { headers: { Accept: 'application/pdf' } });

            if (!response.ok || !(response.headers.get('content-type') ?? '').includes('application/pdf')) {
                window.location.href = href;

                return;
            }

            this.#save(await response.blob(), this.#filenameFrom(response));
        } catch {
            window.location.href = href;
        } finally {
            this.#setLoading(false);
        }
    }

    #setLoading(loading) {
        const button = this.hasTriggerTarget ? this.triggerTarget : this.element;

        this.loading = loading;
        button.classList.toggle('disabled', loading);
        button.setAttribute('aria-busy', loading ? 'true' : 'false');

        if (this.hasSpinnerTarget) {
            this.spinnerTarget.classList.toggle('d-none', !loading);
        }

        if (this.hasLabelTarget && this.hasLoadingLabelValue) {
            if (loading) {
                this.idleLabel = this.labelTarget.textContent;
                this.labelTarget.textContent = this.loadingLabelValue;
            } else if (this.idleLabel !== undefined) {
                this.labelTarget.textContent = this.idleLabel;
            }
        }
    }

    #save(blob, filename) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        // Only after the click has been handed off - revoking synchronously cancels the download
        // in some browsers.
        setTimeout(() => URL.revokeObjectURL(url), 10000);
    }

    #filenameFrom(response) {
        const disposition = response.headers.get('content-disposition') ?? '';
        // RFC 5987 form first (filename*=UTF-8''...), then the plain quoted one - Symfony's
        // HeaderUtils::makeDisposition() emits both when the name isn't pure ASCII.
        const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i);
        if (encoded) {
            try {
                return decodeURIComponent(encoded[1]);
            } catch {
                // Malformed percent-encoding - fall through to the plain form below.
            }
        }

        return disposition.match(/filename="?([^";]+)"?/i)?.[1] ?? this.fallbackFilenameValue;
    }
}
