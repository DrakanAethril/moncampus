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
    static targets = ['table', 'includeInactive', 'includeInactiveWrapper', 'onlyActive', 'onlyActiveWrapper', 'filter'];

    static values = {
        url: String,
        columns: Array,
        language: Object,
        pageLength: { type: Number, default: 10 },
        // Off for the Messages list (search across subject/body is a v2 item, see
        // design/validated/internal-messaging.md) - defaults to true so every other existing
        // usage of this controller is unaffected.
        searching: { type: Boolean, default: true },
        editUrlTemplate: String,
        deactivateUrlTemplate: String,
        deactivateToken: String,
        editLabel: String,
        deactivateLabel: String,
        deactivateConfirmMessage: String,
        deactivateErrorMessage: String,
        duplicateUrlTemplate: String,
        duplicateToken: String,
        duplicateLabel: String,
        duplicateConfirmMessage: String,
        duplicateErrorMessage: String,
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
        pdfUrlTemplate: String,
        pdfLabel: String,
        lendUrlTemplate: String,
        lendLabel: String,
        returnUrlTemplate: String,
        returnLabel: String,
        historyUrlTemplate: String,
        historyLabel: String,
        selectUrlTemplate: String,
        selectLabel: String,
    };

    connect() {
        this.table = $(this.tableTarget).DataTable({
            serverSide: true,
            searching: this.searchingValue,
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
                    if (this.hasOnlyActiveTarget) {
                        params.onlyActive = this.onlyActiveTarget.checked;
                    }
                    // Generic arbitrary-select filters (e.g. the ticket queue's status/category/
                    // priority/assignee dropdowns): each element carries the query param name it
                    // feeds via data-datatable-filter-param, so this controller doesn't need to
                    // know about any specific filter by name.
                    this.filterTargets.forEach((element) => {
                        const param = element.dataset.datatableFilterParam;
                        if (param && '' !== element.value) {
                            params[param] = element.value;
                        }
                    });
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
            this.centerFilterSwitch(this.includeInactiveWrapperTarget);
        }
        if (this.hasOnlyActiveWrapperTarget) {
            this.centerFilterSwitch(this.onlyActiveWrapperTarget);
        }
    }

    // Stimulus invokes these automatically whenever an element with the target attribute enters
    // the DOM, whenever that happens to occur - unlike reading the target synchronously in
    // connect(), it isn't racy against the browser still parsing the rest of the page.
    includeInactiveWrapperTargetConnected(element) {
        if (this.$headerRow) {
            this.centerFilterSwitch(element);
        }
    }

    onlyActiveWrapperTargetConnected(element) {
        if (this.$headerRow) {
            this.centerFilterSwitch(element);
        }
    }

    // Moves a toggle switch (rendered by Twig above the table, e.g. "show inactive" or "active
    // loans only") into the DataTables header row, centered between the length control and the
    // search box.
    centerFilterSwitch(element) {
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

        const duplicateButton = event.target.closest('[data-datatable-duplicate-id]');
        if (duplicateButton) {
            this.performAction(
                duplicateButton,
                this.duplicateUrlTemplateValue,
                duplicateButton.dataset.datatableDuplicateId,
                this.duplicateTokenValue,
                this.duplicateConfirmMessageValue,
                this.duplicateErrorMessageValue,
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

    // confirmMessage is optional - omitting the corresponding *-confirm-message-value attribute
    // (see templates/program/settings/add.html.twig) skips the confirm() prompt entirely, for
    // actions low-stakes enough not to need one (unlike deactivate/remove, which always pass one).
    performAction(button, urlTemplate, id, token, confirmMessage, errorMessage) {
        if (confirmMessage && !window.confirm(confirmMessage)) {
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

                return response.json();
            })
            .then((data) => {
                // A row action that produces a new record to look at (e.g. duplicating a
                // PeriodGroup) navigates straight there instead of just reloading this table -
                // deactivate/add/remove never set this, so they keep reloading in place as before.
                if (data && data.redirectUrl) {
                    window.location.href = data.redirectUrl;

                    return;
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
                    // Optional - a template that doesn't set data-datatable-deactivate-url-template-value
                    // (e.g. users/index.html.twig, which has no deactivate action at all) just gets
                    // an edit-only action column instead.
                    const deactivateButton = this.hasDeactivateUrlTemplateValue && !row.isInactive
                        ? `<button type="button" class="btn btn-ghost-danger btn-sm" data-datatable-deactivate-id="${row.id}">${escapeHtml(this.deactivateLabelValue)}</button>`
                        : '';
                    // Optional - only PeriodGroup's list sets data-datatable-duplicate-url-template-value.
                    const duplicateButton = this.hasDuplicateUrlTemplateValue
                        ? `<button type="button" class="btn btn-ghost-secondary btn-sm" data-datatable-duplicate-id="${row.id}">${escapeHtml(this.duplicateLabelValue)}</button>`
                        : '';

                    return `<div class="btn-list flex-nowrap"><a href="${editUrl}" class="btn btn-ghost-warning btn-sm">${escapeHtml(this.editLabelValue)}</a>${duplicateButton}${deactivateButton}</div>`;
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
                    // Optional - only set by templates that have a PDF-export route wired up
                    // (e.g. program/internship/_tutors_content.html.twig), not every user of
                    // this shared 'reportActions' renderer (e.g. _reports_content.html.twig).
                    const pdfButton = this.hasPdfUrlTemplateValue
                        ? `<a href="${this.pdfUrlTemplateValue.replace('__ID__', row.id)}" class="btn btn-ghost-primary btn-sm">${escapeHtml(this.pdfLabelValue)}</a>`
                        : '';
                    const deactivateButton = row.isInactive
                        ? ''
                        : `<button type="button" class="btn btn-ghost-danger btn-sm" data-datatable-deactivate-id="${row.id}">${escapeHtml(this.deactivateLabelValue)}</button>`;

                    return `<div class="btn-list flex-nowrap"><a href="${printUrl}" class="btn btn-ghost-secondary btn-sm" target="_blank">${escapeHtml(this.printLabelValue)}</a>${pdfButton}<a href="${editUrl}" class="btn btn-ghost-warning btn-sm">${escapeHtml(this.editLabelValue)}</a>${deactivateButton}</div>`;
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

        // Program members (students or teachers) with an "assign options" edit action next to
        // the usual remove action - see templates/program/settings/_students_content.html.twig
        // and _teachers_content.html.twig.
        if (column.render === 'memberActions') {
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

        // Laptop inventory row actions: Lend/Return are navigations (not one-click actions like
        // 'add'/'remove' below) because lending/returning also requires filling in a form
        // (borrower + due date, or return condition + notes) - see templates/laptop/*.html.twig.
        if (column.render === 'laptopActions') {
            return {
                data: null,
                orderable: false,
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    const editUrl = this.editUrlTemplateValue.replace('__ID__', row.id);
                    const editButton = `<a href="${editUrl}" class="btn btn-ghost-warning btn-sm">${escapeHtml(this.editLabelValue)}</a>`;

                    const historyUrl = this.historyUrlTemplateValue.replace('__ID__', row.id);
                    const historyButton = `<a href="${historyUrl}" class="btn btn-ghost-secondary btn-sm">${escapeHtml(this.historyLabelValue)}</a>`;

                    let loanButton = '';
                    if (row.isOnLoan) {
                        const returnUrl = this.returnUrlTemplateValue.replace('__ID__', row.id);
                        loanButton = `<a href="${returnUrl}" class="btn btn-ghost-success btn-sm">${escapeHtml(this.returnLabelValue)}</a>`;
                    } else if (!row.isInactive) {
                        const lendUrl = this.lendUrlTemplateValue.replace('__ID__', row.id);
                        loanButton = `<a href="${lendUrl}" class="btn btn-ghost-primary btn-sm">${escapeHtml(this.lendLabelValue)}</a>`;
                    }

                    const deactivateButton = (row.isInactive || row.isOnLoan)
                        ? ''
                        : `<button type="button" class="btn btn-ghost-danger btn-sm" data-datatable-deactivate-id="${row.id}">${escapeHtml(this.deactivateLabelValue)}</button>`;

                    return `<div class="btn-list flex-nowrap">${loanButton}${historyButton}${editButton}${deactivateButton}</div>`;
                },
            };
        }

        // A GET navigation to a per-row URL, unlike 'add' (an immediate POST) - used by the
        // laptop borrower picker page, where picking a row opens the actual lend form rather
        // than instantly creating the loan.
        if (column.render === 'select') {
            return {
                data: null,
                orderable: false,
                render: (data, type, row) => (type === 'display'
                    ? `<a href="${this.selectUrlTemplateValue.replace('__ID__', row.id)}" class="btn btn-primary btn-sm">${escapeHtml(this.selectLabelValue)}</a>`
                    : ''),
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
