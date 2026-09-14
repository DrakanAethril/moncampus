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
 *    navigates to its cahier de texte (extendedProps.logUrl).
 *
 * Whether that second click exists at all is the server's answer, never this controller's:
 * LessonSessionEventFormatter omits logUrl for a viewer who could not open the cahier de texte
 * (feature off, or LessonLogVoter refusing), and a session without one is inert and shows no
 * pointer - an emploi du temps stays perfectly readable to somebody who has nothing but it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['calendar', 'icalUrl', 'icalCopy'];

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
        // The subscription banner, when the screen carries one
        // (templates/partials/_timetable_ical_banner.html.twig). `icalRotateUrl` is the endpoint
        // that mints a new token, `icalRotateToken` its CSRF token.
        icalRotateUrl: String,
        icalRotateToken: String,
        icalRotateErrorMessage: String,
    };

    connect() {
        // Legend swatches (program/_timetable_legend.html.twig or teacher/_timetable_legend.html.twig)
        // live as a sibling of the calendar target inside this same controller element - keyed by
        // extendedProps.legendKey, independently of which color scheme (Option vs formation) is
        // in play. Starts empty: every legend is active/visible until clicked.
        this.hiddenLegendKeys = new Set();
        // The link as the server handed it over, before any filter - every later value of the field
        // is rebuilt from this one, so toggling a legend off and back on returns the exact URL
        // rather than an accumulation of query strings.
        this.icalBaseUrl = this.hasIcalUrlTarget ? this.icalUrlTarget.value : null;

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
            eventClassNames: (arg) => this.eventClasses(arg),
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

    // Only a session the server actually handed a destination for looks clickable: FullCalendar's
    // own `cursor: pointer` rule keys off an href, which a read-only event does not carry.
    eventClasses(arg) {
        return !this.editableValue && arg.event.extendedProps.logUrl ? ['cm-calendar__event--clickable'] : [];
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

        this.syncIcalUrl();
    }

    // Keeps the subscription banner's URL equal to what is on screen, on every toggle.
    //
    // This is the whole of « the link matches the calendar at the moment you take it »: rather than
    // capturing the filter when a button is pressed, the field simply never holds anything else, so
    // copying it by hand with the mouse gives the same URL as the « Copier » button. The hidden keys
    // are sorted so the same set of swatches always produces the same string - an agenda that sees a
    // different URL treats it as a different calendar.
    syncIcalUrl() {
        if (!this.hasIcalUrlTarget || !this.icalBaseUrl) {
            return;
        }

        const hidden = [...this.hiddenLegendKeys].sort();
        const url = new URL(this.icalBaseUrl);

        if (hidden.length > 0) {
            url.searchParams.set('hide', hidden.join(','));
        } else {
            url.searchParams.delete('hide');
        }

        this.icalUrlTarget.value = url.toString();
    }

    // Copies whatever the field currently holds. navigator.clipboard is unavailable outside a secure
    // context (plain http, which this app is served over on a dev machine), so the field's own
    // select() is the fallback rather than a failure - and it leaves the URL selected, which is what
    // somebody does next by hand anyway.
    copyIcalUrl() {
        const input = this.icalUrlTarget;

        input.select();
        input.setSelectionRange(0, input.value.length);

        const done = () => {
            if (!this.hasIcalCopyTarget) {
                return;
            }

            const button = this.icalCopyTarget;
            const original = button.textContent;

            button.textContent = button.dataset.copiedLabel;
            window.setTimeout(() => {
                button.textContent = original;
            }, 2000);
        };

        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(input.value).then(done).catch(() => {});

            return;
        }

        done();
    }

    // Mints a new token server-side, which breaks every subscription made from the old URL -
    // including the ones this person made themself, which is why it asks first. The answer carries
    // the fresh unfiltered URL, so it becomes the new base and syncIcalUrl() re-applies whatever is
    // hidden right now on top of it.
    rotateIcalUrl(event) {
        if (!window.confirm(event.currentTarget.dataset.confirmMessage)) {
            return;
        }

        fetch(this.icalRotateUrlValue, {
            method: 'POST',
            // Read from the header on the server side (App\Controller\TimetableCalendarController):
            // there is no form here to carry a _csrf_token field.
            headers: { 'X-CSRF-Token': this.icalRotateTokenValue },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Unexpected response status: ${response.status}`);
                }

                return response.json();
            })
            .then((payload) => {
                this.icalBaseUrl = payload.url;
                this.syncIcalUrl();
            })
            .catch(() => {
                window.alert(this.icalRotateErrorMessageValue);
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
