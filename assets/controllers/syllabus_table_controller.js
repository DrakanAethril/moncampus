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
    };

    connect() {
        const colCount = $(this.tableTarget).find('> tbody > tr:first > td').length;

        this.table = $(this.tableTarget).DataTable({
            pageLength: 50,
            order: [[this.groupColumnValue, 'asc'], [0, 'asc']],
            columnDefs: [
                { targets: [this.groupColumnValue], visible: false },
                { targets: [-1], orderable: false },
            ],
            rowGroup: {
                dataSrc: this.groupColumnValue,
                startRender: (rows, group) => $('<tr/>')
                    .append(`<td class="text-center fw-bold" colspan="${colCount}">${group} (${rows.count()} ${this.topicCountLabelValue})</td>`),
                endRender: (rows, group) => {
                    const sum = (colIndex) => rows.data().pluck(colIndex).reduce((a, b) => a * 1 + b * 1, 0);
                    const tr = document.createElement('tr');
                    tr.classList.add('text-secondary');
                    this.addCell(tr, '');
                    this.addCell(tr, `${sum(1)} H`);
                    this.addCell(tr, `${sum(2)} H`);
                    this.addCell(tr, `${sum(3)} H`);
                    this.addCell(tr, `${sum(4)} H`);
                    this.addCell(tr, '');

                    return tr;
                },
            },
        });

        this.styleWrapper();
    }

    // Same reasoning as datatable_controller.js's styleWrapper(): apply Tabler's card-header/
    // card-footer classes to the -bs5 build's own generated wrapper rows instead of a custom dom
    // string (the pre-2.x approach), so it looks native to the rest of the app.
    styleWrapper() {
        const $container = $(this.tableTarget).closest('.dt-container');
        $container.find('> .row').first().addClass('border-bottom py-3 mx-0');
        $container.find('.dt-layout-table').next('.row').addClass('border-top py-3 mx-0');
        $container.find('.dt-info').addClass('text-secondary');
    }

    addCell(tr, content) {
        const td = document.createElement('td');
        td.textContent = content;
        tr.appendChild(td);
    }

    disconnect() {
        this.table?.destroy();
        this.table = null;
    }
}
