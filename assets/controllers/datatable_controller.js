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
        addUrlTemplate: String,
        addToken: String,
        addLabel: String,
        addConfirmMessage: String,
        addErrorMessage: String,
        removeUrlTemplate: String,
        removeToken: String,
        removeLabel: String,
        removeConfirmMessage: String,
        removeErrorMessage: String,
        printUrlTemplate: String,
        printLabel: String,
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
        this.$headerRow = $container.find('> .row').first().addClass('border-bottom py-3 mx-0 position-relative');
        $container.find('.dt-layout-table').next('.row').addClass('border-top py-3 mx-0');
        $container.find('.dt-info').addClass('text-secondary');

        // Handles the common case where the switch markup is already present when connect()
        // runs. includeInactiveWrapperTargetConnected() below covers the rare case where the
        // browser hasn't finished parsing it into the DOM yet at this point.
        if (this.hasIncludeInactiveWrapperTarget) {
            this.centerIncludeInactiveSwitch(this.includeInactiveWrapperTarget);
        }
    }

    // Stimulus invokes this automatically whenever an element with this target attribute enters
    // the DOM, whenever that happens to occur - unlike reading the target synchronously in
    // connect(), it isn't racy against the browser still parsing the rest of the page.
    includeInactiveWrapperTargetConnected(element) {
        if (this.$headerRow) {
            this.centerIncludeInactiveSwitch(element);
        }
    }

    // Moves the "show inactive" switch (rendered by Twig above the table) into the DataTables
    // header row, centered between the length control and the search box.
    centerIncludeInactiveSwitch(element) {
        $(element)
            .removeClass('mb-2')
            .addClass('position-absolute top-50 start-50 translate-middle mb-0 w-auto')
            .appendTo(this.$headerRow);
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
        const deactivateButton = event.target.closest('[data-datatable-deactivate-id]');
        if (deactivateButton) {
            this.performAction(
                deactivateButton,
                this.deactivateUrlTemplateValue,
                deactivateButton.dataset.datatableDeactivateId,
                this.deactivateTokenValue,
                this.deactivateConfirmMessageValue,
                this.deactivateErrorMessageValue,
            );

            return;
        }

        const addButton = event.target.closest('[data-datatable-add-id]');
        if (addButton) {
            this.performAction(
                addButton,
                this.addUrlTemplateValue,
                addButton.dataset.datatableAddId,
                this.addTokenValue,
                this.addConfirmMessageValue,
                this.addErrorMessageValue,
            );

            return;
        }

        const removeButton = event.target.closest('[data-datatable-remove-id]');
        if (removeButton) {
            this.performAction(
                removeButton,
                this.removeUrlTemplateValue,
                removeButton.dataset.datatableRemoveId,
                this.removeTokenValue,
                this.removeConfirmMessageValue,
                this.removeErrorMessageValue,
            );
        }
    }

    performAction(button, urlTemplate, id, token, confirmMessage, errorMessage) {
        if (!window.confirm(confirmMessage)) {
            return;
        }

        const url = urlTemplate.replace('__ID__', id);

        fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': token },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Unexpected response status: ${response.status}`);
                }

                this.table.ajax.reload(null, false);
            })
            .catch(() => window.alert(errorMessage));
    }

    buildColumn(column) {
        // Trusts the server to have already escaped any embedded user input (e.g. via
        // htmlspecialchars() around a name before it's wrapped in an <a> tag) - unlike the
        // default column below, which always escapes since most columns are plain text.
        if (column.render === 'html') {
            return { data: column.data, render: (data, type) => (type === 'display' ? data : data) };
        }

        if (column.render === 'color') {
            return {
                data: column.data,
                render: (data, type) => {
                    if (type !== 'display') {
                        return data;
                    }

                    const swatch = `<span class="badge" style="background-color: ${escapeHtml(data)}">&nbsp;</span>`;

                    return `${swatch} ${escapeHtml(data)}`;
                },
            };
        }

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
                        : `<button type="button" class="btn btn-ghost-danger btn-sm" data-datatable-deactivate-id="${row.id}">${escapeHtml(this.deactivateLabelValue)}</button>`;

                    return `<div class="btn-list flex-nowrap"><a href="${editUrl}" class="btn btn-ghost-warning btn-sm">${escapeHtml(this.editLabelValue)}</a>${deactivateButton}</div>`;
                },
            };
        }

        if (column.render === 'reportActions') {
            return {
                data: null,
                orderable: false,
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    const printUrl = this.printUrlTemplateValue.replace('__ID__', row.id);
                    const editUrl = this.editUrlTemplateValue.replace('__ID__', row.id);
                    const deactivateButton = row.isInactive
                        ? ''
                        : `<button type="button" class="btn btn-ghost-danger btn-sm" data-datatable-deactivate-id="${row.id}">${escapeHtml(this.deactivateLabelValue)}</button>`;

                    return `<div class="btn-list flex-nowrap"><a href="${printUrl}" class="btn btn-ghost-secondary btn-sm" target="_blank">${escapeHtml(this.printLabelValue)}</a><a href="${editUrl}" class="btn btn-ghost-warning btn-sm">${escapeHtml(this.editLabelValue)}</a>${deactivateButton}</div>`;
                },
            };
        }

        if (column.render === 'add') {
            return {
                data: null,
                orderable: false,
                render: (data, type, row) => (type === 'display'
                    ? `<button type="button" class="btn btn-primary btn-sm" data-datatable-add-id="${row.id}">${escapeHtml(this.addLabelValue)}</button>`
                    : ''),
            };
        }

        if (column.render === 'studentActions') {
            return {
                data: null,
                orderable: false,
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    const editUrl = this.editUrlTemplateValue.replace('__ID__', row.id);

                    return `<div class="btn-list flex-nowrap justify-content-end"><a href="${editUrl}" class="btn btn-ghost-warning btn-sm">${escapeHtml(this.editLabelValue)}</a><button type="button" class="btn btn-ghost-danger btn-sm" data-datatable-remove-id="${row.id}">${escapeHtml(this.removeLabelValue)}</button></div>`;
                },
            };
        }

        if (column.render === 'remove') {
            return {
                data: null,
                orderable: false,
                render: (data, type, row) => (type === 'display'
                    ? `<button type="button" class="btn btn-ghost-danger btn-sm" data-datatable-remove-id="${row.id}">${escapeHtml(this.removeLabelValue)}</button>`
                    : ''),
            };
        }

        return { data: column.data, render: (data, type) => (type === 'display' ? escapeHtml(data) : data) };
    }
}
