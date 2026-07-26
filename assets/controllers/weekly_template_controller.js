import { Controller } from '@hotwired/stimulus';
import 'fullcalendar';
import { Calendar } from '@fullcalendar/core';
import interactionPlugin from '@fullcalendar/interaction';
import timeGridPlugin from '@fullcalendar/timegrid';
import frLocaleModule from '@fullcalendar/core/locales/fr';

const frLocale = frLocaleModule.code ? frLocaleModule : frLocaleModule.default;

/**
 * "Semaine type" bulk-apply builder (App\Controller\ProgramTimetableSettingsController::weeklyTemplateForm()/
 * applyWeeklyTemplate(), App\Service\WeeklyTemplateApplier) - a Monday-Saturday pattern of draft
 * sessions kept entirely in memory (never persisted here) until "Appliquer" sends the whole batch
 * + the périodes list + the replace flag in one POST. Reuses lesson_timetable_controller.js's
 * FullCalendar setup for the grid, but with hiddenDays:[0] (Sunday only) instead of the real
 * calendar's weekends:false (which hides Saturday too, since the real calendar is Mon-Fri only) -
 * this one must show Mon-Sat per the "semaine type" spec. Also fixed to a single reference week
 * with no navigation chrome, no server feed, and select/eventClick opening a modal instead of
 * navigating away, since there's no LessonSession row to navigate to/from yet.
 */
export default class extends Controller {
    static targets = [
        'calendar', 'periodsList', 'periodRowTemplate', 'periodRow', 'periodStart', 'periodEnd', 'periodError', 'replaceCheckbox',
        'modal', 'modalDayOfWeek', 'modalStartHour', 'modalEndHour', 'modalLength',
        'modalTitle', 'modalTopic', 'modalTeacher', 'modalClassRoom', 'modalLessonType',
        'modalOptions', 'modalDeleteButton', 'summary',
    ];

    static values = {
        applyUrl: String,
        applyToken: String,
        referenceMonday: String,
        replaceConfirmMessage: String,
        emptyErrorMessage: String,
        notMondayMessage: String,
        notSaturdayOrSundayMessage: String,
        notInFutureMessage: String,
    };

    connect() {
        this.draftSessions = new Map();
        this.nextDraftId = 1;
        this.editingId = null;

        this.calendar = new Calendar(this.calendarTarget, {
            plugins: [interactionPlugin, timeGridPlugin],
            locale: frLocale,
            timeZone: 'Europe/Paris',
            initialView: 'timeGridWeek',
            initialDate: this.referenceMondayValue,
            validRange: { start: this.referenceMondayValue, end: this.weekEnd() },
            slotMinTime: '08:00',
            slotMaxTime: '19:00',
            allDaySlot: false,
            hiddenDays: [0],
            navLinks: false,
            nowIndicator: false,
            height: 'auto',
            headerToolbar: { left: '', center: 'title', right: '' },
            selectable: true,
            selectMirror: true,
            select: (info) => this.onSelect(info),
            editable: true,
            eventStartEditable: true,
            eventDrop: (info) => this.onEventDrop(info),
            eventClick: (info) => this.onEventClick(info),
        });

        this.calendar.render();
    }

    disconnect() {
        this.calendar?.destroy();
        this.calendar = null;
    }

    // Lazy on purpose: at connect() time, Tabler's bundled tabler.min.js (loaded globally in
    // base.html.twig, embeds Bootstrap 5's JS under window.tabler.Modal - NOT window.bootstrap,
    // which doesn't exist; there's no importable ESM "bootstrap" package wired via AssetMapper in
    // this project either) isn't guaranteed to have executed yet relative to this module-loaded
    // controller. By the time the user actually drags/clicks to open the modal, the page has long
    // since finished loading.
    modal() {
        this._modal ??= new window.tabler.Modal(this.modalTarget);

        return this._modal;
    }

