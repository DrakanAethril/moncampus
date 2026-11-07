/**
 * The live contest's screens, drawn from the server's payloads (App\Service\QuizLiveSessionService)
 * - the room's screen and the player's phone (design/validated/quiz-live-public.md). Pure functions
 * returning markup: the projector and player controllers swap it in when the phase changes, and the
 * theme gallery (quiz_live_capture_controller.js) draws the very same screens from a sample contest,
 * which is how a new theme gets its captures without anybody taking a picture.
 *
 * Everything a person wrote - a statement, a proposition, a pseudonym - goes through esc(): a guest
 * types their own name, and the room's screen runs with a teacher's session.
 *
 * The look is the theme's (assets/styles/quiz_live/themes/<slug>.css); the words it writes are the
 * theme's too (ctx.words, config/quiz_live/themes.yaml), the rest are the platform's (ctx.labels).
 */
import { loadAvatarModule } from '../serious_game/avatar_module.js';

const SHAPE_PATHS = {
    triangle: '<path d="M12 3.2 21.6 20H2.4z"/>',
    square: '<rect x="3.4" y="3.4" width="17.2" height="17.2" rx="1.6"/>',
    circle: '<circle cx="12" cy="12" r="9.6"/>',
    diamond: '<path d="M12 1.8 22.2 12 12 22.2 1.8 12z"/>',
    pentagon: '<path d="M12 2.4 21.6 9.4 17.9 20.8H6.1L2.4 9.4z"/>',
    'triangle-down': '<path d="M12 20.8 2.4 4h19.2z"/>',
};
const SHAPE_ORDER = ['triangle', 'square', 'circle', 'diamond', 'pentagon', 'triangle-down'];

const CHECK = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const CROSS = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round"/></svg>';
const FLAME = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 2c1 4 5 5.5 5 11a5 5 0 0 1-10 0c0-2.6 1.4-4 2.4-5.2.2 1.8 1 2.8 2.1 3.2C11 8.6 11.3 5 12 2z" fill="currentColor"/></svg>';

