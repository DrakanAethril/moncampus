import { Controller } from '@hotwired/stimulus';
import { loadAvatarModule } from '../serious_game/avatar_module.js';

/**
 * The guest page of a public live contest (design/validated/quiz-live-public.md): a pseudonym and an
 * avatar, on one screen. The twenty avatars are drawn here by the generator every avatar of
 * Formatool comes from (public/serious-games/shared/avatar.js); « Nouveaux avatars » draws twenty
 * others. The chosen one travels in a hidden field and is normalised by the server, which also
 * judges the pseudonym (App\Service\QuizLive\LiveNickname) - this page only spares a round trip.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['grid', 'preview', 'avatar', 'nickname', 'submit', 'error'];

    static values = { kept: String, avatarLabel: String, chooseAvatar: String, tooShort: String };

    static ANIMALS = ['Loutre', 'Panda', 'Koala', 'Licorne', 'Hérisson', 'Renard', 'Lama', 'Pieuvre', 'Tigre', 'Capybara', 'Axolotl', 'Faucon', 'Manchot', 'Castor'];

    static WORDS = ['Cosmique', 'Turbo', 'Ninja', 'Rapide', 'Malin', 'Zen', 'Pixel', 'Électrique', 'Masqué', 'Volant', 'Rieur', 'Express', 'Agile', 'Polaire'];

    connect() {
        this.choices = [];
        this.picked = -1;
        let kept = null;
        try {
            kept = this.keptValue ? JSON.parse(this.keptValue) : null;
        } catch {
            kept = null;
        }
        loadAvatarModule().then((module) => {
            this.module = module;
            this.draw(kept);
        });
        this.sync();
    }

    draw(kept = null) {
        this.choices = Array.from({ length: 20 }, () => this.module.random());
        this.picked = -1;
        if (kept && 'object' === typeof kept) {
            this.choices[0] = this.module.normalize(kept);
            this.picked = 0;
        }
        this.gridTarget.innerHTML = this.choices.map((config, i) => `<button type="button" class="ql-avpick ql-pop" style="animation-delay:${i * 25}ms" data-index="${i}" data-action="quiz-live-guest#pick" aria-pressed="${i === this.picked}" aria-label="${this.avatarLabelValue} ${i + 1}"><span class="ql-av">${this.module.svg(config, { size: 100 })}</span></button>`).join('');
        this.paintPick();
    }

    again() {
        if (this.module) {
            this.draw();
        }
    }

    pick(event) {
        this.picked = Number(event.currentTarget.dataset.index);
        this.paintPick();
        if (this.errorTarget.textContent === this.chooseAvatarValue) {
            this.errorTarget.textContent = '';
        }
    }

    paintPick() {
        this.gridTarget.querySelectorAll('[data-index]').forEach((button) => {
            button.setAttribute('aria-pressed', String(Number(button.dataset.index) === this.picked));
        });
        const config = this.choices[this.picked];
        this.avatarTarget.value = config ? JSON.stringify(config) : '';
        this.previewTarget.innerHTML = config && this.module
            ? `<span class="ql-av" style="width:58px;height:58px">${this.module.svg(config, { size: 100 })}</span>`
            : '<span class="ql-av ql-av-empty" style="width:58px;height:58px">?</span>';
    }

    // Only pairs that fit the field: a value set by script escapes `maxlength`, and the server cuts
    // what is longer - « Hérisson Électrique » became « Hérisson Électri » on every screen.
    dice() {
        const { ANIMALS, WORDS } = this.constructor;
        const max = this.nicknameTarget.maxLength > 0 ? this.nicknameTarget.maxLength : Infinity;
        const pairs = ANIMALS.flatMap((animal) => WORDS.map((word) => `${animal} ${word}`)).filter((name) => name.length <= max);
        this.nicknameTarget.value = pairs[Math.floor(Math.random() * pairs.length)];
        this.errorTarget.textContent = '';
        this.sync();
    }

    sync() {
        this.submitTarget.disabled = this.nicknameTarget.value.trim().length < 2;
    }

    submit(event) {
        if (this.picked < 0) {
            event.preventDefault();
            this.errorTarget.textContent = this.chooseAvatarValue;
        }
    }
}