    weekEnd() {
        const end = new Date(`${this.referenceMondayValue}T00:00:00`);
        end.setDate(end.getDate() + 7);

        return this.formatLocalDate(end);
    }

    // date.toISOString() converts to UTC first - for any browser whose local timezone is ahead of
    // UTC (Europe/Paris included, this app's own audience), that silently shifts a local midnight
    // Date back to the previous calendar day. Every date here is built from local getDate()/
    // setDate() arithmetic, so it must be read back out the same way, never through toISOString().
    formatLocalDate(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    }

    // --- Add/edit-session modal ---------------------------------------------------------------

    onSelect(info) {
        this.editingId = null;
        this.modalDeleteButtonTarget.classList.add('d-none');
        this.fillModal({
            dayOfWeek: new Date(`${info.startStr.slice(0, 10)}T00:00:00`).getDay(),
            startHour: info.startStr.slice(11, 16),
            endHour: info.endStr.slice(11, 16),
            length: this.roundedHours(info.startStr, info.endStr),
            title: '', topicId: '', teacherId: '', classRoomId: '', lessonTypeId: '', optionIds: [],
        });
        this.calendar.unselect();
        this.modal().show();
    }

    onEventClick(info) {
        const draft = this.draftSessions.get(info.event.id);

        if (!draft) {
            return;
        }

        this.editingId = info.event.id;
        this.modalDeleteButtonTarget.classList.remove('d-none');
        this.fillModal(draft);
        this.modal().show();
    }

    onEventDrop(info) {
        const draft = this.draftSessions.get(info.event.id);

        if (!draft) {
            return;
        }

        draft.dayOfWeek = new Date(`${info.event.startStr.slice(0, 10)}T00:00:00`).getDay();
        draft.startHour = info.event.startStr.slice(11, 16);
        draft.endHour = info.event.endStr.slice(11, 16);
    }

    roundedHours(startStr, endStr) {
        const hours = (new Date(endStr) - new Date(startStr)) / 3_600_000;

        return (Math.round(hours * 2) / 2).toFixed(2);
    }

