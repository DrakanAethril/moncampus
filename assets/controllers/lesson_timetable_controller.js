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
 *  - read-only (student/teacher-facing page): otherwise pure display, but clicking a session
 *    navigates to its cahier de texte (extendedProps.logUrl) - view/edit access there is decided
 *    server-side per session (see LessonLogVoter), not by this page being read-only.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['calendar'];

    static values = {
        feedUrl: String,
        editable: { type: Boolean, default: false },
        focus: String,
        newSessionUrlTemplate: String,
        moveUrlTemplate: String,
        moveToken: String,
        moveErrorMessage: String,
        validRangeStart: String,
        validRangeEnd: String,
        // Which extendedProps to join into the event's detail line, and in what order - default
        // matches the original Program-scoped calendars (teacher/room/type/options; program and
        // topic are redundant there since the whole page is already one Program). The personal,
        // cross-Program teacher timetable (App\Controller\TeacherTimetableController) overrides
        // this instead to show program/topic/classRoom (formation/matière/salle), since which
        // Program and subject a session belongs to is the whole point of that view, not who's
        // teaching it (always the viewer themself there).
        eventDetailFields: { type: Array, default: ['lessonType', 'classRoom', 'teacher', 'options'] },
    };

    connect() {
        // Legend swatches (program/_timetable_legend.html.twig or teacher/_timetable_legend.html.twig)
        // live as a sibling of the calendar target inside this same controller element - keyed by
        // extendedProps.legendKey, independently of which color scheme (Option vs formation) is
        // in play. Starts empty: every legend is active/visible until clicked.
        this.hiddenLegendKeys = new Set();

        this.calendar = new Calendar(this.calendarTarget, {
            plugins: [interactionPlugin, dayGridPlugin, timeGridPlugin],
            locale: frLocale,
            timeZone: 'Europe/Paris',
            initialView: 'timeGridWeek',
            initialDate: this.hasFocusValue ? this.focusValue : undefined,
            // Either side is omitted (not just empty-string) when the Program has no effective
            // date on that end, per FullCalendar's validRange contract - passing '' would be
            // parsed as an invalid date instead of "no bound".
            validRange: {
                start: this.hasValidRangeStartValue ? this.validRangeStartValue : undefined,
                end: this.hasValidRangeEndValue ? this.validRangeEndValue : undefined,
            },
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
            eventDidMount: (arg) => this.onEventDidMount(arg),
            editable: this.editableValue,
            eventStartEditable: this.editableValue,
            selectable: this.editableValue,
            selectMirror: this.editableValue,
            select: this.editableValue ? (info) => this.onSelect(info) : undefined,
            eventDrop: this.editableValue ? (info) => this.onEventDrop(info) : undefined,
            eventClick: this.editableValue ? undefined : (info) => this.onReadOnlyEventClick(info),
        });

        this.calendar.render();
    }

    disconnect() {
        this.calendar?.destroy();
        this.calendar = null;
    }

    renderEvent(arg) {
        const details = this.eventDetailFieldsValue
            .map((field) => arg.event.extendedProps[field])
            .filter((value) => value)
            .join(' · ');

        return { html: `<b>${arg.event.title}</b>${details ? `<br/><i>${details}</i>` : ''}` };
    }

    // Tags every rendered event element with its legend key and applies the current filter state
    // immediately - runs again on every FullCalendar re-render (week navigation, refetch), so a
    // legend toggled off stays hidden for newly mounted events too, not just the ones visible at
    // click time.
    onEventDidMount(arg) {
        const key = arg.event.extendedProps.legendKey;

        if (!key) {
            return;
        }

        arg.el.dataset.legendKey = key;
        arg.el.style.display = this.hiddenLegendKeys.has(key) ? 'none' : '';
    }

    // Bound to each legend swatch's click (data-action="lesson-timetable#toggleLegend") - toggles
    // that one legend's own visibility independently of the others, so any combination can end up
    // active/hidden at once. Every already-mounted event sharing that legend key is shown/hidden
    // immediately by matching on the same data-legend-key onEventDidMount() set, without needing
    // a full calendar refetch/re-render.
    toggleLegend(event) {
        const item = event.currentTarget;
        const key = item.dataset.legendKey;

        if (this.hiddenLegendKeys.has(key)) {
            this.hiddenLegendKeys.delete(key);
        } else {
            this.hiddenLegendKeys.add(key);
        }

        item.classList.toggle('is-inactive', this.hiddenLegendKeys.has(key));

        this.calendarTarget.querySelectorAll(`[data-legend-key="${CSS.escape(key)}"]`).forEach((el) => {
            el.style.display = this.hiddenLegendKeys.has(key) ? 'none' : '';
        });
    }

    onReadOnlyEventClick(info) {
        const { logUrl } = info.event.extendedProps;

        if (logUrl) {
            window.location.href = logUrl;
        }
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
