import { Controller } from '@hotwired/stimulus';
import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.min.css';

/**
 * La console d'une machine : un vrai terminal, dont le pseudo-terminal vit dans la machine.
 *
 * Le navigateur ne tient rien d'autre qu'un xterm.js. Chaque échange est un POST ordinaire qui
 * emporte les octets frappés et l'empreinte de l'écran déjà affiché ; le serveur les pousse dans le
 * tmux de la machine, y regarde l'écran jusqu'à huit secondes, et répond dès qu'il change. Le
 * navigateur relance aussitôt.
 *
 * Trois propriétés à ne pas défaire :
 *
 *   - **Les octets partent tels quels.** `onData` rend ce que xterm.js a produit, encodé en UTF-8
 *     puis en hexadécimal : aucune table de correspondance de touches, donc aucune touche oubliée.
 *   - **Une frappe annule l'attente en cours**, mais jamais une requête qui portait déjà des
 *     touches — celles-là sont peut-être déjà arrivées dans la machine, et les annuler les perdrait.
 *   - **La mesure voyage avec les touches.** Les colonnes et les lignes sont mesurées ici et
 *     envoyées à chaque échange ; sans cela, la fenêtre tmux et la boîte affichée divergent et les
 *     lignes se coupent au mauvais endroit.
 */
export default class extends Controller {
    static targets = ['screen', 'state', 'size', 'overlay', 'overlayText'];

    static values = {
        exchangeUrl: String,
        closeUrl: String,
        token: String,
        connectedText: String,
        lostText: String,
    };

    connect() {
        this.pending = [];
        this.digest = '';
        this.running = true;
        this.inflight = null;
        this.inflightCarriedKeys = false;
        this.released = false;

        this.terminal = new Terminal({
            // Sombre quel que soit le thème du lecteur : les couleurs ANSI sont choisies par les
            // programmes en supposant un fond sombre, et un panneau clair en rend la moitié
            // illisible. C'est l'exception au thème, et elle est unique.
            theme: { background: '#0a121a', foreground: '#c9d6e1', cursor: '#c9d6e1' },
            fontFamily: "'SF Mono', 'JetBrains Mono', 'Cascadia Mono', ui-monospace, Menlo, Consolas, monospace",
            fontSize: this.storedFontSize(),
            cursorBlink: true,
            // Aucun défilement local : chaque échange rend l'écran entier, et l'historique vit dans
            // le tmux de la machine — c'est lui que Ctrl+F relira.
            scrollback: 0,
        });

        this.fit = new FitAddon();
        this.terminal.loadAddon(this.fit);
        this.terminal.open(this.screenTarget);
        this.fit.fit();

        this.terminal.onData((data) => this.type(this.encode(data)));
        this.terminal.onBinary((data) => this.type(this.encodeBinary(data)));

        this.onResize = () => this.resize();
        window.addEventListener('resize', this.onResize);

        this.onLeave = () => this.release();
        window.addEventListener('pagehide', this.onLeave);

        this.terminal.focus();
        this.pump();
    }

    disconnect() {
        this.running = false;
        window.removeEventListener('resize', this.onResize);
        window.removeEventListener('pagehide', this.onLeave);
        this.inflight?.abort();
        this.release();
        this.terminal?.dispose();
    }

