import { Controller } from '@hotwired/stimulus';

// Screen 1c (Lancer) - live difficulty-slider recap (zone label + stacked facile/moyen/difficile
// bar + per-level question counts) and the one-sentence .cm-note summary, both recomputed
// instantly on any relevant input change. This is a *preview* only: the authoritative recipe is
// always recomputed server-side from the submitted slider position at launch time (see
// App\Service\QuizDifficultyDistributionResolver) - the 5-zone table below is a client-side mirror
// of that same PHP logic, not a second source of truth.
const ZONES = [
    { max: 20, labelKey: 'quizDifficultyZoneTresFacileLabel', facile: 60, moyen: 30, difficile: 10 },
    { max: 40, labelKey: 'quizDifficultyZonePlutotFacileLabel', facile: 40, moyen: 40, difficile: 20 },
    { max: 60, labelKey: 'quizDifficultyZoneEquilibreLabel', facile: 20, moyen: 60, difficile: 20 },
    { max: 80, labelKey: 'quizDifficultyZonePlutotDifficileLabel', facile: 20, moyen: 40, difficile: 40 },
    { max: 100, labelKey: 'quizDifficultyZoneTresDifficileLabel', facile: 10, moyen: 30, difficile: 60 },
];

function resolveZone(position) {
    return ZONES.find((zone) => position <= zone.max) ?? ZONES[ZONES.length - 1];
}

// Same largest-remainder rounding as QuizDifficultyDistributionResolver::resolveCounts() - keeps
// the client-side preview counts summing to exactly $total, matching what the server will store.
function resolveCounts(facilePercent, moyenPercent, difficilePercent, total) {
    const raw = { facile: (facilePercent * total) / 100, moyen: (moyenPercent * total) / 100, difficile: (difficilePercent * total) / 100 };
    const counts = { facile: Math.floor(raw.facile), moyen: Math.floor(raw.moyen), difficile: Math.floor(raw.difficile) };
    let remainder = total - (counts.facile + counts.moyen + counts.difficile);
    const order = Object.keys(raw).sort((a, b) => (raw[b] - Math.floor(raw[b])) - (raw[a] - Math.floor(raw[a])));
    for (let i = 0; remainder > 0; i += 1, remainder -= 1) {
        counts[order[i % order.length]] += 1;
    }
    return counts;
}

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'slider', 'hiddenInput', 'zoneBadge', 'barFacile', 'barMoyen', 'barDifficile',
        'legendFacile', 'legendMoyen', 'legendDifficile',
        'questionCount', 'sameQuestionsForAll', 'questionOrderPerStudent', 'answerOrderPerStudent',
        'secondsPerQuestion', 'noteText',
    ];

    static values = {
        labels: Object,
        noteTemplate: String,
        // templateId -> the quiz's own launch defaults (screen 1n), for the screen that picks the
        // quiz here rather than arriving from it. Empty on the library's own launch screen, where
        // the server put those defaults in the fields before rendering.
        defaults: Object,
        // True on a form sent back with an error: the count on screen is the teacher's own.
        countTouched: Boolean,
    };

    connect() {
        this.questionCountTouched = this.countTouchedValue;
        this.update();
    }

    // quiz-pool:change - a quiz was added to or removed from the pool, so both the draw's ceiling
    // and its sensible default moved. A teacher merging five séance quizzes into one end-of-
    // sequence evaluation means to draw from all of them: leaving the field on the first quiz's
    // three questions would silently throw the merge away, so the untouched count follows the sum
    // of each merged quiz's own default draw (screen 1n). Once they type a number themselves it is
    // theirs, and only the pool ceiling may still lower it.
    // The server clamps the submitted count to the real pool size regardless
    // (QuizLibraryController::launch) - this is about what the field *shows*.
    poolChanged(event) {
        const total = Math.max(0, Number(event.detail.total) || 0);

        if (total > 0) {
            this.questionCountTarget.max = String(total);

            const desired = this.questionCountTouched
                ? (parseInt(this.questionCountTarget.value, 10) || 0)
                : Number(event.detail.defaultTotal) || 0;

            this.questionCountTarget.value = String(Math.max(1, Math.min(desired, total)));
        }

        this.update();
    }

    // The base quiz was picked (or changed) on a screen opened without one. Launching a quiz must
    // decide the same thing whichever door it came through, so the quiz's own settings are what the
    // form then shows - the very ones the library's launch screen is rendered with. The draw's size
    // is not among them: it follows the whole pool, and poolChanged() above owns it.
    //
    // Applied rather than merged: the quiz is the first field of the screen, so anything below it
    // is still the previous quiz's answer, not the teacher's.
    baseChanged(event) {
        const defaults = this.defaultsValue[event.currentTarget.value];
        if (defaults === undefined) {
            return;
        }

        this.sameQuestionsForAllTarget.checked = Boolean(defaults.sameQuestions);
        this.questionOrderPerStudentTarget.checked = Boolean(defaults.questionOrder);
        this.answerOrderPerStudentTarget.checked = Boolean(defaults.answerOrder);

        if (this.hasSecondsPerQuestionTarget) {
            // null is « pas de limite » and must stay a blank field, never a number invented here.
            this.secondsPerQuestionTarget.value = defaults.seconds === null || defaults.seconds === undefined
                ? ''
                : String(defaults.seconds);
        }

        this.update();
    }

    questionCountEdited() {
        this.questionCountTouched = true;
        this.update();
    }

    update() {
        this.hiddenInputTarget.value = this.sliderTarget.value;

        const total = Math.max(0, parseInt(this.questionCountTarget.value, 10) || 0);
        const position = parseInt(this.sliderTarget.value, 10);
        const zone = resolveZone(position);
        const counts = resolveCounts(zone.facile, zone.moyen, zone.difficile, total);

        this.zoneBadgeTarget.textContent = this.labelsValue[zone.labelKey] ?? zone.labelKey;

        this.barFacileTarget.style.width = `${zone.facile}%`;
        this.barMoyenTarget.style.width = `${zone.moyen}%`;
        this.barDifficileTarget.style.width = `${zone.difficile}%`;

        this.legendFacileTarget.textContent = `${this.labelsValue.facile} ${zone.facile}% (${counts.facile} q.)`;
        this.legendMoyenTarget.textContent = `${this.labelsValue.moyen} ${zone.moyen}% (${counts.moyen} q.)`;
        this.legendDifficileTarget.textContent = `${this.labelsValue.difficile} ${zone.difficile}% (${counts.difficile} q.)`;

        this.updateNote(total, counts);
    }

    updateNote(total, counts) {
        const sameQuestions = this.sameQuestionsForAllTarget.checked;
        const questionOrder = this.questionOrderPerStudentTarget.checked;
        const answerOrder = this.answerOrderPerStudentTarget.checked;

        const drawSentence = sameQuestions
            ? this.labelsValue.noteSameDrawTemplate.replace('%total%', total).replace('%facile%', counts.facile).replace('%moyen%', counts.moyen).replace('%difficile%', counts.difficile)
            : this.labelsValue.noteOwnDrawTemplate.replace('%total%', total);

        const orderSentence = questionOrder ? this.labelsValue.noteQuestionOrderPerStudent : this.labelsValue.noteQuestionOrderSame;
        const answerSentence = answerOrder ? this.labelsValue.noteAnswerOrderPerStudent : this.labelsValue.noteAnswerOrderSame;

        this.noteTextTarget.innerHTML = `${drawSentence} ${orderSentence}, ${answerSentence}.`;
    }
}
