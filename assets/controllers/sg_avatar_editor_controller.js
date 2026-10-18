import { Controller } from '@hotwired/stimulus';
import { loadAvatarModule } from '../serious_game/avatar_module.js';

/**
 * The avatar editor of the serious games (design/validated/serious-games.md §7,
 * design/conception_serious_games/avatar.html): a preview, « Aléatoire », « Réinitialiser »,
 * « Enregistrer mon avatar », and one tab per heading of the shared module's catalogue (`CATS`).
 * Every shape option is a thumbnail of the current avatar with that option applied.
 *
 * The editor saves the whole config as JSON; the server keeps only the values it knows and refuses
 * a pin the person has not obtained. A student also gets the « Épinglettes » tab - an adult never
 * holds one.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['preview', 'tabs', 'groups', 'save'];

    static values = {
        config: Object,
        saved: Boolean,
        pins: Array,
        collects: Boolean,
        url: String,
        csrf: String,
        saveLabel: String,
        savedLabel: String,
        errorLabel: String,
    };

    async connect() {
        this.A = await loadAvatarModule();
        this.config = this.A.normalize(this.configValue);
        this.held = this.pinsValue;
        this.tab = this.A.CATS[0].id;
        this.isSaved = this.savedValue;
        this.render();
    }

    render() {
        this.previewTarget.innerHTML = this.A.html(this.config, 220);
        this.renderTabs();
        this.renderGroups();
        this.saveTarget.textContent = this.isSaved ? this.savedLabelValue : this.saveLabelValue;
        this.saveTarget.dataset.saved = this.isSaved ? 'true' : 'false';
    }

    renderTabs() {
        const tabs = this.A.CATS.map((cat) => [cat.id, cat.label]);
        if (this.collectsValue) {
            tabs.push(['pins', 'Épinglettes']);
        }

        this.tabsTarget.replaceChildren(...tabs.map(([id, label]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', id === this.tab ? 'true' : 'false');
            button.textContent = label;
            button.addEventListener('click', () => {
                this.tab = id;
                this.renderTabs();
                this.renderGroups();
            });

            return button;
        }));
    }

    renderGroups() {
        if ('pins' === this.tab) {
            this.renderPins();

            return;
        }

        const cat = this.A.CATS.find((c) => c.id === this.tab);
        this.groupsTarget.replaceChildren(...cat.groups.map((group) => {
            const box = document.createElement('div');
            const title = document.createElement('div');
            title.className = 'cm-sg-ed__gt';
            title.textContent = group.t;
            const options = document.createElement('div');
            options.className = 'cm-sg-ed__opts';

            if (group.colors) {
                group.colors.forEach((color, index) => options.append(this.swatch(color, this.config[group.key] === index, `${group.t} ${index + 1}`, () => this.set({ [group.key]: index }))));
            } else if (group.toggle) {
                group.opts.forEach(([key, label, added]) => options.append(this.tile(Object.assign({}, this.config, { [key]: true }), this.config[key], label, added, cat.vb, () => this.set({ [key]: !this.config[key] }))));
            } else {
                group.opts.forEach(([value, label, added]) => {
                    const change = group.preset ? Object.assign({ [group.key]: value }, this.A.PRESET[value]) : { [group.key]: value };
                    options.append(this.tile(Object.assign({}, this.config, change), this.config[group.key] === value, label, added, cat.vb, () => this.set(change)));
                });
            }

            box.append(title, options);

            return box;
        }));
    }

    renderPins() {
        const box = document.createElement('div');
        const title = document.createElement('div');
        title.className = 'cm-sg-ed__gt';
        title.textContent = 'Épinglette portée sur le haut';
        const options = document.createElement('div');
        options.className = 'cm-sg-ed__opts';
        options.append(this.pin(null, 'Aucune', true));
        this.A.PINS.forEach((pin) => options.append(this.pin(pin.id, pin.name, this.held.includes(pin.id))));
        const hint = document.createElement('p');
        hint.className = 'cm-sg-ed__hint';
        hint.textContent = 'Une épinglette s’obtient en terminant un jeu.';
        box.append(title, options, hint);
        this.groupsTarget.replaceChildren(box);
    }

    pin(id, label, available) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'cm-sg-ed__pin';
        button.disabled = !available;
        button.setAttribute('aria-pressed', this.config.pin === id ? 'true' : 'false');
        if (null !== id) {
            const disc = document.createElement('span');
            disc.innerHTML = `<svg viewBox="120 170 32 32" width="28" height="28" aria-hidden="true">${this.A.pinSvg(id, 136, 186, 9)}</svg>`;
            button.append(disc);
        }
        button.append(document.createTextNode(label));
        button.addEventListener('click', () => this.set({ pin: id }));

        return button;
    }

    tile(config, pressed, label, added, viewBox, apply) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'cm-sg-ed__tile';
        button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        const thumbnail = document.createElement('span');
        thumbnail.className = 'cm-sg-ed__th';
        thumbnail.innerHTML = this.A.svg(config, { size: 72, viewBox: viewBox || undefined });
        const text = document.createElement('span');
        text.textContent = label;
        button.append(thumbnail, text);
        if (added) {
            const badge = document.createElement('span');
            badge.className = 'cm-sg-ed__new';
            badge.textContent = 'nouveau';
            button.append(badge);
        }
        button.addEventListener('click', apply);

        return button;
    }

    swatch(color, pressed, label, apply) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'cm-sg-ed__sw';
        button.style.background = color;
        button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        button.setAttribute('aria-label', label);
        button.addEventListener('click', apply);

        return button;
    }

    set(change) {
        this.config = Object.assign({}, this.config, change);
        this.isSaved = false;
        this.render();
    }

    randomize() {
        const pin = this.config.pin;
        this.config = Object.assign(this.A.random(), { pin });
        this.isSaved = false;
        this.render();
    }

    reset() {
        const start = this.config.start;
        this.config = Object.assign({}, this.A.DEF, this.A.PRESET[start], { start, pin: this.config.pin });
        this.isSaved = false;
        this.render();
    }

    async save() {
        this.saveTarget.disabled = true;
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfValue, Accept: 'application/json' },
                body: JSON.stringify(this.config),
            });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const body = await response.json();
            this.config = this.A.normalize(body.config);
            this.isSaved = true;
            this.render();
        } catch {
            this.saveTarget.textContent = this.errorLabelValue;
        } finally {
            this.saveTarget.disabled = false;
        }
    }
}
