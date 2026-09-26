import { Controller } from '@hotwired/stimulus';

// The questions reserved for each quiz of the pool - the same arithmetic as
// App\Service\QuizPoolShares::quotas(), mirrored for the preview only: the launch recomputes it
// and freezes the result on the instance. null where a quiz has no share.
function resolveQuotas(shares, questionCount) {
    const given = shares.filter((share) => share !== null);
    if (shares.length < 2 || given.length === 0) {
        return shares.map(() => null);
    }

    const count = Math.max(0, questionCount);
    const sum = given.reduce((total, share) => total + share, 0);
    const target = given.length === shares.length || sum >= 100 ? count : Math.floor((sum * count + 50) / 100);

    const quotas = shares.map((share) => (share === null ? null : Math.floor((share * count) / 100)));
    const order = shares
        .map((share, index) => ({ index, remainder: share === null ? -1 : (share * count) % 100 }))
        .filter((entry) => entry.remainder >= 0)
        .sort((a, b) => b.remainder - a.remainder || a.index - b.index);

    let missing = target - quotas.reduce((total, quota) => total + (quota ?? 0), 0);
    for (const entry of order) {
        if (missing <= 0) {
            break;
        }
        quotas[entry.index] += 1;
        missing -= 1;
    }

    return quotas;
}

/*
 * The "rajouter un quiz" rows shared by the two launch screens - Bibliothèque > Lancer (1c) and
 * Outils > Concours live > Nouveau. Both merge several QuizTemplates into one question pool
 * (App\Service\QuizInstantiationService), so both need the same three things: add a row, drop a
 * row, and keep a running total of the pool size.
 *
 * The total is announced with a `quiz-pool:change` event rather than written into the page here:
 * screen 1c's own controller (quiz_launch_controller.js) owns the draw recap and needs the number,
 * while the live screen just prints it. Whoever listens decides what it means.
 *
 * Screen 1c has a fixed base quiz (the page's own) and passes its size as `baseCount`; the live
 * screen picks its base from a select, which is simply one more `select` target and leaves
 * `baseCount` at 0. Nothing else differs between the two - except « Part du quiz », which only the
 * launch screen renders: each quiz of the pool may be given a share of the draw, and
 * refreshShares() previews what it amounts to.
 */
export default class extends Controller {
    static targets = ['rows', 'select', 'total', 'addButton', 'share', 'shareStatus', 'questionCount', 'serverErrors'];

    static values = {
        // templateId -> { questions, defaultCount }: the quiz's bank size, and the number of
        // questions it draws by default (screen 1n). Summing the second is what lets the launch
        // screen widen its draw when quizzes are merged - see quiz_launch_controller.js.
        counts: Object,
        baseCount: { type: Number, default: 0 },
        baseDefaultCount: { type: Number, default: 0 },
        prototype: String,
        // Rows are cheap but a merge of every quiz in a library is not what this is for; it also
        // keeps the <select> count bounded on a page that renders every option in every row.
        max: { type: Number, default: 20 },
        // « Part du quiz » (launch screen only): the wording of the per-row count and of the status
        // line under the pool. The live screen has no share at all and passes nothing.
        shareLabels: Object,
    };

    connect() {
        this.update();
    }

    add(event) {
        event.preventDefault();

        if (this.rowCount >= this.maxValue) {
            return;
        }

        // Symfony's collection prototype names its placeholder __quiz__ (App\Form\QuizLaunchType);
        // the live screen has no Symfony form at all and passes plain markup with no placeholder,
        // so the replace is a no-op there rather than a special case.
        const index = this.rowsTarget.querySelectorAll('[data-quiz-pool-row]').length;
        const markup = this.prototypeValue.replace(/__quiz__/g, String(index));

        const holder = document.createElement('div');
        holder.innerHTML = markup.trim();
        const row = holder.firstElementChild;
        if (row === null) {
            return;
        }

        this.rowsTarget.appendChild(row);
        this.update(event);

        // The button where the launch screen has one - its select is behind the picker modal and
        // cannot take focus (library_picker_controller.js); the live screen still has a plain
        // select, and focusing it is what opens its list.
        const focusable = row.querySelector('.cm-pickfield__button') ?? row.querySelector('select');
        if (focusable !== null) {
            focusable.focus();
        }
    }

    remove(event) {
        event.preventDefault();

        const row = event.currentTarget.closest('[data-quiz-pool-row]');
        if (row !== null) {
            row.remove();
        }

        this.update(event);
    }

    // Named `update` rather than bound to a specific event: a row's own select fires it on change,
    // add()/remove() call it directly, and connect() uses it for the initial render.
    update(event) {
        this.dropServerErrors(event);

        // Object values re-parse on every access - read once (see CLAUDE.md's Stimulus gotcha).
        const counts = this.countsValue;

        let total = this.baseCountValue;
        let defaultTotal = this.baseDefaultCountValue;
        this.selectTargets.forEach((select) => {
            const entry = counts[select.value];
            if (entry !== undefined) {
                total += entry.questions;
                defaultTotal += entry.defaultCount;
            }
        });

        if (this.hasTotalTarget) {
            this.totalTarget.textContent = String(total);
        }

        if (this.hasAddButtonTarget) {
            this.addButtonTarget.disabled = this.rowCount >= this.maxValue;
        }

        this.dispatch('change', { detail: { total, defaultTotal } });

        // After the dispatch, not before: the launch screen's listener may just have moved the
        // number of questions drawn, which every share is a percentage of.
        this.refreshShares();
    }

