import { Controller } from '@hotwired/stimulus';

/**
 * Polls the e-CO live safety endpoint (App\Controller\EcoCourseController::liveData(), screen 1h)
 * every 10s and refreshes each already-rendered runner row in place - see that screen's own
 * "rafraîchie toutes les 10 s" note. Only updates rows that exist at connect() time (matched by
 * data-eco-live-runner-id); a runner joining mid-poll appears on the next full page load, same
 * simplification as the rest of this phase's live view (no map yet either, see course_live.html.twig).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['row'];

    static values = {
        url: String,
        // The results screen, without its ?runner= - a runner who scans the finish between two
        // polls becomes a link here without waiting for a page load.
        resultsUrl: String,
        finishedLabel: String,
        seeRaceLabel: String,
        intervalMs: { type: Number, default: 10000 },
    };

    connect() {
        this.poll();
        this.interval = setInterval(() => this.poll(), this.intervalMsValue);
    }

    disconnect() {
        clearInterval(this.interval);
    }

    poll() {
        fetch(this.urlValue, { headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((data) => this.applyRows(data.runners))
            .catch(() => {});
    }

    applyRows(rows) {
        rows.forEach((row) => {
            const rowElement = this.rowTargets.find((element) => Number(element.dataset.ecoLiveRunnerId) === row.id);
            if (!rowElement) {
                return;
            }

            rowElement.classList.toggle('table-danger', row.sosActive);
            rowElement.classList.toggle('table-warning', !row.sosActive && row.status !== 'finished' && row.isStale);

            const checkpointsCell = rowElement.querySelector('[data-eco-live-target="checkpoints"]');
            if (checkpointsCell) {
                checkpointsCell.textContent = row.status === 'finished'
                    ? this.finishedLabelValue
                    : `${row.checkpointsValidated}/${row.checkpointsTotal}`;
            }

            const pseudoCell = rowElement.querySelector('[data-eco-live-target="pseudo"]');
            if (pseudoCell && row.status === 'finished' && !pseudoCell.querySelector('a')) {
                this.linkPseudo(pseudoCell, row);
            }

            const signalCell = rowElement.querySelector('[data-eco-live-target="signal"]');
            if (signalCell) {
                // Already worded and translated by EcoLiveTrackingService - the same string the
                // server rendered on first paint, so a refresh never changes how the cell reads.
                signalCell.textContent = row.signalLabel;
                signalCell.classList.toggle('text-warning', row.signalWarning);
            }
        });
    }

    // Built with DOM nodes, never innerHTML: the pseudo is whatever the runner typed on their phone.
    linkPseudo(cell, row) {
        const url = new URL(this.resultsUrlValue, window.location.origin);
        url.searchParams.set('runner', String(row.id));

        const link = document.createElement('a');
        link.href = url.pathname + url.search;
        link.title = this.seeRaceLabelValue;
        link.textContent = row.pseudo;

        cell.replaceChildren(link, document.createTextNode(row.sosActive ? ' 🆘' : ''));
    }
}
