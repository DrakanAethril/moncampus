import { Controller } from '@hotwired/stimulus';

// Sets the iframe's hash on TOC click and highlights the active entry - same-origin iframe (the
// booklet frame route is served from this same app), so plain contentWindow.location.hash works,
// no postMessage needed. See the feature's plan doc, architecture call 6: deliberately no fake
// pagination/thumbnails/zoom here, just a TOC scrolling the real document.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['toc', 'frame'];

    navigate(event) {
        event.preventDefault();
        const hash = event.currentTarget.getAttribute('href');

        this.frameTarget.contentWindow.location.hash = hash;

        this.tocTarget.querySelectorAll('.active').forEach((el) => el.classList.remove('active'));
        event.currentTarget.classList.add('active');
    }
}
