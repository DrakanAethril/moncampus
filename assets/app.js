import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */

// Vendor CSS eagerly imported here (not left to each Stimulus controller's own lazy
// `import 'foo.css'`, e.g. datatable_controller.js/tom_select_controller.js) so it always lands
// in <head> BEFORE styles/app.css below: those controllers only load once Stimulus discovers a
// matching data-controller element, which happens after this eager entrypoint has already run,
// so app.css's cascade rules (thead th uppercase/font/color, row hover, .ts-dropdown states...)
// would otherwise get silently overridden by the vendor stylesheet's own defaults, loaded later
// in <head>. Harmless to import unconditionally even on pages with no DataTable/Tom Select
// element - it's just a <link>, and AssetMapper's module cache means each one is only ever
// injected once even though the owning controller also imports it.
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'datatables.net-rowgroup-bs5/css/rowGroup.bootstrap5.min.css';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';

// Self-hosted Source Sans 3 / Spectral, replacing the fonts.googleapis.com <link> that used to sit
// in templates/base.html.twig - see the header comment in styles/fonts.css. Pure @font-face
// declarations, so unlike the vendor stylesheets above its position carries no cascade meaning;
// it sits here rather than inside app.css so the vendored files stay separable from the ~5 500
// lines of design system next to them.
import './styles/fonts.css';
import './styles/app.css';

// Turbo Drive only morphs <body> and merges <head> on a visit - it never touches attributes on
// <html> itself, so data-bs-theme (templates/base.html.twig) goes stale across any Turbo-
// intercepted navigation where the correct value actually changes (e.g. the anonymous cookie-
// driven value differs from the real App\Entity\User::$themePreference once a page like the
// contact-email/magic-link confirm flow logs someone in mid-visit) - the server-rendered response
// is always correct (a full reload proves it), only the live DOM is stuck at the old value.
// event.detail.newBody belongs to the freshly-parsed incoming document, so its ownerDocument's
// <html> carries the attribute value the *new* page actually asked for.
document.addEventListener('turbo:before-render', (event) => {
    const incomingTheme = event.detail.newBody.ownerDocument.documentElement.getAttribute('data-bs-theme');

    if (null === incomingTheme) {
        document.documentElement.removeAttribute('data-bs-theme');
    } else {
        document.documentElement.setAttribute('data-bs-theme', incomingTheme);
    }
});
