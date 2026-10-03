import { Controller } from '@hotwired/stimulus';

/**
 * The preview of a teacher's banner on « Ma page » (design/validated/cours-en-ligne.md, §5): the
 * title, the two colours and the height, redrawn as they are typed, in the public page's own markup
 * (templates/online_course/_page_banner.html.twig). Only a colour the field itself produced
 * (`#rrggbb`) is applied, and a height only within the field's bounds - the server keeps the same
 * rules.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['preview', 'title', 'titleColor', 'bannerColor', 'height'];

    refresh() {
        const banner = this.previewTarget.querySelector('.cm-pub-banner');
        const heading = this.previewTarget.querySelector('.cm-pub-banner__title');
        if (!banner || !heading) {
            return;
        }

        heading.textContent = this.titleTarget.value;
        this.apply(banner, '--cm-pub-banner-ink', this.titleColorTarget.value);
        this.apply(banner, '--cm-pub-banner-bg', this.bannerColorTarget.value);

        const height = Number(this.heightTarget.value);
        if (height >= Number(this.heightTarget.min) && height <= Number(this.heightTarget.max)) {
            banner.style.setProperty('--cm-pub-banner-height', `${height}px`);
        }
    }

    apply(banner, property, value) {
        if (/^#[0-9a-f]{6}$/i.test(value)) {
            banner.style.setProperty(property, value);
        }
    }
}
