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

export default class extends Controller {
    static targets = [
        'slider', 'hiddenInput', 'zoneBadge', 'barFacile', 'barMoyen', 'barDifficile',
        'legendFacile', 'legendMoyen', 'legendDifficile',
        'questionCount', 'sameQuestionsForAll', 'questionOrderPerStudent', 'answerOrderPerStudent',
        'noteText',
    ];

    static values = {
        labels: Object,
        noteTemplate: String,
    };

    connect() {
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
