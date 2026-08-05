import { Controller } from '@hotwired/stimulus';

/*
 * The live part of the compose form (design_handoff_stage_alternance, screen 3g inside the 3d
 * form): naming the démarche the mail belongs to.
 *
 * This controller decides nothing. It asks a read-only route whether the address being typed was
 * already used in one of this student's démarches and, if so, offers that name - once, and only
 * into a field the student has not filled in themselves. The démarche is settled again server-side
 * from the name actually submitted; what is prefilled here is never taken at face value.
 *
 * The send button stays disabled until the démarche is named: it is blocking (handoff principle #4).
 */
export default class extends Controller {
    static targets = ['address', 'application', 'hint', 'submit', 'files', 'fileList'];

    static values = { checkUrl: String };

    connect() {
        // A name already in the field - a reply, or input handed back by a rejected send - is the
        // student's, not ours to overwrite with a suggestion.
        this.touched = '' !== this.applicationTarget.value.trim();
        this.knownNames = Array.from(this.element.querySelectorAll('#cm-compose-applications option'))
            .map((option) => option.value.trim().toLocaleLowerCase())
            .filter((name) => '' !== name);

        this.refresh();
        this.check();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    check() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.suggest(), 300);
    }

    async suggest() {
        const address = this.addressTarget.value.trim();

        if (this.touched || !address.includes('@')) {
            return;
        }

        try {
            const response = await fetch(`${this.checkUrlValue}?address=${encodeURIComponent(address)}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const { application } = await response.json();

            // Checked again after the await: the student may well have typed a name while the
            // request was in flight, and it wins.
            if (!application || this.touched) {
                return;
            }

            this.applicationTarget.value = application;
            this.render('suggested');
        } catch (error) {
            // A failed suggestion changes nothing: the field stays as it is, and naming the
            // démarche by hand is the normal path anyway.
        }
    }

    /**
     * Called as the démarche field is typed: from here on, the student owns its content - until
     * they empty it again, which hands the field back and lets a suggestion land in it.
     */
    refresh() {
        const name = this.applicationTarget.value.trim();
        this.touched = '' !== name;

        if ('' === name) {
            this.render('idle');

            return;
        }

        this.render(this.knownNames.includes(name.toLocaleLowerCase()) ? 'existing' : 'new');
    }

    showFiles() {
        const names = Array.from(this.filesTarget.files ?? []).map((file) => file.name);
        this.fileListTarget.textContent = names.join(' · ');
    }

    render(state) {
        const labels = {
            idle: this.hintTarget.dataset.idleLabel,
            existing: this.hintTarget.dataset.existingLabel,
            new: this.hintTarget.dataset.newLabel,
            suggested: this.hintTarget.dataset.suggestedLabel,
        };

        this.hintTarget.textContent = labels[state] ?? '';
        this.submitTarget.disabled = '' === this.applicationTarget.value.trim();
    }
}
