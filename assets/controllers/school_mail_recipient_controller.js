import { Controller } from '@hotwired/stimulus';

/*
 * Links the recipient address to a company as the "To" field is typed
 * (design_handoff_stage_alternance, screen 3g, which lives inside the 3d compose form).
 *
 * This controller decides nothing: it queries a read-only route and shows which of the three cases
 * applies. The decision is taken again server-side at send time, from the address actually
 * submitted - what is displayed here is never taken at face value.
 *
 * The send button stays disabled until a company is settled: linking is blocking (handoff
 * principle #4).
 */
export default class extends Controller {
    static targets = [
        'address', 'idle', 'linked', 'linkedName', 'linkedKind', 'confirm', 'confirmAddress',
        'confirmDomain', 'confirmName', 'create', 'createText', 'enterpriseId', 'enterpriseName',
        'genericNote', 'submit', 'files', 'fileList',
    ];

    static values = { checkUrl: String };

    connect() {
        this.resolution = null;
        this.check();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    check() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.resolve(), 300);
    }

    async resolve() {
        const address = this.addressTarget.value.trim();

        if (!address.includes('@')) {
            this.resolution = null;
            this.render('idle');

            return;
        }

        try {
            const response = await fetch(`${this.checkUrlValue}?address=${encodeURIComponent(address)}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                this.render('idle');

                return;
            }

            this.resolution = { ...(await response.json()), address };
            this.apply();
        } catch (error) {
            this.render('idle');
        }
    }

    apply() {
        const data = this.resolution;

        if (!data || data.case === 'invalid') {
            this.render('idle');

            return;
        }

        if (data.case === 'linked') {
            this.enterpriseIdTarget.value = data.enterpriseId ?? '';
            this.linkedNameTarget.textContent = data.enterpriseName ?? '';
            this.linkedKindTarget.textContent = this.linkedKindTarget.dataset.autoLabel ?? '';
            this.render('linked');

            return;
        }

        if (data.case === 'confirm') {
            this.enterpriseIdTarget.value = '';
            this.confirmAddressTarget.textContent = data.address;
            this.confirmDomainTarget.textContent = data.domain;
            this.confirmNameTarget.textContent = data.enterpriseName ?? '';
            this.render('confirm');

            return;
        }

        this.enterpriseIdTarget.value = '';
        this.createTextTarget.textContent = (this.createTextTarget.dataset.template ?? '').replace('%address%', data.address);
        this.genericNoteTarget.textContent = data.generic ? (this.genericNoteTarget.dataset.genericLabel ?? '') : '';
        this.render('create');
    }

    /** "Yes, link it": the suggested company becomes the application's company. */
    acceptConfirm() {
        this.enterpriseIdTarget.value = this.resolution?.enterpriseId ?? '';
        this.linkedNameTarget.textContent = this.resolution?.enterpriseName ?? '';
        this.linkedKindTarget.textContent = this.linkedKindTarget.dataset.confirmedLabel ?? '';
        this.render('linked');
    }

    /** "No, it's another company": switch over to creation. */
    refuseConfirm() {
        this.enterpriseIdTarget.value = '';
        this.createTextTarget.textContent = (this.createTextTarget.dataset.template ?? '').replace('%address%', this.resolution?.address ?? '');
        this.genericNoteTarget.textContent = '';
        this.render('create');
        this.enterpriseNameTarget.focus();
    }

    refresh() {
        this.updateSubmit();
    }

    showFiles() {
        const names = Array.from(this.filesTarget.files ?? []).map((file) => file.name);
        this.fileListTarget.textContent = names.join(' · ');
    }

    render(state) {
        this.idleTarget.hidden = state !== 'idle';
        // The case blocks carry their own display: they are revealed through a class, the hidden
        // attribute being powerless against Bootstrap's !important utilities.
        this.linkedTarget.classList.toggle('is-shown', state === 'linked');
        this.confirmTarget.classList.toggle('is-shown', state === 'confirm');
        this.createTarget.classList.toggle('is-shown', state === 'create');
        this.state = state;
        this.updateSubmit();
    }

    updateSubmit() {
        const resolved = ('linked' === this.state && '' !== this.enterpriseIdTarget.value)
            || ('create' === this.state && '' !== this.enterpriseNameTarget.value.trim());

        this.submitTarget.disabled = !resolved;
    }
}
