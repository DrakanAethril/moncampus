import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';
import 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';

/**
 * A short, always-full DataTable with no ajax, no pagination/searching/info chrome and no
 * RowGroup - just a sensible default sort (column-click re-sorting stays on). For a list small
 * enough, and meant to be read in full, that paging/search only get in the way (same reasoning
 * as syllabus_table_controller.js / topics_table_controller.js, which additionally group rows -
 * see those for the grouped variant of this same idea).
 *
 * data-controller is bound to a stable wrapper element, not the <table> itself, same reasoning
 * as datatable_controller.js (DataTables moves the table into its own wrapper markup on init).
 */
export default class extends Controller {
    static targets = ['table'];

    static values = {
        orderColumn: { type: Number, default: 0 },
        orderDirection: { type: String, default: 'asc' },
    };

    connect() {
        // Campus Manager's row-zebra/hover CSS (assets/styles/app.css) targets DataTables' own
        // "stripe"/"hover" classes, which DataTables doesn't add itself - see the matching
        // comment in datatable_controller.js's connect().
        this.tableTarget.classList.add('stripe', 'hover');

        this.table = $(this.tableTarget).DataTable({
            paging: false,
            searching: false,
            info: false,
            order: [[this.orderColumnValue, this.orderDirectionValue]],
            // The last column is always a blank-header actions cell (edit/deactivate) in every
            // table this controller drives - sorting by it has no meaning.
            columnDefs: [{ targets: [-1], orderable: false }],
        });
    }

    disconnect() {
        this.table?.destroy();
        this.table = null;
    }
}
