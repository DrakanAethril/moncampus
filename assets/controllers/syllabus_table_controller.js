import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';
import 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'datatables.net-rowgroup-bs5';
import 'datatables.net-rowgroup-bs5/css/rowGroup.bootstrap5.min.css';

/**
 * The Program syllabus table (App\Controller\ProgramSyllabusController): unlike
 * datatable_controller.js, this isn't server-side paginated - a Program's topic list is small, so
 * the whole table is rendered up front and DataTables' RowGroup extension does the grouping (by
 * topic group, a hidden sortable column), per-group "N topic(s)" counts, and per-group CM/TD/TP/
 * total hour sums entirely client-side, matching the ported screen's original behavior.
 *
 * No paging/searching/info chrome - this is a short, always-active-only list meant to be read in
 * full at a glance, not filtered or paged through.
 *
 * data-controller is bound to a stable wrapper element, not the <table> itself, same reasoning as
 * datatable_controller.js (DataTables moves the table into its own wrapper markup on init).
 */
export default class extends Controller {
    static targets = ['table'];

    static values = {
        // Column index holding the topic group name - hidden, used only as the RowGroup key and
        // as the primary sort.
        groupColumn: Number,
        topicCountLabel: String,
        // The template renders no placeholder <tr> for the empty case (see below) - DataTables'
        // own built-in "no data" row takes over instead, which also keeps it out of RowGroup's
        // grouping (see emptyTableLabel below for why that matters).
        emptyTableLabel: String,
    };

    connect() {
        // Read from thead, not "tbody tr:first td" - the tbody can legitimately have zero <tr>
        // elements (empty topic list), which would otherwise make colCount silently collapse to 0.
        const colCount = $(this.tableTarget).find('> thead > tr:first > th').length;

        this.table = $(this.tableTarget).DataTable({
            paging: false,
            searching: false,
            info: false,
            // Without this, RowGroup would fold DataTables' own generated "no data" row into a
            // bogus "No group (1)" group header, since that row has no real group-column value to
            // key off of - see emptyTableLabel above.
            language: { emptyTable: this.emptyTableLabelValue },
            order: [[this.groupColumnValue, 'asc'], [0, 'asc']],
            columnDefs: [
                { targets: [this.groupColumnValue], visible: false },
                { targets: [-1], orderable: false },
            ],
            rowGroup: {
                dataSrc: this.groupColumnValue,
                startRender: (rows, group) => $('<tr/>')
                    .addClass('cm-dt-group')
                    .append(`<td class="text-center" colspan="${colCount}">${group} (${rows.count()} ${this.topicCountLabelValue})</td>`),
                endRender: (rows, group) => {
                    const sum = (colIndex) => rows.data().pluck(colIndex).reduce((a, b) => a * 1 + b * 1, 0);
                    const tr = document.createElement('tr');
                    tr.classList.add('cm-dt-subtotal');
                    this.addCell(tr, '');
                    this.addCell(tr, `${sum(1)} H`, 'text-end');
                    this.addCell(tr, `${sum(2)} H`, 'text-end');
                    this.addCell(tr, `${sum(3)} H`, 'text-end');
                    this.addCell(tr, `${sum(4)} H`, 'text-end');
                    this.addCell(tr, '');

                    return tr;
                },
            },
        });
    }

    // className is optional - RowGroup-generated cells aren't touched by DataTables' own numeric
    // type detection (that only applies to cells rendered through its normal column pipeline),
    // so the "H"-suffixed sums need an explicit text-end to line up with the real data rows above
    // them.
    addCell(tr, content, className) {
        const td = document.createElement('td');
        td.textContent = content;
        if (className) {
            td.className = className;
        }
        tr.appendChild(td);
    }

    disconnect() {
        this.table?.destroy();
        this.table = null;
    }
}
