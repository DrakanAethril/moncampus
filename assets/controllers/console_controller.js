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
    static targets = [
        'screen', 'state', 'size', 'overlay', 'overlayText',
        'palette', 'paletteInput', 'paletteList', 'notice', 'noticeText',
        'become', 'becomeChip', 'identity', 'fileMenu', 'fileInput', 'search', 'searchInput', 'searchList',
    ];

    static values = {
        exchangeUrl: String,
        closeUrl: String,
        token: String,
        connectedText: String,
        lostText: String,
        paletteUrl: String,
        snippetUrl: String,
        becomeUrl: String,
        fileUrl: String,
        fetchUrl: String,
        searchUrl: String,
        platformAccount: String,
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

        // Trois raccourcis, et pas un de plus : Ctrl+K, Ctrl+Alt+B, Ctrl+Alt+F. Tout le reste
        // appartient au terminal — Ctrl+C, Ctrl+D, Ctrl+L, Ctrl+R compris. Un raccourci de
        // plateforme qui vole une touche du shell est un défaut, pas un arbitrage.
        this.terminal.attachCustomKeyEventHandler((event) => {
            if (event.type !== 'keydown') {
                return true;
            }

            if (event.ctrlKey && !event.altKey && event.key === 'k') {
                event.preventDefault();
                this.openPalette();

                return false;
            }

            if (event.ctrlKey && event.altKey && (event.key === 'f' || event.key === 'F')) {
                event.preventDefault();
                this.fullscreen();

                return false;
            }

            return true;
        });

        this.lastCommand = '';
        this.pendingIdentity = null;
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
        // La dernière commande passée, retenue pour « enregistrer comme extrait ». Elle est lue
        // dans **l'écran**, jamais dans les frappes : c'est la même règle que la transcription, et
        // un mot de passe tapé à une invite sudo n'est pas à l'écran.
        const prompts = String(answer.pane ?? '')
            // Les séquences ANSI d'abord : le panneau arrive colorié, et une invite qui commence par
            // un « \u001b[1;32m » ne ressemble à une invite pour personne.
            .replace(/\u001b\][^\u0007\u001b]*(?:\u0007|\u001b\\)|\u001b\[[0-9;?]*[ -/]*[@-~]|\u001b[@-Z\\-_]/g, '')
            .split('\n')
            .map((line) => /^[\w.-]+@[\w.-]+:[^\n]*?[$#]\s+(\S.*)$/.exec(line))
            .filter(Boolean);

        if (prompts.length > 0) {
            this.lastCommand = prompts[prompts.length - 1][1].trim();
        }

        // Une réponse partie **avant** le changement d'identité rapporte encore l'ancienne, jusqu'à
        // huit secondes après. Tant que l'identité annoncée n'est pas celle qu'on vient de prendre,
        // la barre d'état garde celle que la personne a demandée.
        if (answer.unixUser) {
            if (this.pendingIdentity && answer.unixUser !== this.pendingIdentity) {
                // rien : la réponse est plus vieille que le geste
            } else {
                this.pendingIdentity = null;
                this.showIdentity(answer.unixUser);
            }
        }

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

    // ---------------------------------------------------------------- palette

    /**
     * Ctrl+K. Trois sources fusionnées et étiquetées : les extraits de la personne (et ceux que ses
     * collègues ont partagés), le catalogue de plateforme, et ce qui a déjà été tapé sur cette
     * machine.
     *
     * **Entrée insère, Alt+Entrée exécute**, et l'ordre n'est pas un détail : on relit avant de
     * lancer — d'autant plus quand la diffusion est armée.
     */
    openPalette() {
        if (!this.hasPaletteTarget) {
            return;
        }

        this.paletteTarget.hidden = false;
        this.paletteInputTarget.value = '';
        this.paletteInputTarget.focus();
        this.loadPalette();
    }

    closePalette() {
        if (this.hasPaletteTarget) {
            this.paletteTarget.hidden = true;
            this.terminal.focus();
        }
    }

    async loadPalette() {
        const query = this.paletteInputTarget.value;
        const response = await fetch(`${this.paletteUrlValue}?q=${encodeURIComponent(query)}`, {
            headers: { 'X-Requested-With': 'fetch' },
        });

        if (!response.ok) {
            return;
        }

        const answer = await response.json();
        const groups = [
            ['consoleSnippets', answer.snippets ?? []],
            ['consoleCatalog', answer.catalog ?? []],
            ['consoleHistory', answer.history ?? []],
        ];

        this.paletteListTarget.innerHTML = '';
        this.paletteEntries = [];

        for (const [key, entries] of groups) {
            if (entries.length === 0) {
                continue;
            }

            const heading = document.createElement('div');
            heading.className = 'cm-console__palgroup';
            heading.textContent = this.paletteListTarget.dataset[key] ?? key;
            this.paletteListTarget.append(heading);

            for (const entry of entries) {
                const row = document.createElement('button');
                row.type = 'button';
                row.className = 'cm-console__palitem';
                row.dataset.command = entry.command;
                row.dataset.action = 'console#pickEntry';

                const label = document.createElement('span');
                label.className = 'cm-console__pallabel';
                label.textContent = entry.label || '';

                const command = document.createElement('span');
                command.className = 'cm-console__palcmd';
                command.textContent = entry.command;

                const meta = document.createElement('span');
                meta.className = 'cm-console__palmeta';
                meta.textContent = entry.author ? `· ${entry.author}` : (entry.uses ? `${entry.uses}×` : '');

                row.append(label, command, meta);
                this.paletteListTarget.append(row);
                this.paletteEntries.push(row);
            }
        }

        this.paletteCursor = 0;
        this.highlight();
    }

    highlight() {
        (this.paletteEntries ?? []).forEach((row, index) => {
            row.classList.toggle('is-on', index === this.paletteCursor);
        });
    }

    paletteKey(event) {
        const entries = this.paletteEntries ?? [];

        if (event.key === 'Escape') {
            event.preventDefault();
            this.closePalette();

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.paletteCursor = Math.max(0, Math.min((this.paletteCursor ?? 0) + step, entries.length - 1));
            this.highlight();

            return;
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            const row = entries[this.paletteCursor ?? 0];

            if (row) {
                this.insert(row.dataset.command, event.altKey);
            }

            return;
        }

        // Ctrl+S : la dernière commande passée devient un extrait, sans quitter l'écran. C'est ainsi
        // qu'une bibliothèque personnelle se remplit — pas par un formulaire que personne n'ouvre.
        if (event.ctrlKey && event.key === 's') {
            event.preventDefault();
            this.saveLastCommand();
        }
    }

    pickEntry(event) {
        this.insert(event.currentTarget.dataset.command, event.altKey);
    }

    /** Entrée insère (et laisse relire), Alt+Entrée exécute. */
    insert(command, run) {
        this.closePalette();
        this.type(this.encode(command));

        if (run) {
            this.type(this.encode('\r'));
        }
    }

    async saveLastCommand() {
        if (!this.lastCommand) {
            this.notify(this.paletteListTarget.dataset.consoleNoCommand ?? '');

            return;
        }

        const answer = await this.post(this.snippetUrlValue, { command: this.lastCommand, label: '' });
        this.notify(answer?.message ?? '');
        this.closePalette();
    }

    // ------------------------------------------------------- devenir quelqu'un

    toggleBecome() {
        if (this.hasBecomeTarget) {
            this.becomeTarget.hidden = !this.becomeTarget.hidden;
        }
    }

    /**
     * sudo -iu <login> : dans son $HOME, avec ses droits. On **reproduit** son problème au lieu de
     * l'imaginer. La barre d'état change de couleur et de nom — une identité qu'on porte sans le
     * savoir est la façon la plus simple de casser la machine de quelqu'un d'autre.
     */
    async become(event) {
        const login = event.currentTarget.dataset.login;
        const answer = await this.post(this.becomeUrlValue.replace(/zzlogin$/, encodeURIComponent(login)), {});

        if (this.hasBecomeTarget) {
            this.becomeTarget.hidden = true;
        }

        if (answer?.ok) {
            // Dit tout de suite plutôt qu'au prochain échange : la requête longue en vol a été
            // ouverte avant le changement d'identité et rapportera encore l'ancienne, jusqu'à huit
            // secondes plus tard. Une identité qu'on porte sans le savoir est la façon la plus
            // simple de casser la machine de quelqu'un d'autre.
            this.pendingIdentity = answer.unixUser ?? login;
            this.showIdentity(this.pendingIdentity);
            this.notify(this.element.dataset.becameText?.replace('%login%', login) ?? login);

            if (this.hasBecomeChipTarget) {
                this.becomeChipTarget.classList.toggle('cm-console__chip--on', login !== this.platformAccountValue);
            }
        } else if (answer?.message) {
            this.notify(answer.message);
        }

        this.terminal.focus();
    }

    // ------------------------------------------------------------- fichiers

    toggleFileMenu() {
        if (this.hasFileMenuTarget) {
            this.fileMenuTarget.hidden = !this.fileMenuTarget.hidden;
        }
    }

    pickFile() {
        this.fileInputTarget.click();
    }

    async uploadFile(event) {
        // Retenu avant le premier await : `currentTarget` est remis à null dès que le gestionnaire
        // a rendu la main, et le vider après coup lève une erreur qui avale le reste de la méthode.
        const input = event.currentTarget;
        const file = input.files?.[0];

        if (!file) {
            return;
        }

        await this.sendFile(file);
        input.value = '';
    }

    /** Glisser-déposer sur le panneau : le geste le plus court entre un fichier et une machine. */
    async dropFile(event) {
        event.preventDefault();
        this.element.classList.remove('is-dropping');
        const file = event.dataTransfer?.files?.[0];

        if (file) {
            await this.sendFile(file);
        }
    }

    dragOver(event) {
        event.preventDefault();
        this.element.classList.add('is-dropping');
    }

    dragLeave() {
        this.element.classList.remove('is-dropping');
    }

    async sendFile(file) {
        this.notify(this.element.dataset.sendingText ?? '…');
        const body = new FormData();
        body.append('file', file);

        const response = await fetch(this.fileUrlValue, {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.tokenValue },
            body,
        });

        const answer = response.ok ? await response.json() : null;
        this.notify(answer?.message ?? this.lostTextValue);

        if (this.hasFileMenuTarget) {
            this.fileMenuTarget.hidden = true;
        }
    }

    /** Le chemin inverse : le travail d'un poste remonte dans la bibliothèque, sans clé USB. */
    async fetchFile() {
        const path = this.fileMenuTarget.querySelector('[data-console-fetch-path]')?.value ?? '';
        const answer = await this.post(this.fetchUrlValue, { path });
        this.notify(answer?.message ?? this.lostTextValue);
        this.fileMenuTarget.hidden = true;
    }

    // ------------------------------------------------------------- recherche

    toggleSearch() {
        if (!this.hasSearchTarget) {
            return;
        }

        this.searchTarget.hidden = !this.searchTarget.hidden;

        if (!this.searchTarget.hidden) {
            this.searchInputTarget.focus();
        } else {
            this.terminal.focus();
        }
    }

    async runSearch(event) {
        if (event.key === 'Escape') {
            this.toggleSearch();

            return;
        }

        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        const answer = await this.post(this.searchUrlValue, { q: this.searchInputTarget.value });
        this.searchListTarget.innerHTML = '';

        for (const match of answer?.matches ?? []) {
            const row = document.createElement('div');
            row.className = 'cm-console__searchrow';
            row.textContent = `${match.line}  ${match.text}`;
            this.searchListTarget.append(row);
        }

        if ((answer?.matches ?? []).length === 0) {
            const row = document.createElement('div');
            row.className = 'cm-console__searchrow';
            row.textContent = this.searchListTarget.dataset.consoleNoMatch ?? '';
            this.searchListTarget.append(row);
        }
    }

    // --------------------------------------------------------------- outils

    showIdentity(login) {
        if (!this.hasIdentityTarget) {
            return;
        }

        this.identityTarget.textContent = `${login}@${this.identityTarget.dataset.host ?? ''}`;
        this.identityTarget.classList.toggle('cm-console__id--other', login !== this.platformAccountValue);
    }

    async post(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
            body: JSON.stringify(payload),
        });

        return response.ok ? response.json() : null;
    }

    /**
     * Le bandeau cuivré : ce que MonCampus a fait *dans* le terminal, dit hors du panneau.
     *
     * Hors du panneau, et c'est délibéré : écrire une ligne dans le tmux voudrait dire y envoyer des
     * touches, et des touches envoyées pendant qu'on tape une commande la corrompent. La créa dessine
     * la ligne dans le panneau ; la sécurité de ce que quelqu'un est en train de taper passe avant.
     */
    notify(text) {
        if (!this.hasNoticeTarget || !text) {
            return;
        }

        this.noticeTextTarget.textContent = text;
        this.noticeTarget.hidden = false;
        window.clearTimeout(this.noticeTimer);
        this.noticeTimer = window.setTimeout(() => { this.noticeTarget.hidden = true; }, 12000);
    }

    pause(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }
}
