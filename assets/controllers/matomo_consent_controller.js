import { Controller } from '@hotwired/stimulus';

const COOKIE_NAME = 'matomo_consent';
const COOKIE_MAX_AGE = 365 * 24 * 60 * 60;

export default class extends Controller {
    connect() {
        const consent = readCookie(COOKIE_NAME);

        if (consent === 'granted') {
            pushConsentGiven();
        } else if (consent !== 'denied') {
            this.element.hidden = false;
        }
    }

    accept() {
        writeCookie(COOKIE_NAME, 'granted');
        pushConsentGiven();
        this.element.hidden = true;
    }

    decline() {
        writeCookie(COOKIE_NAME, 'denied');
        this.element.hidden = true;
    }
}

function pushConsentGiven() {
    window._paq && window._paq.push(['setConsentGiven']);
}

function readCookie(name) {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

    return match ? decodeURIComponent(match[1]) : null;
}

function writeCookie(name, value) {
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + COOKIE_MAX_AGE + '; samesite=lax';
}
