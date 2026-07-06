import { Controller } from '@hotwired/stimulus';
// Must run before any @fullcalendar/* plugin import - FullCalendar v5's plugin system relies
// on this side-effecting module to set up a shared registry first.
import 'fullcalendar';
import { Calendar } from '@fullcalendar/core';
import interactionPlugin from '@fullcalendar/interaction';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import frLocaleModule from '@fullcalendar/core/locales/fr';

// jsDelivr's ESM re-bundling of this locale file double-wraps its CJS "exports.default" as
// { default: { code: 'fr', ... } } instead of the flat locale object - unwrap it defensively.
const frLocale = frLocaleModule.code ? frLocaleModule : frLocaleModule.default;

/**
 * Weekly lesson-session calendar, ported from the reference app's plain
 * assets/js/calendar/index.js (same FullCalendar library/config) but wrapped as a Stimulus
 * controller to match this project's convention. Two modes, both server-driven from the same
 * event feed shape:
 *  - editable (settings/timetable tab): click a session to edit it (via event.url), drag to
 *    reschedule (persisted through moveUrlTemplate), select an empty slot to create one.
 *  - read-only (student/teacher-facing page): pure display, no interaction.
 */
export default class extends Controller {
    static values = {
        feedUrl: String,
        editable: { type: Boolean, default: false },
        focus: String,
        newSessionUrlTemplate: String,
        moveUrlTemplate: String,
        moveToken: String,
        moveErrorMessage: String,
    };

    connect() {
        this.calendar = new Calendar(this.element, {
            plugins: [interactionPlugin, dayGridPlugin, timeGridPlugin],
            locale: frLocale,
            timeZone: 'Europe/Paris',
            initialView: 'timeGridWeek',
            initialDate: this.hasFocusValue ? this.focusValue : undefined,
            slotMinTime: '08:00',
            slotMaxTime: '19:00',
            allDaySlot: false,
            weekends: false,
            weekNumbers: true,
            navLinks: true,
            nowIndicator: true,
            height: 'auto',
            headerToolbar: { left: 'prev,next today', center: 'title', right: '' },
            eventSources: [{ url: this.feedUrlValue, method: 'POST' }],
            eventContent: (arg) => this.renderEvent(arg),
            editable: this.editableValue,
            eventStartEditable: this.editableValue,
            selectable: this.editableValue,
            selectMirror: this.editableValue,
            select: this.editableValue ? (info) => this.onSelect(info) : undefined,
            eventDrop: this.editableValue ? (info) => this.onEventDrop(info) : undefined,
        });

        this.calendar.render();
    }

    disconnect() {
        this.calendar?.destroy();
        this.calendar = null;
    }

    renderEvent(arg) {
        const { teacher, classRoom, lessonType, options } = arg.event.extendedProps;
        const details = [lessonType, classRoom, teacher, options].filter((value) => value).join(' · ');

        return { html: `<b>${arg.event.title}</b>${details ? `<br/><i>${details}</i>` : ''}` };
    }

    onSelect(info) {
        const url = this.newSessionUrlTemplateValue
            .replace('__START__', encodeURIComponent(info.startStr))
            .replace('__END__', encodeURIComponent(info.endStr));

        window.location.href = url;
    }

    onEventDrop(info) {
        const url = this.moveUrlTemplateValue.replace('__ID__', info.event.id);

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.moveTokenValue,
            },
            // startStr/endStr are already formatted in the calendar's configured timeZone
            // (Europe/Paris) - unlike start/end.toISOString(), which would convert to UTC and
            // require the server to convert back, an easy way to reintroduce the project's past
            // 2-hour timezone bug.
            body: JSON.stringify({
                start: info.event.startStr,
                end: info.event.endStr,
            }),
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Unexpected response status: ${response.status}`);
                }
            })
            .catch(() => {
                window.alert(this.moveErrorMessageValue);
                info.revert();
            });
    }
}
