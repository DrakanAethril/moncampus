import { Controller } from '@hotwired/stimulus';

// UFA > Configuration > Emails: the previewed mail sits in an <iframe srcdoc>, which has no
// height of its own. Give it its document's, so the mail is read in the page's scroll rather
// than in a second one.
export default class extends Controller {
    connect() {
        // `load` may have fired before Stimulus connected.
        this.fit();
    }

    fit() {
        const height = this.element.contentDocument?.documentElement?.scrollHeight;
        if (height) {
            this.element.style.height = `${height}px`;
        }
    }
}
