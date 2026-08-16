import { Controller } from '@hotwired/stimulus';

/**
 * The four-step « Nouveau travail » wizard (design_handoff_creation_travail 2a).
 *
 * The whole wizard fits in a single already-rendered form: this controller only decides what is
 * shown - the current step, the recipients of the chosen class, the sections the work type calls
 * for. Nothing is saved before « Publier le travail », so no step triggers a server round trip, and
 * the only submission is that of the last step.
 *
 * The same controller serves the three mountings (full page, modal, panel): it only knows its own
 * card, never the page around it.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'railStep', 'stepPanel', 'backButton', 'nextButton',
        'programPick', 'noClassBanner', 'audienceBlock', 'audiencePane', 'optionChoice',
        'studentRow', 'selectedCount', 'groupBatchSelect', 'groupChips',
        'naturePane', 'productionList', 'productionRow', 'productionDueMode', 'productionPosition',
        'attachmentChips', 'attachmentLinks',
        'quizSelect', 'evaluationSelect',
        'duePreset', 'duePresetNextLabel', 'duePresetWeekLabel', 'dueCustom', 'dueDate', 'dueTime',
        'multiDueBanner', 'multiDueText', 'lateLabel', 'gradeVisibility', 'gradeVisibilityLabel',
        'scheduledAt',
    ];

    static values = {
        // The step carrying the first publication error, 0 if there is none - see the template.
        errorStep: Number,
        groupMembers: Object,
        nextDue: String,
        weekDue: String,
        nextDueLabel: String,
        weekDueLabel: String,
    };

    connect() {
        this.step = 1;
        // Pasted links live in a hidden field, one URL per line; the chips are only their reading.
        this.links = (this.attachmentLinksTarget.value || '').split(/\r?\n/).filter((line) => line.trim() !== '');

        this.duePresetNextLabelTarget.textContent = this.nextDueLabelValue;
        this.duePresetWeekLabelTarget.textContent = this.weekDueLabelValue;

        this.syncDueTimeFromField();
        this.programChanged();
        this.natureChanged();
        this.visibilityChanged();
        this.refreshToggleLabels();
        this.refreshAttachmentChips();
        this.productionDueModeTargets.forEach((select) => this.applyProductionDueMode(select));
        // A refused publication reopens the wizard on the offending step: the message is already
        // there, the step still has to be the one being looked at.
        this.showStep(this.errorStepValue || 1);
    }

    /* ---------- Navigation between steps ---------- */

    goToStep(event) {
        this.showStep(Number(event.currentTarget.dataset.step));
    }

    back() {
        this.showStep(Math.max(1, this.step - 1));
    }

    /**
     * The same button advances three steps then publishes. Publishing is the only gesture that
     * writes: it is here, and nowhere before, that the form goes to the server.
     */
    next() {
        if (this.step < 4) {
            this.showStep(this.step + 1);
            return;
        }

        this.element.requestSubmit();
    }

    showStep(step) {
        this.step = step;

        this.stepPanelTargets.forEach((panel) => {
            panel.classList.toggle('d-none', Number(panel.dataset.step) !== step);
        });

        this.railStepTargets.forEach((railStep) => {
            const target = Number(railStep.dataset.step);
            railStep.classList.toggle('is-current', target === step);
            railStep.classList.toggle('is-done', target < step);
        });

        this.backButtonTarget.classList.toggle('d-none', step === 1);
        this.nextButtonTarget.textContent = step === 4
            ? this.nextButtonTarget.dataset.publishLabel ?? this.nextButtonTarget.textContent
            : this.nextButtonTarget.dataset.continueLabel ?? this.nextButtonTarget.textContent;

        this.refreshGate();
    }

    /**
     * « Un travail est toujours assigné à une classe »: with no class chosen, step 1 cannot be
     * cleared and the button stays off.
     */
    refreshGate() {
        const blocked = this.step === 1 && this.selectedProgramId() === null;

        this.nextButtonTarget.disabled = blocked;
        this.noClassBannerTarget.classList.toggle('d-none', !blocked);
    }

    /* ---------- Étape 1 · Destinataires ---------- */

    selectedProgramId() {
        const checked = this.programPickTarget.querySelector('input:checked');

        return checked ? checked.value : null;
    }

    programChanged() {
        const programId = this.selectedProgramId();

        this.audienceBlockTarget.classList.toggle('d-none', programId === null);

        // Every class-dependent choice carries the list of classes it applies to; only those of the
        // selected class are shown, and the others are silently unticked.
        this.optionChoiceTargets.forEach((choice) => {
            const belongs = this.belongsToProgram(choice.dataset.programs, programId);
            choice.classList.toggle('d-none', !belongs);
            if (!belongs) {
                choice.querySelector('input').checked = false;
            }
        });

        this.studentRowTargets.forEach((row) => {
            const belongs = this.belongsToProgram(row.dataset.programs, programId);
            row.classList.toggle('is-off-program', !belongs);
            if (!belongs) {
                row.querySelector('input').checked = false;
            }
        });

        [this.groupBatchSelectTarget, this.quizSelectTarget, this.evaluationSelectTarget].forEach((select) => {
            this.filterSelect(select, programId);
        });

        this.studentToggled();
        this.groupBatchChanged();
        this.audienceChanged();
        this.refreshHeading();
        this.refreshGate();
    }

    /**
     * The screen title names the class as soon as it is chosen. The element is spotted by its
     * attribute and not by a layout class: it is the page that declares itself rewritable, the
     * wizard will never touch the title of a screen that has not asked for it - a modal opened over
     * another screen does not rename that page.
     */
    refreshHeading() {
        const heading = document.querySelector('[data-assignment-wizard-heading]');

        if (!heading) {
            return;
        }

        // The label is read off the chip itself: its textContent would also carry that of the
        // .form-check-label the Bootstrap theme adds and the stylesheet hides.
        const label = this.programPickTarget.querySelector('input:checked')?.closest('.cm-pillpick__item')?.dataset.programLabel;

        heading.textContent = label
            ? heading.dataset.withClass.replace('%class%', label)
            : heading.dataset.plain;
    }

    belongsToProgram(programs, programId) {
        return programId !== null && (programs ?? '').split(' ').includes(programId);
    }

    /** A dropdown cannot be filtered by class: entries of another one are removed. */
    filterSelect(select, programId) {
        let selectedWasRemoved = false;

        Array.from(select.options).forEach((option) => {
            if (option.value === '') {
                return;
            }

            const belongs = this.belongsToProgram(option.dataset.programs, programId);
            option.hidden = !belongs;
            option.disabled = !belongs;

            if (!belongs && option.selected) {
                selectedWasRemoved = true;
            }
        });

        if (selectedWasRemoved) {
            select.value = '';
        }
    }

    audienceChanged() {
        const audience = this.element.querySelector('[name$="[audienceType]"]:checked')?.value;

        this.audiencePaneTargets.forEach((pane) => {
            pane.classList.toggle('d-none', pane.dataset.audience !== audience);
        });
    }

    filterStudents(event) {
        const needle = event.currentTarget.value.trim().toLowerCase();

        this.studentRowTargets.forEach((row) => {
            row.classList.toggle('is-filtered', needle !== '' && !row.dataset.name.includes(needle));
        });
    }

    studentToggled() {
        const count = this.studentRowTargets.filter((row) => row.querySelector('input').checked).length;

        this.selectedCountTarget.textContent = this.selectedCountTarget.textContent.replace(/\d+/, count);
    }

    /** The summary chips of the chosen batch - who is with whom, before publishing. */
    groupBatchChanged() {
        const batchId = this.groupBatchSelectTarget.value;
        const groups = this.groupMembersValue[batchId] ?? [];

        this.groupChipsTarget.replaceChildren(...groups.map((members, index) => {
            const chip = document.createElement('span');
            chip.className = 'cm-groupchip';

            const name = document.createElement('b');
            name.textContent = `${this.groupChipsTarget.dataset.groupLabel ?? 'Groupe'} ${index + 1}`;
            chip.append(name, ` · ${members.join(', ')}`);

            return chip;
        }));
    }

    /* ---------- Étape 2 · Type ---------- */

    natureChanged() {
        const nature = this.element.querySelector('[name$="[nature]"]:checked')?.value;

        this.naturePaneTargets.forEach((pane) => {
            pane.classList.toggle('d-none', !pane.dataset.natures.split(' ').includes(nature));
        });

        this.refreshMultiDueBanner();
    }

    /**
     * The mockup's switches say their state in full - « Dépôt en retard autorisé » / « non
     * autorisé » - and not by their position alone.
     */
    refreshToggleLabels() {
        const gradeVisible = this.gradeVisibilityTarget.querySelector('input').checked;
        this.gradeVisibilityLabelTarget.textContent = gradeVisible
            ? this.gradeVisibilityLabelTarget.dataset.onLabel ?? this.gradeVisibilityLabelTarget.textContent
            : this.gradeVisibilityLabelTarget.dataset.offLabel ?? this.gradeVisibilityLabelTarget.textContent;

        if (this.hasLateLabelTarget) {
            const lateAllowed = this.lateLabelTarget.closest('.cm-toggle-row').querySelector('input').checked;
            this.lateLabelTarget.textContent = lateAllowed
                ? this.lateLabelTarget.dataset.onLabel ?? this.lateLabelTarget.textContent
                : this.lateLabelTarget.dataset.offLabel ?? this.lateLabelTarget.textContent;
        }
    }

    /* ---------- Étape 3 · Consigne ---------- */

    addProduction() {
        const template = document.createElement('template');
        template.innerHTML = this.productionListTarget.dataset.prototype
            .replace(/__production__/g, String(this.productionRowTargets.length))
            .trim();

        this.productionListTarget.append(template.content.firstElementChild);
        this.applyProductionDueMode(this.productionDueModeTargets[this.productionDueModeTargets.length - 1]);
        this.renumberProductions();
    }

    removeProduction(event) {
        event.currentTarget.closest('.cm-prod-row').remove();
        this.renumberProductions();
        this.refreshMultiDueBanner();
    }

    // The rank as the rows read: removing the middle one must not leave a gap the server would sort
    // wrongly.
    renumberProductions() {
        this.productionPositionTargets.forEach((field, index) => {
            field.value = String(index);
        });
    }

    productionDueModeChanged(event) {
        this.applyProductionDueMode(event.currentTarget);
        this.refreshMultiDueBanner();
    }

    applyProductionDueMode(select) {
        const row = select.closest('.cm-prod-row');
        const custom = select.value === 'custom';

        row.classList.toggle('is-custom-due', custom);
        row.querySelector('.cm-prod-row__date').classList.toggle('d-none', !custom);
    }

    /**
     * The multiple-deadlines banner: it names the files that do not follow the assignment's
     * deadline, since that is what the student will see on their side.
     */
    refreshMultiDueBanner() {
        if (!this.hasMultiDueBannerTarget) {
            return;
        }

        const custom = this.productionRowTargets
            .filter((row) => row.querySelector('.cm-prod-row__mode').value === 'custom')
            .map((row) => row.querySelector('.cm-prod-row__name').value.trim())
            .filter((name) => name !== '');

        this.multiDueBannerTarget.classList.toggle('d-none', custom.length === 0);

        if (custom.length > 0) {
            this.multiDueTextTarget.textContent = (this.multiDueTextTarget.dataset.template ?? '%files%')
                .replace('%files%', custom.map((name) => `« ${name} »`).join(', '));
        }
    }

    /**
     * A support already attached that is being detached: the chip is struck through instead of
     * disappearing, because its checkbox must stay in the form to tell the server, on saving, to
     * remove it - and because one must be able to change one's mind before that.
     */
    attachmentDropToggled(event) {
        event.currentTarget.closest('.cm-chip').classList.toggle('is-dropped', event.currentTarget.checked);
    }

    pasteLink() {
        const url = window.prompt(this.attachmentChipsTarget.dataset.linkPrompt ?? '');

        if (url && url.trim() !== '') {
            this.links.push(url.trim());
            this.attachmentLinksTarget.value = this.links.join('\n');
            this.refreshAttachmentChips();
        }
    }

    // Links only, since the file half moved to App\Form\FilePickerType: the picker draws its own
    // rows, with a progress bar and a verdict per file, and a second list of chips saying the same
    // thing in fewer words would be two places to keep in step.
    removeAttachment(event) {
        const { index } = event.currentTarget.dataset;

        this.links.splice(Number(index), 1);
        this.attachmentLinksTarget.value = this.links.join('\n');
        this.refreshAttachmentChips();
    }

    refreshAttachmentChips() {
        this.attachmentChipsTarget.replaceChildren(...this.links.map((url, index) => this.buildChip(url, index, 'link')));
    }

    buildChip(label, index, kind) {
        const chip = document.createElement('span');
        chip.className = 'cm-chip';
        chip.textContent = label;

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'cm-chip__remove';
        remove.dataset.index = String(index);
        remove.dataset.kind = kind;
        remove.dataset.action = 'assignment-wizard#removeAttachment';
        remove.textContent = '✕';

        chip.append(remove);

        return chip;
    }

    /* ---------- Étape 4 · Échéance ---------- */

    applyDuePreset(event) {
        const preset = event.currentTarget.dataset.preset;

        this.duePresetTargets.forEach((pill) => pill.classList.toggle('is-selected', pill === event.currentTarget));
        this.dueCustomTarget.classList.toggle('d-none', preset !== 'custom');

        if (preset !== 'custom') {
            this.dueDateTarget.value = preset === 'next' ? this.nextDueValue : this.weekDueValue;
            this.syncDueTimeFromField();
        }
    }

    dueDateChanged() {
        this.syncDueTimeFromField();
    }

    /** The time picker only carries the time: it rewrites the date's time, never its day. */
    dueTimeChanged() {
        const [date] = (this.dueDateTarget.value || '').split('T');

        if (date) {
            this.dueDateTarget.value = `${date}T${this.dueTimeTarget.value}`;
        }
    }

    syncDueTimeFromField() {
        const time = (this.dueDateTarget.value || '').split('T')[1];

        if (time && Array.from(this.dueTimeTarget.options).some((option) => option.value === time.slice(0, 5))) {
            this.dueTimeTarget.value = time.slice(0, 5);
        }
    }

    visibilityChanged() {
        const visibility = this.element.querySelector('[name$="[visibility]"]:checked')?.value;

        this.scheduledAtTarget.classList.toggle('d-none', visibility !== 'scheduled');
    }
}
