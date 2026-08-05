import { Controller } from '@hotwired/stimulus';

// Carnet de notes - saisie rapide d'une évaluation (design/design_handoff_carnet_de_notes, écran 4).
// Une ligne par élève, dans l'un des deux modes du handoff : note globale (input + boutons
// Abs/N.É./( )) ou une case par question du barème avec total automatique. Le commentaire audio de
// l'élève se pose ici et nulle part ailleurs - la grille ne fait qu'en signaler la présence.
//
// Les lignes sont construites une fois au connect() puis mises à jour en place (statut, total,
// compteurs de l'en-tête) plutôt que redessinées : un re-render complet à chaque enregistrement
// ferait perdre le focus au beau milieu d'une saisie au clavier.
//
// L'enregistrement est automatique et par cellule ; il n'y a pas de bouton « enregistrer » global.
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['list', 'entered', 'average', 'progress', 'micWarning', 'player', 'sortLabel'];

    static values = {
        editable: Boolean,
        rows: Array,
        sections: Array,
        scale: Number,
        countsOutOf20: Boolean,
        saveGradeUrlTemplate: String,
        saveRubricUrlTemplate: String,
        uploadAudioUrlTemplate: String,
        deleteAudioUrlTemplate: String,
        playbackUrlTemplate: String,
        csrfToken: String,
        labels: Object,
    };

    connect() {
        this.rows = JSON.parse(JSON.stringify(this.rowsValue));
        this.sections = JSON.parse(JSON.stringify(this.sectionsValue));
        this.questions = this.sections.flatMap((section) => section.questions);
        this.hasRubric = this.questions.length > 0;
        this.nodes = {};
        this.sortDescending = false;
        this.render();
        this.refreshCounters();
    }

    disconnect() {
        this.stopStream();
    }

    // ---- Rendu ----------------------------------------------------------------------------

    // The server already sends the class in lastname order, so the first click reverses it and the
    // next one puts the alphabet back - the rank numbers follow the order shown, never the roster.
    toggleSort() {
        this.sortDescending = !this.sortDescending;
        const direction = this.sortDescending ? -1 : 1;
        this.rows.sort((a, b) => direction * a.name.localeCompare(b.name, 'fr', { sensitivity: 'base' }));

        if (this.hasSortLabelTarget) {
            this.sortLabelTarget.textContent = this.sortDescending ? this.labelsValue.sortDescLabel : this.labelsValue.sortAscLabel;
        }

        this.render();
    }

    render() {
        const children = [];
        if (this.hasRubric) children.push(this.buildQuestionHead());
        this.rows.forEach((row, index) => children.push(this.buildRow(row, index)));
        this.listTarget.replaceChildren(...children);
    }

    buildQuestionHead() {
        const head = this.el('div', 'cm-gb-qhead');

        // Bande des sections, alignée au-dessus des colonnes de leurs propres questions.
        const sectionsRow = this.el('div', 'cm-gb-qhead__sections');
        sectionsRow.appendChild(this.spacer(22));
        sectionsRow.appendChild(this.spacer(230));
        for (const section of this.sections) {
            const band = this.el('div', 'cm-gb-qhead__section', section.name);
            band.title = section.name;
            // 54px par question + les 10px de gouttière entre deux colonnes.
            band.style.width = `${section.questions.length * 54 + (section.questions.length - 1) * 10}px`;
            sectionsRow.appendChild(band);
        }
        head.appendChild(sectionsRow);

        const columnsRow = this.el('div', 'cm-gb-qhead__cols');
        columnsRow.appendChild(this.spacer(22));
        const pointsLabel = this.el('div', 'cm-gb-label', this.labelsValue.pointsPerQuestionLabel);
        pointsLabel.style.cssText = 'width: 230px; flex: none;';
        columnsRow.appendChild(pointsLabel);
        for (const question of this.questions) {
            const column = this.el('div', 'cm-gb-qhead__q');
            column.appendChild(this.el('span', 'cm-gb-qhead__num', question.label));
            column.appendChild(this.el('span', 'cm-gb-qhead__pts', `/${question.maxPoints}`));
            columnsRow.appendChild(column);
        }
        const total = this.el('div', 'cm-gb-qhead__total cm-gb-label', `${this.labelsValue.totalLabel} /${this.rubricTotalPoints()}`);
        columnsRow.appendChild(total);
        head.appendChild(columnsRow);

        return head;
    }

    buildRow(row, index) {
        const node = this.el('div', 'cm-gb-entry__row');
        node.appendChild(this.el('span', 'cm-gb-entry__rank', String(index + 1)));

        const name = this.el('div', 'cm-gb-entry__name');
        name.appendChild(this.el('span', null, row.name));
        if (row.option) {
            const tag = this.el('span', 'cm-gb-tag', row.option);
            if (row.optionColor) tag.style.backgroundColor = row.optionColor;
            name.appendChild(tag);
        }
        node.appendChild(name);

        const refs = { node };

        if (this.hasRubric) {
            refs.inputs = {};
            for (const question of this.questions) {
                const input = this.el('input', 'cm-gb-qinput');
                input.inputMode = 'decimal';
                input.placeholder = '–';
                input.value = this.answerDisplay(row.answers?.[question.id]);
                input.disabled = !this.editableValue;
                input.dataset.lastValid = input.value;
                input.addEventListener('blur', () => this.commitAnswer(row, question, input));
                input.addEventListener('keydown', (event) => this.onRubricKeydown(event, row, question, input));
                refs.inputs[question.id] = input;
                node.appendChild(input);
            }
            refs.total = this.el('span', 'cm-gb-qtotal');
            const totalCell = this.el('div', 'cm-gb-qhead__total');
            totalCell.appendChild(refs.total);
            node.appendChild(totalCell);
        } else {
            refs.status = this.el('span', 'cm-gb-entry__status');
            node.appendChild(refs.status);

            const inputWrap = this.el('div', 'd-flex align-items-center gap-1 flex-none');
            refs.input = this.el('input', 'cm-gb-entry__input');
            refs.input.inputMode = 'decimal';
            refs.input.placeholder = '—';
            refs.input.value = this.rawValueFor(row);
            refs.input.disabled = !this.editableValue;
            refs.input.addEventListener('blur', () => this.commitGrade(row, refs.input.value));
            refs.input.addEventListener('keydown', (event) => this.onGradeKeydown(event, row, refs.input));
            inputWrap.appendChild(refs.input);
            inputWrap.appendChild(this.el('span', 'cm-gb-entry__scale', `/${this.scaleValue}`));
            node.appendChild(inputWrap);

            if (this.editableValue) {
                const quick = this.el('div', 'cm-gb-quick');
                quick.appendChild(this.quickButton('cm-gb-quick--abs', this.labelsValue.absentShortLabel, this.labelsValue.absentLabel, () => this.commitGrade(row, 'abs')));
                quick.appendChild(this.quickButton('cm-gb-quick--ne', this.labelsValue.notEvaluatedShortLabel, this.labelsValue.notEvaluatedLabel, () => this.commitGrade(row, 'ne')));
                // Mettre entre parenthèses n'a de sens que sur une note déjà saisie : la valeur
                // reste, seul son décompte dans la moyenne change.
                quick.appendChild(this.quickButton('', this.labelsValue.excludedButtonLabel, this.labelsValue.excludedButtonTitle, () => {
                    if (row.value != null) this.commitGrade(row, `(${row.value})`);
                }));
                node.appendChild(quick);
            }
        }

        refs.audio = this.el('div', 'cm-gb-audio');
        node.appendChild(refs.audio);

        this.nodes[row.id] = refs;
        this.paintRow(row);

        return node;
    }

    quickButton(className, label, title, onClick) {
        const button = this.el('button', className, label);
        button.type = 'button';
        button.title = title;
        button.tabIndex = -1;
        button.addEventListener('click', onClick);

        return button;
    }

    // Statut, total et couleur d'une ligne - rappelé après chaque enregistrement.
    paintRow(row) {
        const refs = this.nodes[row.id];
        if (!refs) return;

        refs.node.classList.toggle('is-done', this.isEntered(row));

        if (this.hasRubric) {
            refs.total.className = `cm-gb-qtotal ${row.value == null ? '' : row.colorClass}`;
            refs.total.textContent = row.value == null ? '—' : `${this.formatGrade(row.value)}`;
        } else {
            const [text, modifier] = this.statusDisplay(row);
            refs.status.className = `cm-gb-entry__status ${modifier}`;
            refs.status.textContent = text;
        }

        this.paintAudio(row);
    }

    statusDisplay(row) {
        if (row.status === 'absent') return [this.labelsValue.absentLabel, 'cm-gb-val--abs'];
        if (row.status === 'not_evaluated') return [this.labelsValue.notEvaluatedLabel, 'cm-gb-val--ne'];
        if (row.status === 'not_tested') return [this.labelsValue.notTestedShortLabel, 'cm-gb-val--nt'];
        if (row.status === 'excluded') return [`(${this.formatGrade(row.value)})`, 'fst-italic'];
        if (row.status === 'normal' && row.value != null) return [`${this.formatGrade(row.value)}/${this.scaleValue}`, row.colorClass];

        return [this.labelsValue.toEnterLabel, 'cm-gb-entry__status--empty'];
    }

    // ---- Enregistrement -------------------------------------------------------------------

    async commitGrade(row, raw) {
        if (!this.editableValue) return;

        const url = this.saveGradeUrlTemplateValue.replace('__STUDENT_ID__', row.id);
        const data = await this.post(url, { raw: String(raw ?? '') });
        if (!data) return;

        if (data.cleared) {
            row.status = null;
            row.value = null;
            row.normalizedValue = null;
            row.colorClass = 'cm-grade-band-none';
        } else {
            row.status = data.status;
            row.value = data.value;
            row.normalizedValue = data.normalizedValue;
            row.colorClass = data.colorClass;
        }

        this.nodes[row.id].input.value = this.rawValueFor(row);
        this.paintRow(row);
        this.refreshCounters();
    }

    async commitAnswer(row, question, input) {
        if (!this.editableValue) return;
        const raw = input.value.trim();
        if (raw === input.dataset.lastValid) return;

        const url = this.saveRubricUrlTemplateValue
            .replace('__STUDENT_ID__', row.id)
            .replace('__QUESTION_ID__', question.id);

        const response = await this.post(url, { raw }, true);
        // Une valeur au-dessus du maximum de la question est refusée telle quelle, pas rabotée
        // (qSet() des créas) : on remet la dernière valeur réellement enregistrée.
        if (response === 422) {
            input.value = input.dataset.lastValid;
            input.classList.add('is-invalid');
            setTimeout(() => input.classList.remove('is-invalid'), 1500);

            return;
        }
        if (!response) return;

        input.dataset.lastValid = raw;
        row.answers[question.id] = raw === '' ? undefined : (raw.toLowerCase() === 'nt' ? 'nt' : parseFloat(raw.replace(',', '.')));
        row.value = response.total;
        row.normalizedValue = response.normalizedValue;
        row.colorClass = response.colorClass;
        this.paintRow(row);
        this.refreshCounters();
    }

    async post(url, payload, allow422 = false) {
        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfTokenValue },
                body: JSON.stringify(payload),
            });
        } catch (e) {
            window.alert(this.labelsValue.networkErrorMessage);

            return null;
        }

        if (allow422 && response.status === 422) return 422;
        if (!response.ok) {
            window.alert(this.labelsValue.networkErrorMessage);

            return null;
        }

        return response.json();
    }

    // ---- Navigation clavier ---------------------------------------------------------------

    onGradeKeydown(event, row, input) {
        if (!['Enter', 'ArrowDown', 'ArrowUp'].includes(event.key)) return;
        event.preventDefault();

        const index = this.rows.indexOf(row);
        const next = this.rows[index + (event.key === 'ArrowUp' ? -1 : 1)];
        this.commitGrade(row, input.value).then(() => {
            if (next) this.nodes[next.id]?.input?.focus();
        });
    }

    onRubricKeydown(event, row, question, input) {
        if (!['Enter', 'ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        // Gauche/droite ne prennent la main que sur une case déjà vide ou entièrement
        // sélectionnée, sinon elles doivent rester le déplacement du curseur dans le champ.
        const atEdge = input.selectionStart === input.selectionEnd
            && (event.key !== 'ArrowLeft' || input.selectionStart === 0)
            && (event.key !== 'ArrowRight' || input.selectionStart === input.value.length);
        if (['ArrowLeft', 'ArrowRight'].includes(event.key) && !atEdge) return;
        event.preventDefault();

        const rowIndex = this.rows.indexOf(row);
        const colIndex = this.questions.indexOf(question);
        let nextRow = rowIndex;
        let nextCol = colIndex;
        if (event.key === 'ArrowLeft') nextCol -= 1;
        else if (event.key === 'ArrowRight') nextCol += 1;
        else if (event.key === 'ArrowUp') nextRow -= 1;
        else nextRow += 1;

        this.commitAnswer(row, question, input).then(() => {
            const target = this.rows[nextRow];
            const targetQuestion = this.questions[nextCol];
            if (target && targetQuestion) this.nodes[target.id]?.inputs?.[targetQuestion.id]?.focus();
        });
    }

    // ---- Compteurs de l'en-tête -----------------------------------------------------------

    isEntered(row) {
        return null != row.status || (this.hasRubric && row.value != null);
    }

    refreshCounters() {
        const entered = this.rows.filter((row) => this.isEntered(row)).length;
        this.enteredTarget.textContent = String(entered);
        this.progressTarget.style.width = this.rows.length ? `${Math.round((entered / this.rows.length) * 100)}%` : '0';

        const values = this.rows
            .filter((row) => row.status === 'normal' && row.normalizedValue != null)
            .map((row) => row.normalizedValue);
        this.averageTarget.textContent = values.length
            ? (values.reduce((a, b) => a + b, 0) / values.length).toFixed(2)
            : '—';
    }

    // ---- Commentaire audio ----------------------------------------------------------------

    paintAudio(row) {
        const container = this.nodes[row.id]?.audio;
        if (!container) return;
        container.replaceChildren();

        if (this.recordingRowId === row.id) {
            const stop = this.el('button', 'cm-gb-audio__rec');
            stop.type = 'button';
            stop.tabIndex = -1;
            stop.title = this.labelsValue.stopTitle;
            stop.appendChild(this.el('span', 'cm-gb-audio__dot'));
            stop.append(this.recordingLabel ?? '0:00');
            stop.addEventListener('click', () => this.stopRecording());
            container.appendChild(stop);

            return;
        }

        if (row.hasAudio) {
            const play = this.el('button', 'cm-gb-audio__play');
            play.type = 'button';
            play.tabIndex = -1;
            play.title = this.labelsValue.playTitle;
            play.append('▶');
            play.addEventListener('click', () => this.play(row));
            container.appendChild(play);

            const percent = row.audioListenPercent ?? 0;
            const listened = percent >= 90;
            const badge = this.el(
                'span',
                `cm-gb-audio__listen ${listened ? 'cm-gb-audio__listen--yes' : 'cm-gb-audio__listen--no'}`,
                listened
                    ? this.labelsValue.listenedLabel
                    : (percent > 0 ? this.labelsValue.listenedPercentLabel.replace('%percent%', percent) : this.labelsValue.unlistenedLabel),
            );
            badge.title = this.labelsValue.listenTrackingTitle;
            container.appendChild(badge);
        }

        if (!this.editableValue) return;

        const record = this.el('button', 'cm-gb-audio__record');
        record.type = 'button';
        record.tabIndex = -1;
        record.title = row.hasAudio ? this.labelsValue.againTitle : this.labelsValue.recordTitle;
        record.appendChild(this.icon('M9 2h6v12H9z|M5 10a7 7 0 0 0 14 0M12 17v4', 16));
        record.append(this.labelsValue.audioCommentLabel);
        record.addEventListener('click', () => this.startRecording(row));
        container.appendChild(record);

        if (row.hasAudio) {
            const remove = this.el('button', 'cm-gb-audio__btn cm-gb-audio__btn--danger');
            remove.type = 'button';
            remove.tabIndex = -1;
            remove.title = this.labelsValue.deleteAudioTitle;
            remove.appendChild(this.icon('M3 6h18|M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2|M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6', 12));
            remove.addEventListener('click', () => this.deleteAudio(row));
            container.appendChild(remove);
        }
    }

    async play(row) {
        const url = this.playbackUrlTemplateValue.replace('__STUDENT_ID__', row.id);
        let data;
        try {
            data = await (await fetch(url)).json();
        } catch (e) {
            window.alert(this.labelsValue.networkErrorMessage);

            return;
        }

        this.playerTarget.src = data.url;
        this.playerTarget.play();
    }

    pickMime() {
        const candidates = ['audio/webm;codecs=opus', 'audio/ogg;codecs=opus', 'audio/webm'];
        if (window.MediaRecorder) {
            for (const mime of candidates) {
                if (MediaRecorder.isTypeSupported(mime)) return mime;
            }
        }

        return '';
    }

    async startRecording(row) {
        if (this.recordingRowId) this.stopRecording();

        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch (e) {
            this.micWarningTarget.textContent = this.labelsValue.micDeniedMessage;
            this.micWarningTarget.hidden = false;

            return;
        }

        this.micWarningTarget.hidden = true;
        const mime = this.pickMime();
        // ~24 kbps mono : le débit demandé par le handoff pour une appréciation parlée.
        this.mediaRecorder = new MediaRecorder(this.stream, mime ? { mimeType: mime, audioBitsPerSecond: 24000 } : { audioBitsPerSecond: 24000 });
        this.chunks = [];
        this.mediaRecorder.ondataavailable = (event) => { if (event.data && event.data.size) this.chunks.push(event.data); };
        this.mediaRecorder.onstop = () => this.uploadRecording(row, new Blob(this.chunks, { type: mime || 'audio/webm' }));
        this.mediaRecorder.start();

        this.recordingRowId = row.id;
        this.recordingSeconds = 0;
        this.recordingLabel = '0:00';
        this.recordingTimer = window.setInterval(() => {
            this.recordingSeconds += 1;
            this.recordingLabel = `${Math.floor(this.recordingSeconds / 60)}:${String(this.recordingSeconds % 60).padStart(2, '0')}`;
            this.paintAudio(row);
        }, 1000);
        this.paintAudio(row);
    }

    stopRecording() {
        window.clearInterval(this.recordingTimer);
        this.mediaRecorder?.stop();
    }

    stopStream() {
        window.clearInterval(this.recordingTimer);
        if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') this.mediaRecorder.stop();
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
    }

    // The recording is posted to the app, which writes it to S3 itself
    // (App\Service\GradeAudioCommentUploadService). It used to go straight to the bucket through a
    // presigned PUT, which never left the browser: a cross-origin PUT requires a CORS rule on the
    // bucket, so the request was refused at the preflight and the teacher only saw "not saved".
    // Same origin, one call, nothing to configure on the AWS side.
    async uploadRecording(row, blob) {
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
        this.recordingRowId = null;

        try {
            const uploadUrl = this.uploadAudioUrlTemplateValue.replace('__STUDENT_ID__', row.id);
            const payload = new FormData();
            payload.append('audio', blob, 'commentaire.webm');

            const response = await fetch(uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.csrfTokenValue },
                body: payload,
            });
            if (!response.ok) throw new Error('audio upload failed');
        } catch (e) {
            window.alert(this.labelsValue.networkErrorMessage);
            this.paintAudio(row);

            return;
        }

        row.hasAudio = true;
        row.audioListenPercent = 0;
        this.paintAudio(row);
    }

    async deleteAudio(row) {
        if (!window.confirm(this.labelsValue.deleteConfirmMessage)) return;

        const url = this.deleteAudioUrlTemplateValue.replace('__STUDENT_ID__', row.id);
        try {
            const response = await fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': this.csrfTokenValue } });
            if (!response.ok) throw new Error('delete failed');
        } catch (e) {
            window.alert(this.labelsValue.networkErrorMessage);

            return;
        }

        row.hasAudio = false;
        row.audioListenPercent = null;
        this.paintAudio(row);
    }

    // ---- Utilitaires ----------------------------------------------------------------------

    // Arrondi : additionner des quarts de point en virgule flottante donne vite 20.000000000000004.
    rubricTotalPoints() {
        return Math.round(this.questions.reduce((sum, question) => sum + question.maxPoints, 0) * 100) / 100;
    }

    answerDisplay(value) {
        if (value === 'nt') return 'nt';

        return value == null ? '' : String(value);
    }

    rawValueFor(row) {
        if (row.status === 'absent') return 'abs';
        if (row.status === 'not_evaluated') return 'ne';
        if (row.status === 'not_tested') return 'nt';
        if (row.value == null) return '';

        return row.status === 'excluded' ? `(${this.formatGrade(row.value)})` : String(this.formatGrade(row.value));
    }

    // Deux décimales au plus, sans zéro inutile : 12 → « 12 », 12,5 → « 12.5 », 12,25 → « 12.25 ».
    // Arrondir au dixième rendait le quart de point insaisissable : le champ se réaffichait à 12.3
    // et c'est cette valeur-là qui repartait au serveur au blur suivant.
    formatGrade(value) {
        return String(Math.round(value * 100) / 100);
    }

    el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text != null) node.textContent = text;

        return node;
    }

    spacer(width) {
        const node = this.el('span');
        node.style.width = `${width}px`;
        node.style.flex = 'none';

        return node;
    }

    icon(paths, size) {
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('width', size);
        svg.setAttribute('height', size);
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        for (const d of paths.split('|')) {
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', d);
            svg.appendChild(path);
        }

        return svg;
    }
}
