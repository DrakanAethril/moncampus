import { Controller } from '@hotwired/stimulus';
import { loadAvatarModule } from '../serious_game/avatar_module.js';

/**
 * Draws a serious-games avatar (design/validated/serious-games.md §7) with the one module the
 * platform and the games share - public/serious-games/shared/avatar.js. The avatar appears in the
 * serious games' screens only; everywhere else Formatool keeps the photo and the initials.
 *
 *   <span data-controller="sg-avatar" data-sg-avatar-config-value="{…}" data-sg-avatar-size-value="40"></span>
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        config: Object,
        size: { type: Number, default: 40 },
        bare: { type: Boolean, default: false },
        costume: String,
    };

    async connect() {
        const avatar = await loadAvatarModule();
        const config = this.configValue;
        this.element.innerHTML = avatar.html(avatar.normalize(config), this.sizeValue, {
            bare: this.bareValue,
            costume: this.costumeValue || undefined,
        });
    }
}
