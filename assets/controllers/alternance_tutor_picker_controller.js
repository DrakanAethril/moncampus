import { Controller } from '@hotwired/stimulus';

// Copies the tom-select-ajax-picked existing tutor's User id (see
// InternshipTutorLinkRepository::searchDistinctTutors()) into InternshipTutorFieldsType's unmapped
// hidden `existingTutorId` field, which App\Service\InternshipTutorFormResolver turns into the
// link's own $tutor server-side. Also pre-selects section 3's Entreprise dropdown from the
// picked option's own enterpriseId payload field ("l'entreprise est reprise automatiquement",
// 32a) and pokes enterprise-picker so its new-enterprise fields fold away - the user keeps the
// mockup's "Changer d'entreprise" affordance simply by changing that dropdown again.
export default class extends Controller {
    static targets = ['hidden'];

    pick(event) {
        this.hiddenTarget.value = event.target.value;

        const tomSelect = event.target.tomselect;
        const option = tomSelect ? tomSelect.options[event.target.value] : null;
        if (!option || !option.enterpriseId) {
            return;
        }

        const enterpriseSelect = document.querySelector('[data-enterprise-picker-target="select"]');
        if (enterpriseSelect) {
            enterpriseSelect.value = String(option.enterpriseId);
            enterpriseSelect.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
}
