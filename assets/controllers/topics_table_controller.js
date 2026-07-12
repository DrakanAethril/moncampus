import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';
import 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'datatables.net-rowgroup-bs5';
import 'datatables.net-rowgroup-bs5/css/rowGroup.bootstrap5.min.css';

/**
 * The Program Topics ("Matières") settings table (App\Controller\ProgramTimetableSettingsController::topicsTab):
 * same reasoning as syllabus_table_controller.js (a Program's topic list is small enough to
 * render up front and let DataTables/RowGroup group by topic group and sum hour columns
 * client-side) but this table keeps its CRUD actions (plain links/forms, not ajax - see the
 * controller) and adds a table-wide grand-total footer row on top of the per-group ones.
 *
 * No paging/searching/info chrome and no inactive-items toggle - this always shows the full,
 * active-only list of the Program's own Topics.
 *
 * data-controller is bound to a stable wrapper element, not the <table> itself, same reasoning
 * as datatable_controller.js (DataTables moves the table into its own wrapper markup on init).
 */
export default class extends Controller {
    static targets = ['table'];

    static values = {
        // Column index holding the topic group name - hidden, used only as the RowGroup key and
        // as the primary sort.
        groupColumn: Number,
        topicCountLabel: String,
        // Column indexes summed for both the per-group (RowGroup) totals and the table-wide
        // grand-total footer row.
        sumColumns: Array,
    };

    connect() {
        this.colCount = $(this.tableTarget).find('> tbody > tr:first > td').length;
        const sumColumns = this.sumColumnsValue;

        this.table = $(this.tableTarget).DataTable({
            paging: false,
            searching: false,
            info: false,
            order: [[this.groupColumnValue, 'asc'], [0, 'asc']],
            columnDefs: [
                { targets: [this.groupColumnValue], visible: false },
                { targets: [-1], orderable: false },
            ],
            rowGroup: {
                dataSrc: this.groupColumnValue,
                startRender: (rows, group) => $('<tr/>')
                    .append(`<td class="text-center fw-bold" colspan="${this.colCount}">${group} (${rows.count()} ${this.topicCountLabelValue})</td>`),
                endRender: (rows) => $(this.buildTotalsRow(rows.data())).addClass('text-secondary'),
            },
            // A regular function (not an arrow function): DataTables calls footerCallback with
            // `this.api()` reaching its own API instance, which is the only way to reach it here
            // - the first draw runs synchronously inside this very DataTable() call, before its
            // return value is assigned to this.table, so `this.table` would still be undefined.
            footerCallback: function () {
                const api = this.api();
                sumColumns.forEach((colIndex) => {
                    const sum = api.column(colIndex, { search: 'applied' }).data().reduce((a, b) => a * 1 + b * 1, 0);
                    $(api.column(colIndex).footer()).text(`${sum} H`);
                });
            },
        });
    }

    // Builds a totals <tr> matching the visible columns (skipping the hidden group column, same
    // as DataTables itself does when rendering a real data row), summing the configured columns
    // and leaving the rest blank.
    buildTotalsRow(data) {
        const tr = document.createElement('tr');

        for (let i = 0; i < this.colCount; i += 1) {
            if (i === this.groupColumnValue) {
                continue;
            }

            const td = document.createElement('td');
            if (this.sumColumnsValue.includes(i)) {
                td.textContent = `${data.pluck(i).reduce((a, b) => a * 1 + b * 1, 0)} H`;
            }
            tr.appendChild(td);
        }

        return tr;
    }

    disconnect() {
        this.table?.destroy();
        this.table = null;
    }
}
