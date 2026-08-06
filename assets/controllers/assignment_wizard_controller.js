import { Controller } from '@hotwired/stimulus';

/**
 * L'assistant « Nouveau travail » en quatre étapes (design_handoff_creation_travail 2a).
 *
 * Tout l'assistant tient dans un seul formulaire déjà rendu : ce contrôleur ne fait que décider ce
 * qui se voit - l'étape courante, les destinataires de la classe choisie, les sections que le type
 * de travail appelle. Rien n'est enregistré avant « Publier le travail », donc aucune étape ne
 * déclenche d'aller-retour serveur, et le seul envoi est celui de la dernière étape.
 *
 * Le même contrôleur sert aux trois montages (pleine page, modale, panneau) : il ne connaît que sa
 * carte, jamais la page autour.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = [
        'railStep', 'stepPanel', 'backButton', 'nextButton',
        'programPick', 'noClassBanner', 'audienceBlock', 'audiencePane', 'optionChoice',
        'studentRow', 'selectedCount', 'groupBatchSelect', 'groupChips',
        'naturePane', 'productionList', 'productionRow', 'productionDueMode', 'productionPosition',
        'attachmentInput', 'attachmentChips', 'attachmentLinks',
        'quizSelect', 'evaluationSelect',
        'duePreset', 'duePresetNextLabel', 'duePresetWeekLabel', 'dueCustom', 'dueDate', 'dueTime',
        'multiDueBanner', 'multiDueText', 'lateLabel', 'gradeVisibility', 'gradeVisibilityLabel',
        'scheduledAt',
    ];

    static values = {
        // L'étape portant la première erreur de publication, 0 s'il n'y en a pas - voir le gabarit.
        errorStep: Number,
        groupMembers: Object,
        nextDue: String,
        weekDue: String,
        nextDueLabel: String,
        weekDueLabel: String,
    };

    connect() {
        this.step = 1;
        // Les liens collés vivent dans un champ caché, une URL par ligne ; les chips ne sont que
        // leur lecture.
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
        // Une publication refusée rouvre l'assistant sur l'étape fautive : le message y est déjà,
        // encore faut-il que l'étape soit celle qu'on regarde.
        this.showStep(this.errorStepValue || 1);
    }

    /* ---------- Navigation entre étapes ---------- */

    goToStep(event) {
        this.showStep(Number(event.currentTarget.dataset.step));
    }

    back() {
        this.showStep(Math.max(1, this.step - 1));
    }

    /**
     * Le même bouton avance de trois étapes puis publie. Publier est le seul geste qui écrit :
     * c'est ici, et nulle part avant, que le formulaire part au serveur.
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
     * « Un travail est toujours assigné à une classe » : sans classe choisie, l'étape 1 ne se
     * franchit pas et le bouton reste éteint.
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

        // Chaque choix dépendant de la classe porte la liste des classes où il a cours ; on ne
        // montre que ceux de la classe retenue, et on décoche silencieusement les autres.
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
     * Le titre de l'écran nomme la classe dès qu'elle est choisie. L'élément est repéré par son
     * attribut et non par une classe de mise en page : c'est la page qui se déclare réécrivable,
     * l'assistant n'ira jamais toucher au titre d'un écran qui ne l'a pas demandé - une modale
     * ouverte par-dessus un autre écran n'en renomme pas la page.
     */
    refreshHeading() {
        const heading = document.querySelector('[data-assignment-wizard-heading]');

        if (!heading) {
            return;
        }

        // Le libellé est lu sur la pastille elle-même : son textContent emporterait aussi celui du
        // .form-check-label que le thème Bootstrap ajoute et que la feuille de style masque.
        const label = this.programPickTarget.querySelector('input:checked')?.closest('.cm-pillpick__item')?.dataset.programLabel;

        heading.textContent = label
            ? heading.dataset.withClass.replace('%class%', label)
            : heading.dataset.plain;
    }

    belongsToProgram(programs, programId) {
        return programId !== null && (programs ?? '').split(' ').includes(programId);
    }

    /** Une liste déroulante ne se filtre pas par classe : on retire les entrées d'une autre. */
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

    /** Les chips récapitulatives du lot choisi - qui est avec qui, avant de publier. */
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

    gradedChanged() {
        const graded = this.element.querySelector('[name$="[graded]"]:checked')?.value === '1';

        this.gradeVisibilityTarget.classList.toggle('d-none', !graded);
    }

    /**
     * Les interrupteurs de la maquette disent leur état en toutes lettres - « Dépôt en retard
     * autorisé » / « non autorisé » - et non par leur seule position.
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

        this.gradedChanged();
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

    // Le rang tel que les lignes se lisent : retirer celle du milieu ne doit pas laisser un trou
    // que le serveur trierait de travers.
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
     * Le bandeau des échéances multiples : il nomme les fichiers qui ne suivent pas l'échéance du
     * travail, puisque c'est ce que l'étudiant verra de son côté.
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
     * Un support déjà joint que l'on décroche : la chip se barre au lieu de disparaître, parce que
     * sa case doit rester dans le formulaire pour dire au serveur, à l'enregistrement, de le
     * retirer - et parce qu'on doit pouvoir se raviser avant.
     */
    attachmentDropToggled(event) {
        event.currentTarget.closest('.cm-chip').classList.toggle('is-dropped', event.currentTarget.checked);
    }

    browseFiles() {
        this.attachmentInputTarget.click();
    }

    filesPicked() {
        this.refreshAttachmentChips();
    }

    dragOver(event) {
        event.preventDefault();
        event.currentTarget.classList.add('is-hover');
    }

    dragLeave(event) {
        event.currentTarget.classList.remove('is-hover');
    }

    /**
     * Un glisser-déposer ne remplit pas un `<input type="file">` tout seul : il faut lui repasser
     * un DataTransfer, seul moyen d'écrire dans `files` sans passer par la boîte de dialogue.
     */
    dropFiles(event) {
        event.preventDefault();
        event.currentTarget.classList.remove('is-hover');

        const transfer = new DataTransfer();
        Array.from(this.attachmentInputTarget.files).forEach((file) => transfer.items.add(file));
        Array.from(event.dataTransfer.files).forEach((file) => transfer.items.add(file));

        this.attachmentInputTarget.files = transfer.files;
        this.refreshAttachmentChips();
    }

    pasteLink() {
        const url = window.prompt(this.attachmentChipsTarget.dataset.linkPrompt ?? '');

        if (url && url.trim() !== '') {
            this.links.push(url.trim());
            this.attachmentLinksTarget.value = this.links.join('\n');
            this.refreshAttachmentChips();
        }
    }

    removeAttachment(event) {
        const { index, kind } = event.currentTarget.dataset;

        if (kind === 'link') {
            this.links.splice(Number(index), 1);
            this.attachmentLinksTarget.value = this.links.join('\n');
        } else {
            const transfer = new DataTransfer();
            Array.from(this.attachmentInputTarget.files)
                .filter((_, position) => position !== Number(index))
                .forEach((file) => transfer.items.add(file));
            this.attachmentInputTarget.files = transfer.files;
        }

        this.refreshAttachmentChips();
    }

    refreshAttachmentChips() {
        const chips = [
            ...Array.from(this.attachmentInputTarget.files).map((file, index) => this.buildChip(file.name, index, 'file')),
            ...this.links.map((url, index) => this.buildChip(url, index, 'link')),
        ];

        this.attachmentChipsTarget.replaceChildren(...chips);
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

    /** Le sélecteur d'heure ne porte que l'heure : il réécrit celle de la date, jamais son jour. */
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
