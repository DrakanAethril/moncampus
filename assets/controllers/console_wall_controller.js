import { Controller } from '@hotwired/stimulus';

/**
 * Le mur de consoles : une tuile par machine du lot, rafraîchies en continu.
 *
 * **Une tuile par requête, quatre en parallèle, cycle de 15 s.** C'est ce qui rend le mur abordable
 * sans rien ajouter : vingt-quatre tuiles tiennent en deux secondes de mur, chaque requête est
 * courte, et les fils du serveur tournent. Une requête unique qui dessinerait tout le mur
 * immobiliserait un fil pendant la somme de vingt-quatre poignées de main SSH.
 *
 * Une tuile injoignable dit « injoignable » et n'empêche pas les sept autres — la même règle que la
 * liste des machines, qui ne refuse pas de se dessiner parce qu'un hôte est tombé.
 */
export default class extends Controller {
    static targets = ['tile', 'freshness'];

    static values = { tileUrl: String, cycle: Number, parallel: Number };

    connect() {
        this.running = true;
        this.labels = {
            off: this.element.dataset.offLabel ?? 'éteinte',
            unknown: this.element.dataset.unknownLabel ?? 'injoignable',
            idle: this.element.dataset.idleLabel ?? 'pas de console ouverte',
        };
        this.loop();
    }

    disconnect() {
        this.running = false;
        window.clearTimeout(this.timer);
    }

    refresh() {
        window.clearTimeout(this.timer);
        this.loop();
    }

    async loop() {
        if (!this.running) {
            return;
        }

        await this.sweep();

        if (this.running) {
            this.timer = window.setTimeout(() => this.loop(), this.cycleValue || 15000);
        }
    }

    /** Quatre de front : le mur avance par vagues plutôt que d'ouvrir vingt-quatre requêtes d'un coup. */
    async sweep() {
        const tiles = [...this.tileTargets];
        const width = Math.max(1, this.parallelValue || 4);

        for (let i = 0; i < tiles.length; i += width) {
            await Promise.all(tiles.slice(i, i + width).map((tile) => this.load(tile)));
        }

        this.stamp();
    }

    async load(tile) {
        const vmid = tile.dataset.vmid;
        const body = tile.querySelector('.cm-console__tilebody');
        const dot = tile.querySelector('.cm-console__tiledot');
        const age = tile.querySelector('.cm-console__tileage');

        try {
            const response = await fetch(this.tileUrlValue.replace(/0$/, vmid), { headers: { 'X-Requested-With': 'fetch' } });

            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const tileData = await response.json();
            dot.dataset.state = tileData.state;
            tile.classList.toggle('is-off', !tileData.ok);
            body.textContent = tileData.ok ? tileData.lines : this.labels[tileData.state] ?? this.labels.unknown;
            age.textContent = tileData.ok ? this.element.dataset.justNowLabel ?? '' : '—';
        } catch (error) {
            // Une tuile qui échoue ne prend pas les autres avec elle.
            dot.dataset.state = 'unknown';
            tile.classList.add('is-off');
            body.textContent = this.labels.unknown;
            age.textContent = '—';
        }
    }

    stamp() {
        if (this.hasFreshnessTarget) {
            this.freshnessTarget.textContent = new Date().toLocaleTimeString();
        }
    }
}
