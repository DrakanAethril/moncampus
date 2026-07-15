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
import './styles/app.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
