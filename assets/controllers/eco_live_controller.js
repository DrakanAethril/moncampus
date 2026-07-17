import { Controller } from '@hotwired/stimulus';

/**
 * Polls the e-CO live safety endpoint (App\Controller\EcoCourseController::liveData(), screen 1h)
 * every 10s and refreshes each already-rendered runner row in place - see that screen's own
 * "rafraîchie toutes les 10 s" note. Only updates rows that exist at connect() time (matched by
 * data-eco-live-runner-id); a runner joining mid-poll appears on the next full page load, same
 * simplification as the rest of this phase's live view (no map yet either, see course_live.html.twig).
 */
export default class extends Controller {
    static targets = ['row'];

    static values = {
        url: String,
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
            if (checkpointsCell && row.status !== 'finished') {
                checkpointsCell.textContent = `${row.checkpointsValidated}/${row.checkpointsTotal}`;
            }

            const signalCell = rowElement.querySelector('[data-eco-live-target="signal"]');
            if (signalCell) {
                signalCell.textContent = this.signalText(row);
            }
        });
    }

    signalText(row) {
        if (null !== row.appLeftSeconds && undefined !== row.appLeftSeconds) {
            return `hors app · ${row.appLeftSeconds}s`;
        }
        if (null !== row.lastSignalSeconds && undefined !== row.lastSignalSeconds) {
            return `il y a ${row.lastSignalSeconds}s`;
        }

        return '—';
    }
}