    /** Un tour de boucle = un échange long. Elle ne s'arrête qu'en quittant l'écran. */
    async pump() {
        while (this.running) {
            const keys = this.pending.splice(0, this.pending.length).join('');
            const controller = new AbortController();
            this.inflight = controller;
            this.inflightCarriedKeys = keys !== '';

            let answer;

            try {
                const response = await fetch(this.exchangeUrlValue, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                    body: JSON.stringify({
                        keys,
                        since: this.digest,
                        columns: this.terminal.cols,
                        rows: this.terminal.rows,
                    }),
                    signal: controller.signal,
                });

                if (!response.ok) {
                    this.setState('lost', this.lostTextValue);
                    await this.pause(3000);
                    continue;
                }

                answer = await response.json();
            } catch (error) {
                if (controller.signal.aborted) {
                    // Une frappe a coupé l'attente : on repart immédiatement avec elle.
                    continue;
                }

                this.setState('lost', this.lostTextValue);
                await this.pause(3000);
                continue;
            } finally {
                this.inflight = null;
            }

            if (answer.ok) {
                this.paint(answer);
                continue;
            }

            // Les états dégradés : la machine est éteinte, elle démarre encore, la console est en
            // préparation. Aucun n'est une erreur SSH montrée telle quelle — chacun donne le geste
            // suivant ou repart tout seul.
            this.degrade(answer);

            if (answer.state === 'off' || answer.state === 'noDoor' || answer.state === 'noConsole') {
                this.running = false;
                return;
            }

            await this.pause(2000);
        }
    }

    paint(answer) {
        this.digest = answer.digest;

        if (answer.columns && answer.rows && (answer.columns !== this.terminal.cols || answer.rows !== this.terminal.rows)) {
            // La machine fait foi : l'écran reçu fait exactement cette taille, et l'afficher dans
            // une boîte d'une autre largeur est précisément le bug des lignes coupées au mauvais
            // endroit.
            this.terminal.resize(answer.columns, answer.rows);
        }

        // Repeint entier : chaque échange rend l'écran complet, jamais un delta. Le curseur est
        // reposé après, parce qu'il n'est pas dans le texte du panneau.
        const screen = String(answer.pane ?? '').split('\n').join('\r\n');
        const home = '\u001b[0m\u001b[H\u001b[2J';
        const cursor = `\u001b[${(answer.cursorY ?? 0) + 1};${(answer.cursorX ?? 0) + 1}H`;
        this.terminal.write(`${home}${screen}${cursor}`);

        this.setState('live', this.connectedTextValue);
        this.hideOverlay();
        this.showSize();
    }

    degrade(answer) {
        this.setState(answer.state ?? 'lost', answer.message ?? this.lostTextValue);
        this.showOverlay(answer.message ?? this.lostTextValue, answer.state);
    }

    /** Les octets d'une frappe, en hexadécimal. UTF-8, donc les accents et les collages passent. */
    encode(data) {
        return Array.from(new TextEncoder().encode(data))
            .map((byte) => byte.toString(16).padStart(2, '0'))
            .join('');
    }

    encodeBinary(data) {
        let hex = '';

        for (let i = 0; i < data.length; i += 1) {
            hex += (data.charCodeAt(i) & 0xff).toString(16).padStart(2, '0');
        }

        return hex;
    }

    type(hex) {
        if (hex === '') {
            return;
        }

        this.pending.push(hex);

        // On n'annule que l'attente. Une requête qui portait des touches est peut-être déjà en
        // train de les écrire dans la machine : l'annuler les perdrait sans le dire.
        if (this.inflight && !this.inflightCarriedKeys) {
            this.inflight.abort();
        }
    }

    resize() {
        this.fit?.fit();
        // Rien à envoyer : la mesure part avec le prochain échange, qui suit de moins de huit
        // secondes. Une requête de redimensionnement séparée serait une poignée de main de plus
        // pour dire ce que la suivante allait dire de toute façon.
        this.showSize();
    }

    zoomIn() {
        this.setFontSize(this.terminal.options.fontSize + 1);
    }

    zoomOut() {
        this.setFontSize(this.terminal.options.fontSize - 1);
    }

    fullscreen() {
        const frame = this.element;

        if (document.fullscreenElement) {
            document.exitFullscreen();

            return;
        }

        frame.requestFullscreen?.().then(() => this.resize());
    }

    setFontSize(size) {
        const bounded = Math.max(9, Math.min(size, 24));
        this.terminal.options.fontSize = bounded;
        window.localStorage.setItem('console.fontSize', String(bounded));
        this.resize();
    }

    storedFontSize() {
        const stored = Number.parseInt(window.localStorage.getItem('console.fontSize') ?? '', 10);

        return Number.isNaN(stored) ? 13 : Math.max(9, Math.min(stored, 24));
    }

    /** Ferme la ligne de journal. Rien n'est tué dans la machine : c'est toute la conception. */
    release() {
        if (this.released || !this.closeUrlValue) {
            return;
        }

        this.released = true;
        const body = new Blob([JSON.stringify({})], { type: 'application/json' });

        // sendBeacon ne porte pas d'en-tête : le jeton voyage dans l'URL, et la route ne fait que
        // clore une ligne qui appartient déjà à la personne connectée.
        navigator.sendBeacon(`${this.closeUrlValue}?token=${encodeURIComponent(this.tokenValue)}`, body);
    }

    setState(state, text) {
        if (this.hasStateTarget) {
            this.stateTarget.textContent = text;
            this.stateTarget.dataset.state = state;
        }
    }

    showSize() {
        if (this.hasSizeTarget && this.terminal) {
            this.sizeTarget.textContent = `${this.terminal.cols} × ${this.terminal.rows}`;
        }
    }

    showOverlay(text, state) {
        if (!this.hasOverlayTarget) {
            return;
        }

        this.overlayTarget.hidden = false;
        this.overlayTarget.dataset.state = state ?? 'lost';

        if (this.hasOverlayTextTarget) {
            this.overlayTextTarget.textContent = text;
        }
    }

    hideOverlay() {
        if (this.hasOverlayTarget) {
            this.overlayTarget.hidden = true;
        }
    }

    pause(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }
}
