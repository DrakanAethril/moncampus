import { Controller } from '@hotwired/stimulus';

/**
 * The guest page of a public live contest (design/validated/quiz-live-public.md): a pseudonym and an
 * avatar, on one screen. The twenty avatars are drawn here among the ménagerie's hundred
 * (App\Service\FixedAvatars, handed in as `catalogue`); « Nouveaux avatars » draws twenty others,
 * none of those just shown. The chosen one travels in a hidden field as its identifier, which the
 * server checks against the catalogue; it also judges the pseudonym (App\Service\QuizLive\
 * LiveNickname) - this page only spares a round trip.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['grid', 'preview', 'avatar', 'nickname', 'submit', 'error'];

    static values = { kept: String, catalogue: Array, chooseAvatar: String, tooShort: String };

    static ANIMALS = ['Loutre', 'Panda', 'Koala', 'Licorne', 'Hérisson', 'Renard', 'Lama', 'Pieuvre', 'Tigre', 'Capybara', 'Axolotl', 'Faucon', 'Manchot', 'Castor'];

    static WORDS = ['Cosmique', 'Turbo', 'Ninja', 'Rapide', 'Malin', 'Zen', 'Pixel', 'Électrique', 'Masqué', 'Volant', 'Rieur', 'Express', 'Agile', 'Polaire'];

    connect() {
        this.catalogue = this.catalogueValue;
        this.choices = [];
        this.picked = -1;
        this.draw(this.catalogue.find((avatar) => avatar.id === this.keptValue));
        this.sync();
    }

    // Twenty distinct avatars, the ones just shown left out while the hundred allow it; a kept one
    // (a refused pseudonym) comes back first, still chosen.
    draw(kept = null) {
        const shown = new Set(this.choices.map((avatar) => avatar.id));
        const pool = this.catalogue.filter((avatar) => !shown.has(avatar.id) && avatar !== kept);
        for (let i = pool.length - 1; i > 0; i -= 1) {
            const j = Math.floor(Math.random() * (i + 1));
            [pool[i], pool[j]] = [pool[j], pool[i]];
        }
        this.choices = kept ? [kept, ...pool.slice(0, 19)] : pool.slice(0, 20);
        this.picked = kept ? 0 : -1;
        this.gridTarget.innerHTML = this.choices.map((avatar, i) => `<button type="button" class="ql-avpick ql-pop" style="animation-delay:${i * 25}ms" data-index="${i}" data-action="quiz-live-guest#pick" aria-pressed="${i === this.picked}" aria-label="${escape(avatar.name)}" title="${escape(avatar.name)}">${face(avatar, 0)}</button>`).join('');
        this.paintPick();
    }

    again() {
        this.draw();
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
        const avatar = this.choices[this.picked];
        this.avatarTarget.value = avatar ? avatar.id : '';
        this.previewTarget.innerHTML = avatar ? face(avatar, 58) : '<span class="ql-av ql-av-empty" style="width:58px;height:58px">?</span>';
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

function escape(text) {
    return String(text).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
}

// The picture as the frame's background, like a photo everywhere else in the contest (render.js).
function face(avatar, size) {
    const box = size ? `width:${size}px;height:${size}px;` : '';

    return `<span class="ql-av" style="${box}background-image:url(${escape(JSON.stringify(avatar.url))})"></span>`;
}
