import { Controller } from '@hotwired/stimulus';
import { loadAvatarModule } from '../serious_game/avatar_module.js';

/**
 * Draws a serious-games avatar (design/validated/serious-games.md §7) with the one module the
 * platform and the games share - public/serious-games/shared/avatar.js. Outside the serious games
 * it stands in for a student's missing photo (App\Service\UserAvatars): there `round` is false and
 * the drawing fills the square frame it is put in, whose initials it replaces once loaded.
 *
 *   <span data-controller="sg-avatar" data-sg-avatar-config-value="{…}" data-sg-avatar-size-value="40"></span>
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        config: Object,
        size: { type: Number, default: 40 },
        bare: { type: Boolean, default: false },
        round: { type: Boolean, default: true },
        costume: String,
    };

    async connect() {
        const avatar = await loadAvatarModule();
        const config = this.configValue;
        if (!this.roundValue) {
            this.element.innerHTML = avatar.svg(avatar.normalize(config), { size: this.sizeValue, round: false });

            return;
        }
        this.element.innerHTML = avatar.html(avatar.normalize(config), this.sizeValue, {
            bare: this.bareValue,
            costume: this.costumeValue || undefined,
        });
    }
}
