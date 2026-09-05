import { Controller } from '@hotwired/stimulus';

/**
 * The period selector of the cahier de texte: a month calendar where a whole WEEK is what gets
 * picked, never a single day.
 *
 * The month grid is drawn here rather than server-side because walking the months is precisely the
 * one gesture that must NOT change the period - "la navigation mensuelle ne change pas la période
 * tant qu'aucune semaine n'est cliquée". A server-rendered grid would have to reload to show
 * October, and reloading is what choosing a week means.
 *
 * Everything that does change the period is a real link: the ‹ › on either side of the label, the
 * three shortcuts under the calendar (rendered in the template, so their wording stays translated),
 * and each week row below. They all carry the current address forward, which is what keeps the
 * selected séance and the grouping across the jump.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['pop', 'month', 'weeks'];
    static values = {
        // Monday of the period on display, and today - both as `Y-m-d`, the shape the URL uses.
        week: String,
        today: String,
        locale: { type: String, default: 'fr' },
    };

    connect() {
        this.month = this.monthOfWeek(this.parse(this.weekValue));
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.close();
            }
        };
    }

    disconnect() {
        this.close();
    }

    toggle() {
        return this.popTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.render();
        this.popTarget.hidden = false;
        this.element.classList.add('is-open');
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('keydown', this.onKeydown);
    }

    close() {
        this.popTarget.hidden = true;
        this.element.classList.remove('is-open');
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('keydown', this.onKeydown);
    }

    previousMonth() {
        this.month = new Date(this.month.getFullYear(), this.month.getMonth() - 1, 1);
        this.render();
    }

    nextMonth() {
        this.month = new Date(this.month.getFullYear(), this.month.getMonth() + 1, 1);
        this.render();
    }

    render() {
        this.monthTarget.textContent = this.month.toLocaleDateString(this.localeValue, {
            month: 'long',
            year: 'numeric',
        });

        const selected = this.key(this.monday(this.parse(this.weekValue)));
        const last = new Date(this.month.getFullYear(), this.month.getMonth() + 1, 0);

        this.weeksTarget.replaceChildren();
        for (let cursor = this.monday(this.month); cursor <= last; cursor = this.addDays(cursor, 7)) {
            this.weeksTarget.append(this.weekRow(cursor, selected));
        }
    }

    weekRow(monday, selected) {
        const row = document.createElement('a');
        row.className = 'cm-cdt-pop__week';
        row.href = this.urlForWeek(this.key(monday));
        if (this.key(monday) === selected) {
            row.classList.add('is-current');
        }

        for (let index = 0; index < 7; index += 1) {
            const day = this.addDays(monday, index);
            const cell = document.createElement('span');
            cell.className = 'cm-cdt-pop__day';
            if (day.getMonth() !== this.month.getMonth()) {
                cell.classList.add('cm-cdt-pop__day--outside');
            }
            if (index >= 5) {
                cell.classList.add('cm-cdt-pop__day--weekend');
            }
            if (this.key(day) === this.todayValue) {
                cell.classList.add('is-today');
            }
            cell.textContent = String(day.getDate());
            row.append(cell);
        }

        return row;
    }

    /**
     * The same address with another week. `date` is dropped on the way out: it was the instruction
     * that opened the screen on one day, and carrying it into another week would drag the period
     * straight back.
     */
    urlForWeek(week) {
        const url = new URL(window.location.href);
        url.searchParams.set('week', week);
        url.searchParams.delete('date');

        return url.toString();
    }

    // --- Plain calendar arithmetic, all in the browser's own local time ---

    parse(value) {
        const [year, month, day] = value.split('-').map(Number);

        return new Date(year, month - 1, day);
    }

    key(date) {
        // Never toISOString(): it converts to UTC first, which moves the day west of Greenwich.
        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0'),
        ].join('-');
    }

    addDays(date, days) {
        return new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
    }

    /**
     * The month a week belongs to: the one its Thursday falls in, which is ISO 8601's own answer.
     * A week straddling two months would otherwise open on whichever the Monday happened to be in -
     * so the week of 31 August would show August with six of its days greyed out as « next month ».
     */
    monthOfWeek(date) {
        const thursday = this.addDays(this.monday(date), 3);

        return new Date(thursday.getFullYear(), thursday.getMonth(), 1);
    }

    monday(date) {
        // getDay() is 0 on Sunday, and the week starts on Monday here.
        const shift = (date.getDay() + 6) % 7;

        return this.addDays(date, -shift);
    }
}
