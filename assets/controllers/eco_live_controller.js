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
    static targets = ['row', 'reports'];

    static values = {
        url: String,
        // The results screen, without its ?runner= - a runner who scans the finish between two
        // polls becomes a link here without waiting for a page load.
        resultsUrl: String,
        finishedLabel: String,
        seeRaceLabel: String,
        // « Balise introuvable »: the block of flags reported missing, asked again whenever its
        // signature in the polled data moves (a report, a neutralisation).
        reportsUrl: String,
        reportsSignature: String,
        // « 1 signalée », with __COUNT__ where the number goes.
        reportedLabel: String,
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
            .then((data) => {
                this.applyRows(data.runners);
                this.refreshReports(data.reportsSignature);
            })
            .catch(() => {});
    }

    refreshReports(signature) {
        if (!signature || signature === this.reportsSignatureValue || !this.hasReportsTarget) {
            return;
        }

        fetch(this.reportsUrlValue, { headers: { Accept: 'text/html' } })
            .then((response) => (response.ok ? response.text() : Promise.reject(response)))
            .then((html) => {
                // Our own server's fragment, rendered by Twig with its escaping: safe as markup.
                this.reportsTarget.innerHTML = html;
                this.reportsSignatureValue = signature;
            })
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

            const reportedCell = rowElement.querySelector('[data-eco-live-target="reported"]');
            if (reportedCell) {
                reportedCell.hidden = !row.reportedCount;
                reportedCell.textContent = this.reportedLabelValue.replace('__COUNT__', String(row.reportedCount ?? 0));
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
