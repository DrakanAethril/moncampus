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
 * controller has no page-specific knowledge (see templates/directory/*.html.twig).
 *
 * data-controller is bound to a stable wrapper element, NOT the <table> itself:
 * DataTables moves the table into its own wrapper markup on init and back out on
 * destroy(), and if Stimulus were watching the table directly, that DOM move
 * makes it look disconnected-then-reconnected, causing an init/destroy/init...
 * infinite loop (each re-init firing a fresh ajax call).
 */
export default class extends Controller {
    static targets = ['table', 'includeInactive', 'includeInactiveWrapper'];

    static values = {
        url: String,
        columns: Array,
        language: Object,
        pageLength: { type: Number, default: 10 },
        editUrlTemplate: String,
        deactivateUrlTemplate: String,
        deactivateToken: String,
        editLabel: String,
        deactivateLabel: String,
        deactivateConfirmMessage: String,
        deactivateErrorMessage: String,
    };

    connect() {
        this.table = $(this.tableTarget).DataTable({
            serverSide: true,
            searching: true,
            ordering: false,
            pagingType: 'simple_numbers',
            pageLength: this.pageLengthValue,
            ajax: {
                url: this.urlValue,
                type: 'GET',
                data: (params) => {
                    if (this.hasIncludeInactiveTarget) {
                        params.includeInactive = this.includeInactiveTarget.checked;
                    }
                },
            },
            language: this.languageValue,
            columns: this.columnsValue.map((column) => this.buildColumn(column)),
        });

        this.styleWrapper();

        // Row actions are rendered by DataTables into the tbody on every draw, so a listener
        // bound to individual buttons would be lost on the next redraw/page change - delegate
        // from the stable wrapper element instead.
        this.onClick = (event) => this.handleActionClick(event);
        this.element.addEventListener('click', this.onClick);
    }

    // DataTables generates its own Bootstrap grid rows around the table (length control on
    // top, info/pagination on bottom). Apply Tabler's own card-header/card-footer utility
    // classes to those rows (see design/tabler/demo/tables.html's "Invoices" table) instead of
    // writing bespoke CSS, so it looks native to the rest of the app.
    styleWrapper() {
        const $container = $(this.tableTarget).closest('.dt-container');
        const $headerRow = $container.find('> .row').first().addClass('border-bottom py-3 mx-0');
        $container.find('.dt-layout-table').next('.row').addClass('border-top py-3 mx-0');
        $container.find('.dt-info').addClass('text-secondary');

        // Move the "show inactive" switch (rendered by Twig above the table) into the same
        // header row as the length control and search box, centered between them.
        if (this.hasIncludeInactiveWrapperTarget) {
            $headerRow.addClass('position-relative');
            $(this.includeInactiveWrapperTarget)
                .removeClass('mb-2')
                .addClass('position-absolute top-50 start-50 translate-middle mb-0 w-auto')
                .appendTo($headerRow);
        }
    }

    disconnect() {
        this.element.removeEventListener('click', this.onClick);
        this.table?.destroy();
        this.table = null;
    }

    // Bound to the "show deactivated items" checkbox via data-action="change->datatable#reload".
    reload() {
        this.table?.ajax.reload(null, false);
    }

    handleActionClick(event) {
        const button = event.target.closest('[data-datatable-deactivate-id]');

        if (!button || !window.confirm(this.deactivateConfirmMessageValue)) {
            return;
        }

        const url = this.deactivateUrlTemplateValue.replace('__ID__', button.dataset.datatableDeactivateId);

        fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.deactivateTokenValue },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Unexpected response status: ${response.status}`);
                }

                this.table.ajax.reload(null, false);
            })
            .catch(() => window.alert(this.deactivateErrorMessageValue));
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

        if (column.render === 'actions') {
            return {
                data: null,
                orderable: false,
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    const editUrl = this.editUrlTemplateValue.replace('__ID__', row.id);
                    const deactivateButton = row.isInactive
                        ? ''
                        : `<button type="button" class="btn btn-sm btn-danger" data-datatable-deactivate-id="${row.id}">${escapeHtml(this.deactivateLabelValue)}</button>`;

                    return `<div class="btn-list flex-nowrap"><a href="${editUrl}" class="link-orange text-decoration-none">${escapeHtml(this.editLabelValue)}</a>${deactivateButton}</div>`;
                },
            };
        }

        return { data: column.data, render: (data, type) => (type === 'display' ? escapeHtml(data) : data) };
    }
}
