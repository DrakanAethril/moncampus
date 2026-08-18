import { Controller } from '@hotwired/stimulus';

/**
 * The two actions of the address registry: adopt what the scan discovered, and free what it found
 * orphaned.
 *
 * Neither writes anything to Proxmox - they only bring the register back into agreement with what
 * exists - which is why the card says so in its footer rather than leaving it to be guessed. Only
 * the release confirms: adopting is reversible by releasing, while freeing an address puts it back
 * on offer to the next machine.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        token: String,
        adoptUrl: String,
        releaseUrl: String,
        releaseConfirm: String,
    };

    adopt(event) {
        return this.#post(this.adoptUrlValue, { ip: event.currentTarget.dataset.ip });
    }

    adoptAll() {
        // No ip: the endpoint re-scans and adopts every discovery it still finds.
        return this.#post(this.adoptUrlValue, {});
    }

    release(event) {
        if (!window.confirm(this.releaseConfirmValue)) {
            return Promise.resolve();
        }

        return this.#post(this.releaseUrlValue, { ip: event.currentTarget.dataset.ip });
    }

    async #post(url, body) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.tokenValue, 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });

            if (!response.ok) {
                throw new Error(`Unexpected response status: ${response.status}`);
            }

            // Reloaded with the scan on: everything on this page - the gaps, the occupancy meter,
            // the register - just moved, and patching one row would leave the other two lying.
            const target = new URL(window.location.href);
            target.searchParams.set('scan', '1');
            window.location.href = target.toString();
        } catch {
            window.location.reload();
        }
    }
}
