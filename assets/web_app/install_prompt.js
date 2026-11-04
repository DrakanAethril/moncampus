/*
 * The browser's offer to install Formatool (design/validated/quiz-tournament.md, §13).
 *
 * Chrome and Edge announce that a page can be installed with a `beforeinstallprompt` event, once,
 * early - often before any Stimulus controller of the page has connected, and long before
 * somebody walks to « Installer beaup-app ». So it is caught here, by a module app.js imports on
 * every page, and kept in this module's memory: a Turbo visit keeps the window, and with it the
 * event, until the install screen asks for it (web_app_install_controller.js).
 *
 * preventDefault() keeps Chrome's own mini-infobar off every screen of the platform: the offer is
 * made where somebody asked for it, and the browser's menu still carries « Installer » anyway.
 * Nothing here asks for a permission or registers a service worker.
 */

let deferredPrompt = null;
let installed = false;
const listeners = new Set();

function notify() {
    listeners.forEach((listener) => listener());
}

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredPrompt = event;
    notify();
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    installed = true;
    notify();
});

/** Whether the browser has offered installation and the offer has not been used yet. */
export function canPromptInstall() {
    return null !== deferredPrompt;
}

/** Whether Formatool was installed from this page, during this visit. */
export function wasInstalledNow() {
    return installed;
}

/**
 * Shows the browser's install dialog. Answers 'accepted', 'dismissed', or null when there is no
 * offer to show. An offer serves once: the browser may make another one later, which notify() tells.
 */
export async function promptInstall() {
    if (null === deferredPrompt) {
        return null;
    }
    const prompt = deferredPrompt;
    deferredPrompt = null;
    await prompt.prompt();
    const choice = await prompt.userChoice;
    notify();

    return choice.outcome;
}

/** Calls the listener whenever the offer appears, is used or installation completes; returns the unsubscribe. */
export function onInstallPromptChange(listener) {
    listeners.add(listener);

    return () => listeners.delete(listener);
}
