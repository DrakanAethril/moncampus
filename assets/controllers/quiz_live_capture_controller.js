import { Controller } from '@hotwired/stimulus';
import { loadAvatarModule } from '../serious_game/avatar_module.js';
import { paintFaces, phoneScreen, roomScreen } from '../quiz_live/render.js';

/**
 * One capture of the theme gallery (App\Controller\QuizLive\HostController::themes()): the real
 * screen - the room's or the phone's, drawn by assets/quiz_live/render.js - for a sample contest,
 * frozen (`.ql-still`). The sample is built here, in the shapes the server publishes
 * (App\Service\QuizLiveSessionService), so a theme is judged on what a contest will look like.
 */

const NAMES = ['Pingouin Turbo', 'Inès M.', 'Captain Kiwi', 'Licorne Pixel', 'Mathéo L.', 'Nina_42', 'Sushi Ninja', 'Plume', 'Yanis R.', 'Rocket', 'Le Hibou', 'Zoé K.'];
const SCORES = [3120, 4890, 2210, 3650, 5230, 1980, 2770, 4410, 1500, 2600, 3980, 4620];
const SHAPES = ['triangle', 'square', 'circle', 'diamond', 'pentagon', 'triangle-down'];

function seeded(seed) {
    let a = seed >>> 0;

    return () => {
        a = (a + 0x6D2B79F5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function topologyPicture() {
    const nodes = [[60, 40], [200, 24], [340, 40], [340, 170], [200, 186], [60, 170]];
    const lines = nodes.map(([x, y]) => `<line x1="200" y1="105" x2="${x}" y2="${y}" stroke="#2B3A55" stroke-width="3"/>`).join('');
    const pcs = nodes.map(([x, y]) => `<g transform="translate(${x - 22},${y - 18})"><rect width="44" height="30" rx="4" fill="#E8EEF7" stroke="#2B3A55" stroke-width="2.5"/><rect x="5" y="5" width="34" height="18" rx="2" fill="#7FB3E8"/></g>`).join('');
    const hub = '<rect x="166" y="90" width="68" height="30" rx="5" fill="#2B5DB8" stroke="#2B3A55" stroke-width="2.5"/>';

    return `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 210">${lines}${pcs}${hub}</svg>`)}`;
}

function sample(module) {
    const players = NAMES.map((name, i) => ({ id: i + 1, displayName: name, avatar: { photo: null, drawn: module.random(seeded(1000 + i * 77)) }, score: SCORES[i] }));
    const ranked = players.slice().sort((a, b) => b.score - a.score).map((p, i) => ({ participantId: p.id, displayName: p.displayName, score: p.score, rank: i + 1, avatar: p.avatar }));
    const options = ['En bus', 'En anneau', 'En étoile', 'Maillée'];
    const answers = options.map((label, i) => ({ answerId: 100 + i, shapeIndex: i, shape: SHAPES[i], label }));
    const question = {
        type: 'question-opened', questionIndex: 1, totalQuestions: 8, kind: 'choice', isVote: false, secondsPerQuestion: 20,
        phaseStartedAt: new Date().toISOString(), answers, label: 'Quelle topologie de réseau montre ce schéma ?', imageUrl: topologyPicture(),
        answeredCount: 7, participantCount: 12,
    };

    return {
        players,
        lobby: { type: 'participant-joined', participantCount: players.length, participants: players.map((p) => ({ id: p.id, displayName: p.displayName, avatar: p.avatar, guest: true })) },
        question,
        results: {
            type: 'results', questionIndex: 2, totalQuestions: 8, kind: 'choice', isVote: false, participantCount: 12, answeredCount: 12, correctCount: 9,
            outcomes: { 4: { answered: true, correct: true, points: 940 } }, leaderboard: ranked, rows: [], label: 'Le RGPD s’applique aussi aux associations.',
        },
        podium: { type: 'session-finished', finalLeaderboard: ranked, podium: ranked.slice(0, 3) },
    };
}

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { kind: String, screen: String, theme: Object, labels: Object, qr: String };

    connect() {
        loadAvatarModule().then((module) => {
            const data = sample(module);
            const me = data.players[3];
            const ctx = {
                words: this.themeValue.words || {},
                slug: this.themeValue.slug,
                labels: this.labelsValue,
                quizName: 'Culture numérique',
                public: true,
                code: 'QZ4K7M',
                joinLabel: `${window.location.host}/live`,
                qrSvg: this.qrValue,
                me: { participantId: me.id, name: me.displayName, avatar: me.avatar, guest: true },
            };
            const state = data[this.screenValue];
            this.element.innerHTML = 'room' === this.kindValue
                ? roomScreen(state, ctx, { previousOrder: [] })
                : phoneScreen(state, ctx, { sent: false, streak: 3, score: me.score });
            paintFaces(this.element);
        });
    }
}
