import { Controller } from '@hotwired/stimulus';
import { loadAvatarModule } from '../serious_game/avatar_module.js';

/**
 * Draws a collection piece of the serious games (design/validated/serious-games.md §8) with the
 * drawings the games use - public/serious-games/shared/rewards.js, loaded with the avatar module.
 *
 *   <span data-controller="sg-reward" data-sg-reward-kind-value="trophy" data-sg-reward-code-value="astrolabe"
 *         data-sg-reward-finish-value="silver" data-sg-reward-locked-value="false"></span>
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        kind: String,
        code: String,
        finish: String,
        locked: Boolean,
        size: { type: Number, default: 96 },
    };

    async connect() {
        await loadAvatarModule();
        const rewards = globalThis.SGRewards;
        if (!rewards) {
            return;
        }
        this.element.innerHTML = rewards.art(this.kindValue, this.codeValue, {
            size: this.sizeValue,
            finish: this.finishValue || undefined,
            locked: this.lockedValue,
        });
    }
}
