import { Controller } from '@hotwired/stimulus';

/**
 * Paramètres › Pédagogique › Années scolaires › Importer, screen 2 (design/validated/
 * school-year-import.md §2).
 *
 * Two jobs, and nothing of the business: greying a box whose dependency is unticked (the
 * dependencies come from the server, `data-needs` / `data-needs-any` on each box - the server applies
 * them again on its side), and adding up, in the summary panel, the counts the server put on each
 * formation (`data-counts`, one dry run with everything ticked) for the formations and boxes ticked.
 * The exact plan is screen 3's.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['block', 'program', 'all', 'programCount', 'created', 'completed', 'rows', 'rowTemplate', 'dates', 'datesTemplate', 'submit'];
    static values = { sourceUrl: String, needsLabel: String, selectedLabel: String };

    connect() {
        this.update();
    }

    changeSource(event) {
        const url = new URL(this.sourceUrlValue, window.location.origin);
        url.searchParams.set('source', event.target.value);
        window.location.assign(url.toString());
    }

    toggleAll() {
        for (const box of this.programTargets) {
            box.checked = this.allTarget.checked;
        }
        this.update();
    }

    update() {
        this.applyDependencies();

        const ticked = new Set(this.blockTargets.filter((box) => box.checked && !box.disabled).map((box) => box.value));
        const programs = this.programTargets.filter((box) => box.checked);

        if (this.hasAllTarget) {
            this.allTarget.checked = programs.length === this.programTargets.length && programs.length > 0;
            this.allTarget.indeterminate = programs.length > 0 && programs.length < this.programTargets.length;
        }
        if (this.hasProgramCountTarget) {
            this.programCountTarget.textContent = this.selectedLabelValue
                .replace('%count%', String(programs.length))
                .replace('%total%', String(this.programTargets.length));
        }

        const sums = {};
        let created = 0;
        let completed = 0;
        let dates = 0;
        for (const box of programs) {
            if ('completed' === box.dataset.status) {
                completed += 1;
            } else {
                created += 1;
            }
            const counts = JSON.parse(box.dataset.counts || '{}');
            for (const [block, count] of Object.entries(counts)) {
                if (ticked.has(block)) {
                    sums[block] = (sums[block] || 0) + count;
                }
            }
            if (ticked.has('internship') || ticked.has('portfolio')) {
                dates += Number(box.dataset.dates || 0);
            }
        }

        this.createdTarget.textContent = String(created);
        this.completedTarget.textContent = String(completed);

        const rows = [];
        for (const box of this.blockTargets) {
            if (box.dataset.calendars !== undefined) {
                if (ticked.has(box.value) && Number(box.dataset.calendars) > 0) {
                    rows.push([box.closest('label').querySelector('.cm-syi-check__title').textContent, Number(box.dataset.calendars)]);
                    dates += Number(box.dataset.periods || 0);
                }
                continue;
            }
            const sum = sums[box.value];
            const cell = box.closest('label').querySelector('[data-sum]');
            if (cell) {
                cell.textContent = ticked.has(box.value) && sum ? String(sum) : '';
            }
            if (ticked.has(box.value) && sum) {
                rows.push([box.dataset.label, sum]);
            }
        }

        this.rowsTarget.replaceChildren(...rows.map(([label, value]) => {
            const row = this.rowTemplateTarget.content.firstElementChild.cloneNode(true);
            row.querySelector('[data-label]').textContent = label;
            row.querySelector('[data-value]').textContent = String(value);
            return row;
        }));

        this.datesTarget.hidden = 0 === dates;
        this.datesTarget.textContent = this.datesTemplateTarget.content.textContent.trim().replace('%count%', String(dates));
        this.submitTarget.disabled = 0 === programs.length && !ticked.has('period_calendars');
    }

    // A box whose dependency is unticked is greyed and unticked - and ticked again, as it was, once
    // the dependency comes back. Repeated until nothing moves: holders depend on the team, which may
    // itself have just changed.
    applyDependencies() {
        const byValue = Object.fromEntries(this.blockTargets.map((box) => [box.value, box]));
        const isOn = (value) => byValue[value] && byValue[value].checked && !byValue[value].disabled;

        let moved = true;
        let guard = 0;
        while (moved && guard < 10) {
            moved = false;
            guard += 1;
            for (const box of this.blockTargets) {
                const needs = (box.dataset.needs || '').split(' ').filter(Boolean);
                const needsAny = (box.dataset.needsAny || '').split(' ').filter(Boolean);
                const missing = needs.filter((value) => !isOn(value));
                const anyMissing = needsAny.length > 0 && !needsAny.some((value) => isOn(value));
                const blocked = missing.length > 0 || anyMissing;

                if (blocked && !box.disabled) {
                    box.dataset.wasChecked = box.checked ? '1' : '0';
                    box.checked = false;
                    box.disabled = true;
                    moved = true;
                } else if (!blocked && box.disabled) {
                    box.disabled = false;
                    box.checked = '1' === box.dataset.wasChecked;
                    moved = true;
                }

                const label = box.closest('label');
                label?.classList.toggle('is-disabled', blocked);
                const hint = label?.querySelector('[data-needs-hint]');
                if (hint) {
                    const names = [...missing, ...(anyMissing ? needsAny : [])].map((value) => byValue[value]?.dataset.label).filter(Boolean);
                    hint.hidden = !blocked;
                    hint.textContent = blocked ? this.needsLabelValue.replace('%blocks%', names.join(', ')) : '';
                }
            }
        }
    }
}
