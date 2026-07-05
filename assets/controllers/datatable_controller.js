import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';
import 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';

// Escapes a value before it is inserted as HTML by a custom render() callback below.
function escapeHtml(value) {
    return $('<div>').text(value ?? '').html();
}

/**
 * Generic, server-side-paginated DataTables wrapper. Column definitions and
 * translated language strings are passed in from Twig via values, so this
 * controller has no page-specific knowledge (see templates/annuaire/*.html.twig).
 *
 * data-controller is bound to a stable wrapper element, NOT the <table> itself:
 * DataTables moves the table into its own wrapper markup on init and back out on
 * destroy(), and if Stimulus were watching the table directly, that DOM move
 * makes it look disconnected-then-reconnected, causing an init/destroy/init...
 * infinite loop (each re-init firing a fresh ajax call).
 */
export default class extends Controller {
    static targets = ['table'];

    static values = {
        url: String,
        columns: Array,
        language: Object,
        pageLength: { type: Number, default: 10 },
    };

    connect() {
        this.table = $(this.tableTarget).DataTable({
            serverSide: true,
            searching: false,
            ordering: false,
            pageLength: this.pageLengthValue,
            ajax: { url: this.urlValue, type: 'GET' },
            language: this.languageValue,
            columns: this.columnsValue.map((column) => this.buildColumn(column)),
        });
    }

    disconnect() {
        this.table?.destroy();
        this.table = null;
    }

    buildColumn(column) {
        if (column.render === 'badge') {
            return {
                data: column.data,
                render: (data, type, row) => (type === 'display'
                    ? `<span class="badge ${row[column.classField]}">${escapeHtml(data)}</span>`
                    : data),
            };
        }

        if (column.render === 'badges') {
            return {
                data: column.data,
                render: (data, type) => {
                    if (type !== 'display') {
                        return Array.isArray(data) ? data.join(', ') : '';
                    }

                    return Array.isArray(data)
                        ? data.map((value) => `<span class="badge bg-blue-lt me-1">${escapeHtml(value)}</span>`).join('')
                        : '';
                },
            };
        }

        return { data: column.data, render: (data, type) => (type === 'display' ? escapeHtml(data) : data) };
    }
}