    fillModal(draft) {
        this.modalDayOfWeekTarget.value = String(draft.dayOfWeek);
        this.modalStartHourTarget.value = draft.startHour;
        this.modalEndHourTarget.value = draft.endHour;
        this.modalLengthTarget.value = draft.length;
        this.modalTitleTarget.value = draft.title ?? '';
        this.modalTopicTarget.value = draft.topicId ?? '';
        this.modalTeacherTarget.value = draft.teacherId ?? '';
        this.modalClassRoomTarget.value = draft.classRoomId ?? '';
        this.modalLessonTypeTarget.value = draft.lessonTypeId ?? '';

        this.modalOptionsTarget.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
            checkbox.checked = (draft.optionIds ?? []).includes(checkbox.value);
        });
    }

    saveModal() {
        const draft = {
            dayOfWeek: Number(this.modalDayOfWeekTarget.value),
            startHour: this.modalStartHourTarget.value,
            endHour: this.modalEndHourTarget.value,
            length: this.modalLengthTarget.value,
            title: this.modalTitleTarget.value,
            topicId: this.modalTopicTarget.value || null,
            teacherId: this.modalTeacherTarget.value || null,
            classRoomId: this.modalClassRoomTarget.value || null,
            lessonTypeId: this.modalLessonTypeTarget.value || null,
            optionIds: Array.from(this.modalOptionsTarget.querySelectorAll('input[type="checkbox"]:checked')).map((c) => c.value),
        };

        const id = this.editingId ?? String(this.nextDraftId++);
        this.draftSessions.set(id, draft);
        this.renderDraftEvent(id, draft);
        this.modal().hide();
    }

    deleteDraft() {
        if (null === this.editingId) {
            return;
        }

        this.draftSessions.delete(this.editingId);
        this.calendar.getEventById(this.editingId)?.remove();
        this.modal().hide();
    }

    renderDraftEvent(id, draft) {
        this.calendar.getEventById(id)?.remove();

        const dayOffset = (draft.dayOfWeek - 1 + 7) % 7;
        const day = new Date(`${this.referenceMondayValue}T00:00:00`);
        day.setDate(day.getDate() + dayOffset);
        const dateStr = this.formatLocalDate(day);

        this.calendar.addEvent({
            id,
            start: `${dateStr}T${draft.startHour}`,
            end: `${dateStr}T${draft.endHour}`,
            title: draft.title || this.optionLabelFor('modalTopic', draft.topicId) || '(sans titre)',
        });
    }

    optionLabelFor(targetName, value) {
        if (!value) {
            return '';
        }

        const select = this[`${targetName}Target`];

        return select.querySelector(`option[value="${CSS.escape(value)}"]`)?.textContent.trim() ?? '';
    }

    // --- Périodes ------------------------------------------------------------------------------

    addPeriod() {
        const row = this.periodRowTemplateTarget.content.cloneNode(true);
        this.periodsListTarget.appendChild(row);
    }

    removePeriod(event) {
        event.target.closest('[data-weekly-template-target~="periodRow"]')?.remove();
    }

    // Fast feedback only, mirroring WeeklyTemplateApplier::validatePeriods()'s rules - the server
    // call is what actually enforces them, this just avoids a round-trip for obvious mistakes.
    // Validates whichever of start/end is already filled in independently, rather than waiting
    // for both - a lone start date typed in should immediately warn if it's not a future Monday,
    // without needing an end date first.
    validatePeriodRow(event) {
        const row = event.target.closest('[data-weekly-template-target~="periodRow"]');
        const start = row.querySelector('[data-weekly-template-target~="periodStart"]').value;
        const end = row.querySelector('[data-weekly-template-target~="periodEnd"]').value;
        const errorEl = row.querySelector('[data-weekly-template-target~="periodError"]');
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const errors = [];

        if (start) {
            if (1 !== new Date(`${start}T00:00:00`).getDay()) {
                errors.push(this.notMondayMessageValue);
            }

            if (new Date(`${start}T00:00:00`) <= today) {
                errors.push(this.notInFutureMessageValue);
            }
        }

        if (end && ![0, 6].includes(new Date(`${end}T00:00:00`).getDay())) {
            errors.push(this.notSaturdayOrSundayMessageValue);
        }

        errorEl.textContent = errors.join(' ');
    }

    // --- Apply -----------------------------------------------------------------------------------

    apply() {
        this.summaryTarget.textContent = '';
        this.summaryTarget.classList.add('d-none');

        const periods = this.collectPeriods();
        const sessions = Array.from(this.draftSessions.values());

        if (0 === sessions.length || 0 === periods.length) {
            this.showSummary(this.emptyErrorMessageValue);

            return;
        }

        if (this.replaceCheckboxTarget.checked && !window.confirm(this.replaceConfirmMessageValue)) {
            return;
        }

        fetch(this.applyUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.applyTokenValue },
            body: JSON.stringify({ sessions, periods, replace: this.replaceCheckboxTarget.checked }),
        })
            .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
            .then(({ ok, data }) => {
                if (ok && data.success) {
                    window.location.href = data.redirectUrl;

                    return;
                }

                this.showSummary((data.violations ?? []).map((v) => v.message).join(' — '));
            })
            .catch(() => this.showSummary(this.emptyErrorMessageValue));
    }

    showSummary(message) {
        this.summaryTarget.textContent = message;
        this.summaryTarget.classList.remove('d-none');
    }

    collectPeriods() {
        return this.periodRowTargets
            .map((row) => ({
                start: row.querySelector('[data-weekly-template-target~="periodStart"]').value,
                end: row.querySelector('[data-weekly-template-target~="periodEnd"]').value,
            }))
            .filter((period) => period.start && period.end);
    }
}
