import { Controller } from '@hotwired/stimulus';
import $ from 'jquery';
import 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';

// Suppress DataTables' own alert() on a failed/malformed ajax response - handled per-request via
// the `error` callback in connect() below instead (see its comment for why).
$.fn.dataTable.ext.errMode = 'none';

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
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['table', 'includeInactive', 'includeInactiveWrapper', 'onlyActive', 'onlyActiveWrapper', 'filter'];

    static values = {
        url: String,
        columns: Array,
        language: Object,
        // Indication du champ de recherche (maquettes 25a/25d : "Étudiant, n° d'inventaire…").
        // Séparée de `language` parce que DataTables ne la propose pas dans son objet de langue.
        searchPlaceholder: String,
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
        launchUrlTemplate: String,
        launchLabel: String,
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
        // Whether the row's "print" action stays in this tab. Off by default: it normally opens a
        // standalone print document (report print sheets), which belongs in its own tab. The
        // internship tutors list sets it, because there the same action opens a full application
        // page - the booklet reader - which has its own navigation and nothing to print directly.
        printSameTab: Boolean,
        pdfLabel: String,
        lendUrlTemplate: String,
        lendLabel: String,
        returnUrlTemplate: String,
        returnLabel: String,
        registerReturnLabel: String,
        conventionLabel: String,
        returnFormLabel: String,
        historyUrlTemplate: String,
        historyLabel: String,
        selectUrlTemplate: String,
        selectLabel: String,
        revealUrlTemplate: String,
        revealToken: String,
        revealLabel: String,
        revealErrorMessage: String,
        modalitiesUrlTemplate: String,
        modalitiesLabel: String,
    };

    connect() {
        // Campus Manager's row-zebra/hover CSS (assets/styles/app.css, ported from
        // design/design_campus_manager/css/campus-theme.css) targets DataTables' own "stripe"/
        // "hover" styling classes (table.dataTable.stripe / table.dataTable.hover), which have to
        // be present in the <table>'s class list - DataTables doesn't add them itself, and this
        // project's Twig templates use Bootstrap's own table-striped instead. Adding them here,
        // once, covers every table this controller touches instead of every template remembering to.
        this.tableTarget.classList.add('stripe', 'hover');

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
                // Most likely cause of a JSON parse failure here: the session died between page
                // load and this request (e.g. a deploy recreated the prod container mid-
                // navigation - see config/packages/framework.yaml) and the server redirected to
                // the login page's HTML instead of returning JSON. Reload once so the browser
                // lands on a clean /login instead of DataTables' cryptic "Invalid JSON response"
                // alert - guarded via sessionStorage to fire only once per tab, in case the
                // non-JSON response has some other, persistent cause (a genuine reload loop would
                // be worse than the original alert).
                error: (jqXHR, textStatus) => {
                    if ('parsererror' !== textStatus || window.sessionStorage.getItem('datatableReloadedForAuth')) {
                        return;
                    }

                    window.sessionStorage.setItem('datatableReloadedForAuth', '1');
                    window.location.reload();
                },
            },
            // `emptyTable` (tableau vide) est une chaîne distincte de `zeroRecords` (recherche sans
            // résultat) chez DataTables, et aucun gabarit ne la fournissait : le tableau vide
            // affichait donc l'anglais d'origine, "No data available in table". Faute de mieux,
            // c'est le message de recherche vide qui sert de repli - il dit la même chose - mais un
            // gabarit qui fournit sa propre clé garde la main, d'où l'ordre des deux termes.
            language: { emptyTable: this.languageValue.zeroRecords, ...this.languageValue },
            initComplete: () => {
                if ('' !== this.searchPlaceholderValue) {
                    // Les deux sélecteurs : DataTables 1.x nomme le bloc .dataTables_filter, 2.x
                    // le nomme .dt-search.
                    this.element.querySelector('.dataTables_filter input, .dt-search input')?.setAttribute('placeholder', this.searchPlaceholderValue);
                }
            },
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
        // Pas de .text-secondary ici : cette utilitaire de Tabler pose sa couleur en !important et
        // rendait inatteignable celle de la maquette (voir .dt-info dans assets/styles/app.css).

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

            return;
        }

        const revealButton = event.target.closest('[data-datatable-reveal-id]');
        if (revealButton) {
            this.revealSecret(revealButton);
        }
    }

    // Unlike performAction() above (which reloads the table or navigates away on success), this
    // shows the returned secret in place of the button itself - the whole point of clicking it -
    // and never reloads the table, since the row's state doesn't change. Used by the Directory >
    // Mots de passe list to reveal a generated LDAP password on demand (see
    // App\Controller\DirectoryPasswordController::reveal()).
    revealSecret(button) {
        const url = this.revealUrlTemplateValue.replace('__ID__', button.dataset.datatableRevealId);

        fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.revealTokenValue },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Unexpected response status: ${response.status}`);
                }

                return response.json();
            })
            .then((data) => {
                button.outerHTML = `<code>${escapeHtml(data.password)}</code>`;
            })
            .catch(() => window.alert(this.revealErrorMessageValue));
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

    // The booklet PDF export takes several seconds (Gotenberg renders the whole livret), so the
    // row action carries the same pdf-download controller as the standalone export buttons - a
    // spinner in place of the label while the file is being built. No loading-label value here,
    // unlike those buttons: swapping the text would jolt the width of the actions column.
    pdfActionLink(rowId) {
        return `<a href="${this.pdfUrlTemplateValue.replace('__ID__', rowId)}" class="cm-action--neutral"`
            + ` data-controller="pdf-download" data-action="pdf-download#download"`
            + ` data-pdf-download-fallback-filename-value="livret-alternant.pdf">`
            + `<span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true" data-pdf-download-target="spinner"></span>`
            + `${escapeHtml(this.pdfLabelValue)}</a>`;
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

        // Same idea as 'badge', but for a value whose color is arbitrary (admin-picked hex, e.g.
        // App\Entity\LaptopConditionType::$color) rather than one of a fixed set of Bootstrap
        // classes - column.colorField names the row property holding that hex value. Renders
        // nothing for a null/empty name (e.g. a loan not yet returned has no return condition).
        if (column.render === 'colorBadge') {
            return {
                data: column.data,
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return data ?? '';
                    }

                    if (!data) {
                        return '';
                    }

                    const color = row[column.colorField];

                    // Pastel plutôt que fond plein : texte à la couleur de l'état sur un fond
                    // teinté, comme les maquettes 25a/25d du handoff UFA.
                    return `<span class="badge" style="background: color-mix(in srgb, ${escapeHtml(color)} 14%, #fff); color: ${escapeHtml(color)}; border: 1px solid color-mix(in srgb, ${escapeHtml(color)} 28%, #fff);">${escapeHtml(data)}</span>`;
                },
            };
        }

        if (column.render === 'badges') {
            const badgeClass = column.badgeClass ?? 'bg-blue-lt';

            return {
                data: column.data,
                render: (data, type) => {
                    if (type !== 'display') {
                        return Array.isArray(data) ? data.join(', ') : '';
                    }

                    return Array.isArray(data)
                        ? data.map((value) => `<span class="badge ${badgeClass} me-1">${escapeHtml(value)}</span>`).join('')
                        : '';
                },
            };
        }

        // Same idea as 'badge', but the color is fixed per-column (column.badgeClass) instead of
        // read from a row field - for a single value whose color doesn't vary row-to-row (e.g.
        // directory/users.html.twig's "Type" column, kept visually distinct from the "groupes"/
        // "groupes manuels" badge columns on the same row).
        if (column.render === 'staticBadge') {
            return {
                data: column.data,
                render: (data, type) => (type === 'display'
                    ? `<span class="badge ${column.badgeClass}">${escapeHtml(data)}</span>`
                    : data),
            };
        }

        // Row actions render as plain color-coded text links/buttons (.cm-actions, assets/styles/
        // app.css), not Bootstrap buttons - className: 'cm-actions' puts that class on both the
        // <th> and every <td> in the column. Color follows the action's semantics, not its markup
        // (edit-style navigations are always warning-colored, deactivate/remove always danger,
        // etc.) regardless of which render branch/template it comes from.
        if (column.render === 'actions') {
            return {
                data: null,
                orderable: false,
                className: 'cm-actions',
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    // Optional - directory/users.html.twig's queue rows can have no linked User
                    // at all (see App\Entity\LdapManageUser::$user's docblock), so row.id is null
                    // and there's nothing to edit; every other user of this renderer always has a
                    // real id.
                    if (null === row.id) {
                        return '';
                    }

                    const editUrl = this.editUrlTemplateValue.replace('__ID__', row.id);
                    // row.isAdmin is only set by directory/users.html.twig's data endpoint (see
                    // App\Controller\DirectoryUserController::data()) - staff must not be able to
                    // edit an admin profile from that list; every other user of this renderer
                    // never sets it, so this is a no-op there.
                    const editButton = row.isAdmin
                        ? ''
                        : `<a href="${editUrl}" class="cm-action--warning">${escapeHtml(this.editLabelValue)}</a>`;
                    // Optional - a template that doesn't set data-datatable-deactivate-url-template-value
                    // just gets an edit-only action column instead.
                    const deactivateButton = this.hasDeactivateUrlTemplateValue && !row.isInactive
                        ? `<button type="button" class="cm-action--danger" data-datatable-deactivate-id="${row.id}">${escapeHtml(this.deactivateLabelValue)}</button>`
                        : '';
                    // Optional - only PeriodGroup's list sets data-datatable-duplicate-url-template-value.
                    const duplicateButton = this.hasDuplicateUrlTemplateValue
                        ? `<button type="button" class="cm-action--neutral" data-datatable-duplicate-id="${row.id}">${escapeHtml(this.duplicateLabelValue)}</button>`
                        : '';

                    return `${editButton}${duplicateButton}${deactivateButton}`;
                },
            };
        }

        // Gestion > Mes quiz (screen 1a): Lancer (positive) / Modifier (warning) / Dupliquer
        // (neutral, one-click) / Supprimer (danger, one-click - reuses the same
        // remove-url-template/-token/-label values as the generic 'remove' renderer above, since
        // a quiz template is hard-deleted rather than deactivated). Lancer is intentionally
        // optional (this.hasLaunchUrlTemplateValue) - Phase 1 ships this list before the "Lancer"
        // route exists (see App\Controller\QuizLibraryController); the template just omits
        // data-datatable-launch-url-template-value until that route lands, and the link appears
        // with no further JS change.
        if (column.render === 'quizTemplateActions') {
            return {
                data: null,
                orderable: false,
                className: 'cm-actions',
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    const launchButton = this.hasLaunchUrlTemplateValue
                        ? `<a href="${this.launchUrlTemplateValue.replace('__ID__', row.id)}" class="cm-action--positive">${escapeHtml(this.launchLabelValue)}</a>`
                        : '';
                    const editUrl = this.editUrlTemplateValue.replace('__ID__', row.id);
                    const duplicateButton = `<button type="button" class="cm-action--neutral" data-datatable-duplicate-id="${row.id}">${escapeHtml(this.duplicateLabelValue)}</button>`;
                    const removeButton = `<button type="button" class="cm-action--danger" data-datatable-remove-id="${row.id}">${escapeHtml(this.removeLabelValue)}</button>`;

                    return `${launchButton}<a href="${editUrl}" class="cm-action--warning">${escapeHtml(this.editLabelValue)}</a>${duplicateButton}${removeButton}`;
                },
            };
        }

        if (column.render === 'reportActions') {
            return {
                data: null,
                orderable: false,
                className: 'cm-actions',
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
                        ? this.pdfActionLink(row.id)
                        : '';
                    const deactivateButton = row.isInactive
                        ? ''
                        : `<button type="button" class="cm-action--danger" data-datatable-deactivate-id="${row.id}">${escapeHtml(this.deactivateLabelValue)}</button>`;

                    return `<a href="${printUrl}" class="cm-action--neutral"${this.printSameTabValue ? '' : ' target="_blank"'}>${escapeHtml(this.printLabelValue)}</a>${pdfButton}<a href="${editUrl}" class="cm-action--warning">${escapeHtml(this.editLabelValue)}</a>${deactivateButton}`;
                },
            };
        }

        if (column.render === 'add') {
            return {
                data: null,
                orderable: false,
                className: 'cm-actions',
                render: (data, type, row) => (type === 'display'
                    ? `<button type="button" class="cm-action--positive" data-datatable-add-id="${row.id}">${escapeHtml(this.addLabelValue)}</button>`
                    : ''),
            };
        }

        // Program members (students or teachers) with an "assign options" edit action next to
        // the usual remove action - see templates/program/settings/_students_content.html.twig
        // and _teachers_content.html.twig. The modalities action is optional and students-only
        // (this.hasModalitiesUrlTemplateValue) - a program with modalities but no options still
        // needs this column, hence editUrlTemplateValue being optional here too, unlike every
        // other renderer above that treats it as always set.
        if (column.render === 'memberActions') {
            return {
                data: null,
                orderable: false,
                className: 'cm-actions',
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    const editButton = this.hasEditUrlTemplateValue
                        ? `<a href="${this.editUrlTemplateValue.replace('__ID__', row.id)}" class="cm-action--warning">${escapeHtml(this.editLabelValue)}</a>`
                        : '';
                    const modalitiesButton = this.hasModalitiesUrlTemplateValue
                        ? `<a href="${this.modalitiesUrlTemplateValue.replace('__ID__', row.id)}" class="cm-action--warning">${escapeHtml(this.modalitiesLabelValue)}</a>`
                        : '';

                    return `${editButton}${modalitiesButton}<button type="button" class="cm-action--danger" data-datatable-remove-id="${row.id}">${escapeHtml(this.removeLabelValue)}</button>`;
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
                className: 'cm-actions',
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    const editUrl = this.editUrlTemplateValue.replace('__ID__', row.id);
                    const editButton = `<a href="${editUrl}" class="cm-action--warning">${escapeHtml(this.editLabelValue)}</a>`;

                    const historyUrl = this.historyUrlTemplateValue.replace('__ID__', row.id);
                    const historyButton = `<a href="${historyUrl}" class="cm-action--neutral">${escapeHtml(this.historyLabelValue)}</a>`;

                    let loanButton = '';
                    if (row.isOnLoan) {
                        const returnUrl = this.returnUrlTemplateValue.replace('__ID__', row.id);
                        loanButton = `<a href="${returnUrl}" class="cm-action--positive">${escapeHtml(this.returnLabelValue)}</a>`;
                    } else if (!row.isInactive) {
                        const lendUrl = this.lendUrlTemplateValue.replace('__ID__', row.id);
                        loanButton = `<a href="${lendUrl}" class="cm-action--positive">${escapeHtml(this.lendLabelValue)}</a>`;
                    }

                    const deactivateButton = (row.isInactive || row.isOnLoan)
                        ? ''
                        : `<button type="button" class="cm-action--danger" data-datatable-deactivate-id="${row.id}">${escapeHtml(this.deactivateLabelValue)}</button>`;

                    return `${loanButton}${historyButton}${editButton}${deactivateButton}`;
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
                className: 'cm-actions',
                render: (data, type, row) => (type === 'display'
                    ? `<a href="${this.selectUrlTemplateValue.replace('__ID__', row.id)}" class="cm-action--positive">${escapeHtml(this.selectLabelValue)}</a>`
                    : ''),
            };
        }

        // Row-conditional (row.canReveal) - only a "succeeded" ldap_manage_password row actually
        // has a password to decrypt, see App\Repository\LdapManagePasswordRepository::decryptPassword().
        if (column.render === 'reveal') {
            return {
                data: null,
                orderable: false,
                className: 'cm-actions',
                render: (data, type, row) => (type === 'display' && row.canReveal
                    ? `<button type="button" class="cm-action--neutral" data-datatable-reveal-id="${row.id}">${escapeHtml(this.revealLabelValue)}</button>`
                    : ''),
            };
        }

        if (column.render === 'remove') {
            return {
                data: null,
                orderable: false,
                className: 'cm-actions',
                render: (data, type, row) => (type === 'display'
                    ? `<button type="button" class="cm-action--danger" data-datatable-remove-id="${row.id}">${escapeHtml(this.removeLabelValue)}</button>`
                    : ''),
            };
        }

        // 25a's loans list - a single "Enregistrer le retour" link straight to the laptop's
        // return form, blank once the loan is already returned (row.canReturn is false).
        if (column.render === 'loanReturnAction') {
            return {
                data: null,
                orderable: false,
                className: 'cm-actions',
                render: (data, type, row) => (type === 'display' && row.canReturn
                    ? `<a href="${row.returnUrl}" class="cm-action--warning">${escapeHtml(this.registerReturnLabelValue)}</a>`
                    : ''),
            };
        }

        // The loan's two paper documents. The convention is available as soon as the loan is
        // saved, the restitution form only once the return has been recorded - it prints
        // pre-filled, to be signed. Either URL is null when the loan has no printable model (see
        // App\Service\LaptopLoanDocumentExporter::supports()), and the cell is then left empty
        // rather than offering a link that would render nothing.
        if (column.render === 'loanDocuments') {
            return {
                data: null,
                orderable: false,
                className: 'cm-actions',
                render: (data, type, row) => {
                    if (type !== 'display') {
                        return '';
                    }

                    const links = [];

                    if (row.conventionUrl) {
                        links.push(`<a href="${row.conventionUrl}" target="_blank" rel="noopener" class="cm-action--neutral">${escapeHtml(this.conventionLabelValue)}</a>`);
                    }

                    if (row.returnFormUrl) {
                        links.push(`<a href="${row.returnFormUrl}" target="_blank" rel="noopener" class="cm-action--neutral">${escapeHtml(this.returnFormLabelValue)}</a>`);
                    }

                    return links.join(' ');
                },
            };
        }

        return { data: column.data, render: (data, type) => (type === 'display' ? escapeHtml(data) : data) };
    }
}