export function esc(text) {
    return String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

export function fmt(value) {
    return Math.round(Number(value) || 0).toLocaleString('fr-FR');
}

function ordinal(rank) {
    return 1 === rank ? '1er' : `${rank}e`;
}

function fill(template, values) {
    return Object.entries(values).reduce((text, [key, value]) => text.replaceAll(key, String(value)), template || '');
}

export function shape(name, size) {
    return `<svg class="ql-shp" viewBox="0 0 24 24" width="${size}" height="${size}" aria-hidden="true">${SHAPE_PATHS[name] || SHAPE_PATHS.circle}</svg>`;
}

function colorOf(position) {
    return `var(--c${(position % 6) + 1})`;
}

/** A face: the photo, else the drawn avatar (painted by paintFaces()), else the initial. */
export function face(avatar, name, size, extra = '') {
    const initial = Array.from(String(name || '').trim())[0]?.toUpperCase() || '?';
    const data = avatar && (avatar.photo || avatar.drawn) ? ` data-face="${esc(JSON.stringify(avatar))}"` : '';

    return `<span class="ql-av ${extra}" style="width:${size}px;height:${size}px"${data}>${esc(initial)}</span>`;
}

/** Paints the faces of a fragment just swapped in. */
export function paintFaces(root) {
    root.querySelectorAll('[data-face]').forEach((element) => {
        let avatar;
        try {
            avatar = JSON.parse(element.dataset.face);
        } catch {
            return;
        }
        element.removeAttribute('data-face');
        if (avatar.photo) {
            element.textContent = '';
            element.style.backgroundImage = `url(${JSON.stringify(avatar.photo)})`;
            element.classList.add('is-photo');
        } else if (avatar.drawn) {
            loadAvatarModule().then((module) => {
                element.innerHTML = module.svg(module.normalize(avatar.drawn), { size: 100 });
                element.classList.add('is-drawn');
            }).catch(() => {});
        }
    });
}

function figure(state, where) {
    return state.imageUrl ? `<figure class="ql-img ql-img--${where}"><img src="${esc(state.imageUrl)}" alt=""></figure>` : '';
}

function scr(inner, deco = '') {
    return `<div class="ql-deco ${deco}" aria-hidden="true"></div><div class="ql-scr">${inner}</div>`;
}

function kindHint(state, labels) {
    const kinds = labels.kinds || {};
    if (state.isVote) {
        return kinds.vote || '';
    }
    if ('number' === state.kind && state.range) {
        return fill(kinds.slider || '', { '%min%': fmt(state.range.min), '%max%': fmt(state.range.max) });
    }

    return kinds[state.kind] || '';
}

/* ================================================================== the room's screen (1280×720) */

function roomHead(state, ctx, action = '') {
    const counter = undefined !== state.questionIndex && null !== state.questionIndex
        ? `<span class="ql-qn">${esc(ctx.labels.question)} ${state.questionIndex + 1} / ${state.totalQuestions}</span>` : '';
    const where = ctx.public ? `<span class="ql-pill ql-code">${esc(ctx.joinLabel)} · <b>${esc(ctx.code)}</b></span>` : (ctx.className ? `<span class="ql-pill ql-code">${esc(ctx.className)}</span>` : '');

    return `<div class="ql-sh"><span class="ql-pill">${esc(ctx.quizName)}</span>${counter}${where}${action}</div>`;
}

function hostButton(label, alt = false) {
    return `<button type="button" class="ql-btn ql-btn--inline${alt ? ' ql-btn--alt' : ''}" data-action="quiz-live-host#advance">${esc(label)}</button>`;
}

export function roomChip(participant) {
    return `<button type="button" class="ql-pchip ql-pop" data-id="${participant.id}" data-action="quiz-live-host#remove">${face(participant.avatar, participant.displayName, 46)}<span>${esc(participant.displayName)}</span></button>`;
}

export function roomLobby(state, ctx) {
    const left = ctx.public
        ? `<div class="ql-panel ql-qrcard"><div class="ql-qr">${ctx.qrSvg || ''}</div><div class="ql-url">${esc(ctx.joinLabel)}</div><div class="ql-pin fd">${esc(ctx.code)}</div><span class="ql-muted ql-b">${esc(ctx.labels.scanHint)}</span></div>`
        : `<div class="ql-panel ql-qrcard ql-qrcard--class"><h3 class="fd">${esc(ctx.labels.classJoinTitle)}</h3><p class="ql-muted">${esc(ctx.labels.classJoinHint)}</p></div>`;
    const chips = (state.participants || []).map(roomChip).join('');

    return `<div class="ql-marquee" aria-hidden="true"></div>${scr(`${roomHead({}, ctx)}<div class="ql-lobby">${left}<div class="ql-col ql-gap14 ql-min0">`
        + `<div class="ql-row ql-gap20"><h2 class="fd ql-title-xl ql-hero ql-glow">${esc(ctx.words.lobby)}</h2><span class="fd ql-count" data-bind="count">${state.participantCount || 0}</span></div>`
        + `<div class="ql-players ql-grow" data-players>${chips}</div>`
        + `<div class="ql-row ql-gap14"><span class="ql-muted ql-b ql-grow">${esc(ctx.labels.removeHint)}</span>${ctx.cancelUrl ? `<form method="post" action="${esc(ctx.cancelUrl)}" data-turbo="false"><input type="hidden" name="_token" value="${esc(ctx.cancelToken)}"><button type="submit" class="ql-btn ql-btn--alt ql-btn--inline">${esc(ctx.labels.cancel)}</button></form>` : ''}<button type="button" class="ql-btn ql-btn--inline" data-action="quiz-live-host#advance">${esc(ctx.labels.start)}</button></div>`
        + '</div></div>')}`;
}

export function roomCountdown(ctx) {
    return scr(`<div class="ql-grow ql-center ql-gap20"><p class="fd ql-title-xl">${esc(ctx.words.ready)}</p><div data-cd class="ql-cd-host"></div></div>`, 'is-speed');
}

export function roomReading(state, ctx) {
    return scr(`${roomHead(state, ctx, hostButton(ctx.labels.showAnswers))}<div class="ql-grow ql-center ql-gap20 ql-reading"><span class="ql-pill">${esc(kindHint(state, ctx.labels))}</span><h2 class="ql-stmt ql-hero" style="font-size:${state.imageUrl ? 42 : 54}px">${esc(state.label)}</h2>${figure(state, 'read')}</div>`);
}

function roomTiles(state, mark) {
    const answers = state.answers || [];
    const cols = answers.length > 4 ? ' is-three' : '';

    return `<div class="ql-stiles${cols}">${answers.map((answer) => {
        const cls = mark ? (answer.correct ? ' is-win' : ' is-dim') : '';
        const tick = mark ? (answer.correct ? CHECK : CROSS) : '';

        return `<div class="ql-tile${cls}" style="--c:${colorOf(answer.shapeIndex)}">${shape(answer.shape, 40)}<span class="ql-t">${esc(answer.label)}</span>${tick}</div>`;
    }).join('')}</div>`;
}

export function roomQuestion(state, ctx) {
    let center = '';
    if (state.imageUrl) {
        center = figure(state, 'screen');
    } else if ('number' === state.kind && state.range) {
        center = `<div class="ql-ruler"><div class="ql-axis"></div><span class="ql-tick" style="left:0">${fmt(state.range.min)}</span><span class="ql-tick" style="left:100%">${fmt(state.range.max)}</span><span class="fd ql-ruler-hint">${esc(kindHint(state, ctx.labels))}</span></div>`;
    } else {
        center = `<p class="fd ql-kindhint">${esc(kindHint(state, ctx.labels))}</p>`;
    }
    const timed = null !== state.secondsPerQuestion && undefined !== state.secondsPerQuestion;
    const clock = `<div class="ql-clock"><svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52" fill="var(--panel)" stroke="var(--track)" stroke-width="12"/><circle cx="60" cy="60" r="52" fill="none" stroke="var(--accent)" stroke-width="12" stroke-linecap="round" stroke-dasharray="326.7" data-bind="clock"/></svg><span class="fd ql-clock-n" data-bind="clockn">${timed ? state.secondsPerQuestion : '∞'}</span></div>`;
    const tiles = ['choice', 'multi', 'order'].includes(state.kind) || state.isVote ? roomTiles(state, false) : '';

    return scr(`${roomHead(state, ctx, hostButton(ctx.labels.skip, true))}<div class="ql-sq"><h2 class="ql-stmt ql-panel">${esc(state.label)}</h2>`
        + `<div class="ql-mid">${clock}<div class="ql-center">${center}</div><div class="ql-answers-n"><b class="fd" data-bind="answered">${state.answeredCount ?? 0}</b><span>${esc(ctx.labels.answers)}</span></div></div>${tiles}</div>`);
}

export function roomResults(state, ctx) {
    const next = state.isLastQuestion ? ctx.labels.podium : (state.isVote ? ctx.labels.next : ctx.labels.scoreboard);
    let mid;
    if ('order' === state.kind) {
        mid = `<div class="ql-grow ql-center ql-gap14"><div class="ql-col ql-gap10 ql-orderlist">${(state.rows || []).map((row, i) => `<div class="ql-tile ql-up" style="--c:${colorOf(row.shapeIndex)};animation-delay:${i * 0.25}s"><span class="fd ql-rank">${row.rank}</span>${shape(row.shape, 32)}<span class="ql-t">${esc(row.label)}</span></div>`).join('')}</div>`
            + `<p class="ql-stmt ql-small">${esc(fill(ctx.labels.correctCount, { '%correct%': state.correctCount, '%answered%': state.participantCount }))}</p></div>`;
    } else if ('text' === state.kind) {
        mid = `<div class="ql-grow ql-center ql-gap20"><div class="fd ql-hero ql-glow ql-expected">${esc(state.expected || '')}</div><div class="ql-row ql-gap14 ql-wrap ql-jc">${(state.rows || []).slice(0, 6).map((row, i) => `<span class="ql-pill ql-up ql-big" style="animation-delay:${0.4 + i * 0.12}s">${row.correct ? CHECK : CROSS}${esc(row.label)} · ${row.count}</span>`).join('')}</div></div>`;
    } else {
        const rows = state.rows || [];
        const max = Math.max(1, ...rows.map((row) => row.count));
        const vote = !!state.isVote;
        const lead = vote ? rows.reduce((best, row, i) => (row.count > rows[best].count ? i : best), 0) : -1;
        mid = `<div class="ql-grow"><div class="ql-bars">${rows.map((row, i) => {
            const good = vote ? i === lead : row.correct;
            const colour = undefined !== row.shapeIndex ? colorOf(row.shapeIndex) : (row.correct ? 'var(--ok)' : 'var(--muted)');
            const icon = row.shape ? shape(row.shape, 30) : `<span class="ql-barlabel">${esc(row.label)}</span>`;

            return `<div class="ql-bar${good ? '' : ' is-dim'}" style="--c:${colour}"><b class="fd">${row.count}</b><i style="height:${Math.max(6, (row.count / max) * 190)}px;animation-delay:${i * 0.08}s"></i>${icon}</div>`;
        }).join('')}</div>${'number' === state.kind && state.expected ? `<p class="fd ql-expected-small ql-hero">${esc(ctx.labels.expected)} ${esc(state.expected)}</p>` : ''}</div>`;
        if (['choice', 'multi'].includes(state.kind) || vote) {
            mid += roomTiles({ answers: rows }, !vote);
        }
    }

    return scr(`${roomHead(state, ctx, hostButton(next))}<div class="ql-sq"><h2 class="ql-stmt ql-panel ql-small">${esc(state.label)}</h2>${mid}</div>`, 'manga' === ctx.slug ? 'is-speed' : '');
}

/** The top five, each row sliding from where it stood before this question. */
export function roomScoreboard(state, ctx, previousOrder) {
    const rows = (state.top || []).map((row, i) => {
        const before = previousOrder.indexOf(row.participantId);
        const from = (before < 0 ? 5 : Math.min(before, 5)) - i;

        return `<div class="ql-brow ql-panel" style="--from:${from * 84}px;animation-delay:${0.15 + i * 0.05}s"><span class="fd ql-rk">${row.rank}</span>${face(row.avatar, row.displayName, 54)}<span class="ql-nm">${esc(row.displayName)}</span><span class="fd ql-pts">${fmt(row.score)}</span></div>`;
    }).join('');

    return scr(`${roomHead(state, ctx, hostButton(ctx.labels.next))}<div class="ql-grow ql-col ql-gap20 ql-boardwrap"><h2 class="fd ql-title-xl ql-hero ql-glow ql-tc">${esc(ctx.labels.ranking)}</h2><div class="ql-board">${rows}</div></div>`);
}

export function roomPodium(state, ctx) {
    const top = state.podium || [];
    const slots = [[1, 220, 1.4, 'var(--c2)'], [0, 300, 2.6, 'var(--c3)'], [2, 160, 0.4, 'var(--c1)']];
    const pods = slots.map(([k, height, delay, colour]) => {
        const row = top[k];
        if (!row) {
            return '<div class="ql-pod"></div>';
        }

        return `<div class="ql-pod"><div class="ql-who" style="animation-delay:${delay}s">${face(row.avatar, row.displayName, 110)}<b>${esc(row.displayName)}</b><span class="fd">${fmt(row.score)}</span></div><div class="ql-plinth fd" style="--c:${colour};height:${height}px;animation-delay:${delay - 0.3}s">${k + 1}</div></div>`;
    }).join('');
    const results = ctx.resultsUrl ? `<a class="ql-btn ql-btn--inline ql-btn--alt" href="${esc(ctx.resultsUrl)}" data-turbo="false">${esc(ctx.labels.results)}</a>` : '';

    return scr(`${roomHead({}, ctx, results)}<h2 class="fd ql-title-xl ql-hero ql-glow ql-tc">${esc(ctx.labels.podiumTitle)}</h2><div class="ql-podium">${pods}</div>`, 'is-speed');
}

export function roomScreen(state, ctx, memory = {}) {
    switch (state.type) {
        case 'participant-joined': return roomLobby(state, ctx);
        case 'countdown-started': return roomCountdown(ctx);
        case 'question-reading': return roomReading(state, ctx);
        case 'question-opened': return roomQuestion(state, ctx);
        case 'results': return roomResults(state, ctx);
        case 'scoreboard': return roomScoreboard(state, ctx, memory.previousOrder || []);
        case 'session-finished': return roomPodium(state, ctx);
        default: return scr('');
    }
}

/* ================================================================== the player's phone */

function phoneHead(state, ctx, me) {
    const counter = undefined !== state.questionIndex && null !== state.questionIndex ? `<span class="ql-pill">${state.questionIndex + 1} / ${state.totalQuestions}</span>` : '';
    const score = undefined !== me.score ? `<span class="ql-pill fd" data-bind="myscore">${fmt(me.score)}</span>` : '';

    return `<div class="ql-phead">${face(ctx.me.avatar, ctx.me.name, 38)}<span class="ql-who">${esc(ctx.me.name)}</span>${counter}${score}</div>`;
}

function mine(state, ctx) {
    const rows = state.leaderboard || state.finalLeaderboard || [];

    return rows.find((row) => row.participantId === ctx.me.participantId) || null;
}

export function phoneLobby(state, ctx) {
    const note = ctx.me.guest
        ? `<span class="ql-pill">${esc(ctx.labels.guestNote)}</span>`
        : `<span class="ql-pill">${esc(ctx.labels.accountNote)}</span>`;

    return scr(`${phoneHead(state, ctx, {})}<div class="ql-grow ql-center ql-pad ql-gap14"><div class="ql-bob">${face(ctx.me.avatar, ctx.me.name, 150, 'ql-bigav')}</div><h2 class="fd ql-title-xl ql-hero ql-glow">${esc(ctx.me.name)}</h2><p class="ql-stmt ql-s19">${esc(ctx.labels.inTheRoom)}</p><p class="ql-muted ql-m0">${esc(ctx.labels.lookAtBoard)}<br><span data-bind="count">${state.participantCount ?? ''}</span> ${esc(ctx.labels.playersInRoom)}</p>${note}</div>`);
}

export function phoneCountdown(ctx) {
    return scr(`<div class="ql-grow ql-center ql-pad ql-gap14"><p class="fd ql-title-l">${esc(ctx.words.ready)}</p><div data-cd class="ql-cd-phone"></div></div>`, 'is-speed');
}

export function phoneReading(state, ctx, me) {
    return scr(`${phoneHead(state, ctx, me)}<div class="ql-grow ql-col ql-pad ql-gap14 ql-jcenter"><span class="ql-pill ql-self-start">${esc(kindHint(state, ctx.labels))}</span><h2 class="ql-stmt ql-hero ql-s26">${esc(state.label)}</h2>${figure(state, 'phone')}<p class="ql-muted ql-m0">${esc(ctx.labels.answersComing)}</p></div>`);
}

function phoneAnswerUi(state, ctx) {
    const answers = state.answers || [];
    switch (state.isVote ? 'choice' : state.kind) {
        case 'choice': {
            const big = state.imageUrl || answers.length > 4 ? '' : ' is-big';
            const tf = 2 === answers.length && !state.imageUrl ? ' is-tf' : '';

            return `<div class="ql-tiles${big}${tf}">${answers.map((answer, i) => `<button type="button" class="ql-tile ql-pop" style="--c:${colorOf(answer.shapeIndex)};animation-delay:${i * 60}ms" data-answer-id="${answer.answerId}" data-action="quiz-live-play#tap">${shape(answer.shape, 30)}<span class="ql-t">${esc(answer.label)}</span></button>`).join('')}</div>`;
        }
        case 'multi':
            return `<p class="ql-muted ql-b ql-m0">${esc(ctx.labels.tickAll)}</p><div class="ql-tiles is-multi">${answers.map((answer, i) => `<button type="button" class="ql-tile ql-pop" style="--c:${colorOf(answer.shapeIndex)};animation-delay:${i * 50}ms" data-answer-id="${answer.answerId}" data-action="quiz-live-play#tap" aria-pressed="false">${shape(answer.shape, 22)}<span class="ql-t">${esc(answer.label)}</span><span class="ql-badge">${CHECK}</span></button>`).join('')}</div>${validateBar(ctx, false)}`;
        case 'order':
            return `<p class="ql-muted ql-b ql-m0">${esc(ctx.labels.tapInOrder)}</p><div class="ql-ordlist">${answers.map((answer, i) => `<button type="button" class="ql-tile ql-pop" style="--c:${colorOf(answer.shapeIndex)};animation-delay:${i * 60}ms" data-answer-id="${answer.answerId}" data-action="quiz-live-play#tap">${shape(answer.shape, 26)}<span class="ql-t">${esc(answer.label)}</span><span class="ql-badge"></span></button>`).join('')}</div>${validateBar(ctx, true)}`;
        case 'number': {
            if (state.range) {
                const { min, max, step } = state.range;
                const middle = min + Math.round((max - min) / 2 / step) * step;

                return `<div class="ql-grow ql-center ql-gap14"><div class="ql-numbig fd ql-hero" data-bind="numout" aria-live="polite">${fmt(middle)}${state.unit ? ` ${esc(state.unit)}` : ''}</div><input type="range" class="ql-range" data-number min="${min}" max="${max}" step="${step}" value="${middle}" data-action="input->quiz-live-play#slide" aria-label="${esc(ctx.labels.numberField)}">`
                    + `<div class="ql-row ql-gap10 ql-w100"><button type="button" class="ql-btn ql-btn--alt ql-step" data-delta="-1" data-action="quiz-live-play#nudge" aria-label="−">−</button><div class="ql-row ql-grow ql-jsb ql-b"><span>${fmt(min)}</span><span>${fmt(max)}</span></div><button type="button" class="ql-btn ql-btn--alt ql-step" data-delta="1" data-action="quiz-live-play#nudge" aria-label="+">+</button></div></div>${validateBar(ctx, false, true)}`;
            }

            return `<div class="ql-field"><label class="ql-b" for="ql-number">${esc(ctx.labels.numberField)}</label><input id="ql-number" type="number" inputmode="decimal" step="any" class="ql-input" data-number data-action="keydown.enter->quiz-live-play#validate"></div><div class="ql-grow"></div>${validateBar(ctx, false, true)}`;
        }
        case 'text':
            return `<div class="ql-field"><label class="ql-b" for="ql-text">${esc(ctx.labels.textField)}</label><input id="ql-text" type="text" class="ql-input" maxlength="200" autocomplete="off" autocapitalize="off" spellcheck="false" data-text data-action="input->quiz-live-play#typed keydown.enter->quiz-live-play#validate"></div><p class="ql-muted ql-m0">${esc(ctx.labels.textHint)}</p><div class="ql-grow"></div>${validateBar(ctx, false)}`;
        default:
            return '';
    }
}

function validateBar(ctx, withClear, enabled = false) {
    const clear = withClear ? `<button type="button" class="ql-btn ql-btn--alt ql-clear" data-action="quiz-live-play#clearOrder">${esc(ctx.labels.clear)}</button>` : '';

    return `<div class="ql-grow"></div><div class="ql-row ql-gap10">${clear}<button type="button" class="ql-btn" data-validate data-action="quiz-live-play#validate"${enabled ? '' : ' disabled'}>${esc(ctx.labels.validate)}</button></div>`;
}

export function phoneQuestion(state, ctx, me) {
    return scr(`${phoneHead(state, ctx, me)}<div class="ql-col ql-qhead"><div class="ql-tbar"><i data-bind="time"></i></div><p class="ql-stmt ql-s18">${esc(state.label)}</p></div>`
        + `<div class="ql-grow ql-scroll ql-col ql-answerzone"><div class="ql-retry" data-retry hidden role="alert">${esc(ctx.labels.retry)}</div>${figure(state, 'phone')}${phoneAnswerUi(state, ctx)}</div>`);
}

export function phoneSent(state, ctx, me) {
    const position = (state.questionIndex || 0) % 6;

    return scr(`${phoneHead(state, ctx, me)}<div class="ql-grow ql-center ql-pad ql-gap20"><svg class="ql-wait ql-shp" viewBox="0 0 24 24" aria-hidden="true"><g style="fill:${colorOf(position)}">${SHAPE_PATHS[SHAPE_ORDER[position]]}</g></svg><h2 class="fd ql-title-l ql-hero">${esc(ctx.words.sent)}</h2><p class="ql-muted ql-m0">${esc(ctx.labels.waitingOthers)}</p><div class="ql-dots" aria-hidden="true"><i></i><i></i><i></i></div></div>`);
}

function rightAnswer(state) {
    const rows = state.rows || [];
    if ('order' === state.kind) {
        return rows.slice().sort((a, b) => a.rank - b.rank).map((row) => row.label).join(' › ');
    }
    if (['choice', 'multi'].includes(state.kind)) {
        return rows.filter((row) => row.correct).map((row) => row.label).join(', ');
    }

    return state.expected || '';
}

/**
 * This phone's verdict - the server's, under its participant id. `memory` holds what this page
 * remembers: the pick it sent (for a vote's share) and the run of right answers.
 */
export function phoneResults(state, ctx, memory) {
    const outcome = (state.outcomes || {})[String(ctx.me.participantId)] || { answered: false, correct: null, points: 0 };
    const row = mine(state, ctx);
    const me = { score: row ? row.score : 0 };
    const rank = row ? `${ordinal(row.rank)} ${esc(ctx.labels.outOf)} ${state.participantCount}` : '';

    if (state.isVote) {
        const total = (state.rows || []).reduce((sum, r) => sum + r.count, 0);
        const picked = (state.rows || []).find((r) => memory.pick && memory.pick.includes(r.answerId ?? -1));
        const share = picked && total ? Math.round((picked.count / total) * 100) : null;

        return `<div class="ql-res is-vote"><div class="ql-deco" aria-hidden="true"></div><div class="ql-scr">${phoneHead(state, ctx, me)}<div class="ql-grow ql-center ql-pad ql-gap14"><h2 class="fd ql-title-xl ql-hero ql-glow">${esc(outcome.answered ? ctx.words.voted : ctx.words.missed)}</h2>${null !== share ? `<p class="ql-stmt ql-s19">${esc(fill(ctx.labels.sameVote, { '%share%': share }))}</p>` : ''}<p class="ql-muted ql-m0">${esc(ctx.labels.voteNoPoints)}</p></div></div></div>`;
    }

    const cls = !outcome.answered ? 'is-none' : (outcome.correct ? 'is-ok' : 'is-ko');
    const word = !outcome.answered ? ctx.words.missed : (outcome.correct ? ctx.words.correct : ctx.words.wrong);
    const big = 'manga' === ctx.slug
        ? `<div class="ql-burst ql-hero"><h2 class="fd ql-title-xl">${esc(word)}</h2></div>`
        : `<h2 class="fd ql-title-xl ql-hero ql-s56">${esc(word)}</h2>`;
    const streak = outcome.correct && memory.streak > 1 ? `<span class="ql-pill ql-streak ql-pop">${FLAME}${esc(fill('neon' === ctx.slug ? ctx.labels.combo : ctx.labels.streak, { '%count%': memory.streak }))}</span>` : '';
    const points = outcome.correct ? `<div class="fd ql-ptscount" data-count="${outcome.points}">+${fmt(outcome.points)}</div>${streak}` : '';
    const right = outcome.correct ? '' : `<div class="ql-panel ql-rightbox"><span class="ql-kind">${esc(ctx.labels.rightAnswer)}</span><p class="ql-stmt ql-s20">${esc(rightAnswer(state))}</p></div>`;

    return `<div class="ql-res ${cls}"><div class="ql-deco${outcome.correct && 'manga' === ctx.slug ? ' is-speed' : ''}" aria-hidden="true"></div><div class="ql-scr">${phoneHead(state, ctx, me)}<div class="ql-grow ql-center ql-pad ql-gap14${outcome.answered && !outcome.correct ? ' ql-shake' : ''}">${big}${points}${right}<p class="ql-stmt ql-s17">${rank}</p></div></div></div>`;
}

export function phoneScoreboard(state, ctx, memory) {
    const row = mine(state, ctx);
    if (!row) {
        return scr(`${phoneHead(state, ctx, {})}<div class="ql-grow ql-center ql-pad"><p class="ql-stmt">${esc(ctx.labels.lookAtBoard)}</p></div>`);
    }
    const all = state.leaderboard || [];
    const ahead = row.rank > 1 ? all[row.rank - 2] : null;
    const before = memory.previousRank;
    let move = '';
    if (before && before !== row.rank) {
        move = `<span class="ql-pill ql-pop">${before > row.rank ? '▲' : '▼'} ${esc(fill(ctx.labels.places, { '%count%': Math.abs(before - row.rank) }))}</span>`;
    }
    const gap = ahead ? fill(ctx.labels.behind, { '%points%': fmt(ahead.score - row.score), '%name%': ahead.displayName }) : ctx.labels.leading;

    return scr(`${phoneHead(state, ctx, { score: row.score })}<div class="ql-grow ql-center ql-pad ql-gap14"><span class="ql-kind ql-muted">${esc(ctx.labels.ranking)}</span><div class="fd ql-hero ql-glow ql-rankbig">${ordinal(row.rank)}</div>${move}<p class="ql-stmt ql-s19">${esc(gap)}</p><p class="ql-muted ql-m0">${esc(fill(ctx.labels.outOfPlayers, { '%count%': all.length }))}</p></div>`);
}

const MEDAL = ['#F7C548', '#C9CED6', '#D98A4E'];

export function phoneFinished(state, ctx) {
    const row = mine(state, ctx);
    const rank = row ? row.rank : null;
    const medal = rank && rank <= 3
        ? `<svg class="ql-medal" viewBox="0 0 100 100" aria-hidden="true"><path d="M30 4h16l8 30H38zM70 4H54l-8 30h16z" fill="${1 === rank ? '#FF6B6B' : '#4D7CFE'}" stroke="#111" stroke-width="3"/><circle cx="50" cy="62" r="32" fill="${MEDAL[rank - 1]}" stroke="#111" stroke-width="4"/><text x="50" y="76" text-anchor="middle" font-size="38" font-family="Archivo Black, Arial Black, sans-serif" fill="#111">${rank}</text></svg>` : '';
    const message = ctx.me.guest ? fill(ctx.labels.guestThanks, { '%name%': ctx.me.name }) : ctx.labels.accountThanks;

    return scr(`${phoneHead({}, ctx, { score: row ? row.score : 0 })}<div class="ql-grow ql-center ql-pad ql-gap14"><div class="ql-dots ql-drumroll" aria-hidden="true"><i></i><i></i><i></i></div>`
        + `<div class="ql-center ql-gap14 ql-later">${medal}<div class="fd ql-glow ql-rankbig${medal ? ' is-small' : ''}">${rank ? ordinal(rank) : '—'}</div><p class="ql-stmt">${esc(fill(ctx.labels.points, { '%points%': fmt(row ? row.score : 0) }))}</p><p class="ql-muted ql-m0 ql-maxw">${esc(message)}</p>${ctx.homeUrl ? `<a class="ql-btn ql-btn--alt ql-btn--inline" href="${esc(ctx.homeUrl)}" data-turbo="false">${esc(ctx.labels.home)}</a>` : ''}</div></div>`);
}

export function phoneMessage(title, text, ctx, link = null) {
    return scr(`<div class="ql-grow ql-center ql-pad ql-gap14"><h2 class="fd ql-title-l ql-hero">${esc(title)}</h2><p class="ql-muted ql-maxw">${esc(text)}</p>${link ? `<a class="ql-btn ql-btn--inline" href="${esc(link.href)}" data-turbo="false">${esc(link.label)}</a>` : ''}</div>`);
}

export function phoneScreen(state, ctx, memory = {}) {
    const me = { score: memory.score };
    switch (state.type) {
        case 'lobby': return phoneLobby(state, ctx);
        case 'countdown-started': return phoneCountdown(ctx);
        case 'question-reading': return phoneReading(state, ctx, me);
        case 'question-opened': return memory.sent || true === state.answered ? phoneSent(state, ctx, me) : phoneQuestion(state, ctx, me);
        case 'results': return phoneResults(state, ctx, memory);
        case 'scoreboard': return phoneScoreboard(state, ctx, memory);
        case 'session-finished': return phoneFinished(state, ctx);
        case 'session-cancelled': return phoneMessage(ctx.labels.cancelledTitle, ctx.labels.cancelledText, ctx);
        default: return scr('');
    }
}
