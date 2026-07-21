import { Controller } from '@hotwired/stimulus';

// Pédagogique > Périodes d'évaluation (design 12a) - a plain, fully server-rendered expandable
// list (see App\Repository\EvaluationPeriodGroupRepository's docblock for why this tab isn't
// DataTables-backed like its siblings): expand/collapse per group, a client-side "show inactive"
// filter, and deactivate/reactivate via the same confirm+fetch+CSRF idiom
// assets/controllers/datatable_controller.js uses for row actions elsewhere.
export default class extends Controller {
    static targets = ['group', 'chevron', 'children', 'includeInactive'];

    static values = {
        deactivateUrlTemplate: String,
        reactivateUrlTemplate: String,
        token: String,
        deactivateConfirmMessage: String,
        errorMessage: String,
    };

    // Every children row starts with data-collapsed="true"/hidden - toggle() is the only place
    // that flips it, so toggleInactive() below can tell a still-collapsed child apart from one
    // whose group is expanded but that's merely filtered out by the inactive switch.
    toggle(event) {
        const groupId = event.currentTarget.dataset.groupId;
        const nowExpanded = event.currentTarget.getAttribute('aria-expanded') !== 'true';
        event.currentTarget.setAttribute('aria-expanded', String(nowExpanded));

        const chevron = event.currentTarget.querySelector('[data-evaluation-period-group-list-target="chevron"]');
        if (chevron) {
            chevron.textContent = nowExpanded ? '▾' : '▸';
        }

        const showInactive = this.includeInactiveTarget.checked;
        for (const row of this.childrenTargets) {
            if (row.dataset.groupId !== groupId) {
                continue;
            }

            row.dataset.collapsed = nowExpanded ? 'false' : 'true';
            row.hidden = !nowExpanded || (row.dataset.inactive === 'true' && !showInactive);
        }
    }

    toggleInactive() {
        const showInactive = this.includeInactiveTarget.checked;

        for (const row of this.groupTargets) {
            if (row.dataset.inactive === 'true') {
                row.hidden = !showInactive;
            }
        }

        for (const row of this.childrenTargets) {
            const collapsed = row.dataset.collapsed !== 'false';
            row.hidden = collapsed || (row.dataset.inactive === 'true' && !showInactive);
        }
    }

    deactivate(event) {
        this.performAction(event.currentTarget.dataset.id, this.deactivateUrlTemplateValue, this.deactivateConfirmMessageValue);
    }

    reactivate(event) {
        this.performAction(event.currentTarget.dataset.id, this.reactivateUrlTemplateValue, null);
    }

    performAction(id, urlTemplate, confirmMessage) {
        if (confirmMessage && !window.confirm(confirmMessage)) {
            return;
        }

        fetch(urlTemplate.replace('__ID__', id), {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.tokenValue },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Unexpected response status: ${response.status}`);
                }

                window.location.reload();
            })
            .catch(() => window.alert(this.errorMessageValue));
    }
}