    // « Part du quiz ». Shown once the pool holds two quizzes - with one, a share would be the
    // whole draw - and each share is followed by the questions it amounts to. The status line
    // says what the quizzes without a share are left with, or which rule the shares break; the
    // server checks the same rules on submit (App\Service\QuizPoolShares::violation()).
    refreshShares(event) {
        this.dropServerErrors(event);

        if (!this.hasShareTarget) {
            return;
        }

        const counts = this.countsValue;
        const labels = this.shareLabelsValue;
        const entries = this.shareTargets.map((input) => {
            const entry = input.closest('[data-quiz-pool-entry]');
            const select = entry?.querySelector('select') ?? null;
            const fixed = entry?.dataset.questions;
            const chosen = fixed !== undefined || (select !== null && select.value !== '');
            const option = select !== null && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;

            return {
                input,
                wrapper: input.closest('[data-quiz-pool-share]'),
                hint: entry?.querySelector('[data-quiz-pool-share-hint]') ?? null,
                chosen,
                name: fixed !== undefined ? (entry.querySelector('.cm-quizpool__name')?.textContent ?? '') : (option?.textContent ?? ''),
                available: fixed !== undefined ? Number(fixed) : (counts[select?.value]?.questions ?? 0),
            };
        });

        const split = entries.length >= 2;
        entries.forEach((entry) => {
            entry.wrapper?.classList.toggle('d-none', !split);
            // Cleared rather than kept out of sight: a hidden field the browser refuses (a share
            // above 100) would block the submit without showing anything.
            if (!split) {
                entry.input.value = '';
            }
        });

        // Only the quizzes actually picked take part - a row still on its placeholder is not in
        // the pool, and the server skips it the same way.
        const pooled = entries.filter((entry) => entry.chosen);
        const shares = pooled.map((entry) => {
            const value = parseInt(entry.input.value, 10);
            return Number.isFinite(value) && value > 0 ? value : null;
        });
        const questionCount = this.hasQuestionCountTarget ? Math.max(0, parseInt(this.questionCountTarget.value, 10) || 0) : 0;
        const quotas = resolveQuotas(shares, questionCount);

        entries.forEach((entry) => {
            if (entry.hint !== null) {
                entry.hint.textContent = '';
            }
            entry.input.classList.remove('is-over');
        });

        let problem = null;
        let rest = questionCount;
        let restAvailable = 0;
        pooled.forEach((entry, index) => {
            const quota = quotas[index];
            if (quota === null) {
                restAvailable += entry.available;
                return;
            }
            rest -= quota;
            if (entry.hint !== null) {
                entry.hint.textContent = (labels.quota ?? '').replace('%count%', String(quota));
            }
            if (quota > entry.available) {
                entry.input.classList.add('is-over');
                problem ??= (labels.tooLarge ?? '')
                    .replace('%name%', entry.name.trim())
                    .replace('%available%', String(entry.available))
                    .replace('%share%', String(shares[index]))
                    .replace('%quota%', String(quota));
            }
        });

        const given = shares.filter((share) => share !== null);
        const sum = given.reduce((total, share) => total + share, 0);
        if (given.length > 0 && pooled.length >= 2) {
            if (sum > 100) {
                problem = (labels.overflow ?? '').replace('%sum%', String(sum));
            } else if (given.length === pooled.length && sum < 100) {
                problem = (labels.incomplete ?? '').replace('%sum%', String(sum));
            } else if (problem === null && given.length < pooled.length && rest > restAvailable) {
                problem = (labels.restTooLarge ?? '').replace('%rest%', String(rest)).replace('%available%', String(restAvailable));
            }
        }

        if (!this.hasShareStatusTarget) {
            return;
        }

        const status = this.shareStatusTarget;
        if (this.hasServerErrorsTarget) {
            // The server already said it, in its own words, for the pool as it was sent.
            status.hidden = true;
        } else if (problem !== null) {
            status.textContent = problem;
            status.className = 'small text-danger';
            status.hidden = false;
        } else if (given.length > 0 && given.length < pooled.length) {
            status.textContent = (labels.rest ?? '').replace('%rest%', String(Math.max(0, rest)));
            status.className = 'small text-secondary';
            status.hidden = false;
        } else {
            status.hidden = true;
        }
    }

    // A form sent back with an error: its message describes the pool as it was sent, and is
    // withdrawn at the first gesture on it - the live status line takes over from there.
    dropServerErrors(event) {
        if (event !== undefined && this.hasServerErrorsTarget) {
            this.serverErrorsTarget.remove();
        }
    }

    get rowCount() {
        return this.hasRowsTarget ? this.rowsTarget.querySelectorAll('[data-quiz-pool-row]').length : 0;
    }
}
