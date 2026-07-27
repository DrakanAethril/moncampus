import { Controller } from '@hotwired/stimulus';

/**
 * Live client-side mirror of App\Form\ChangePasswordType's server-side rules - purely a UX aid
 * (instant feedback + submit button gating), the actual enforcement stays server-side
 * (App\Controller\PasswordRenewalController::renewal()). Drives the strength meter, criteria
 * checklist and confirmation match indicator on the forced password renewal screen
 * (security/password_renewal.html.twig).
 */
export default class extends Controller {
    static targets = ['newPasswordInput', 'confirmInput', 'strengthSeg', 'strengthLabel', 'criterion', 'matchIcon', 'submitButton'];
    static values = {
        tooWeakLabel: String,
        mediumLabel: String,
        goodLabel: String,
        strongLabel: String,
    };

    connect() {
        this.update();
    }

    update() {
        const password = this.newPasswordInputTarget.value;
        const rules = {
            length: password.length >= 12,
            upper: /[A-Z]/.test(password),
            lower: /[a-z]/.test(password),
            digit: /\d/.test(password),
            special: /[^A-Za-z0-9]/.test(password),
        };
        const metCount = Object.values(rules).filter(Boolean).length;

        this.criterionTargets.forEach((element) => {
            const met = rules[element.dataset.rule] === true;
            element.classList.toggle('is-met', met);

            // Real text (not a CSS ::before glyph) so aria-live actually has something to announce
            // as criteria become satisfied - see the criteria grid's aria-live="polite" wrapper.
            const dot = element.querySelector('.cm-authcard__criterion-dot');
            if (dot) {
                dot.textContent = met ? '✓' : '•';
            }
        });

        this.updateStrength(metCount, password.length > 0);
        this.updateMatch(password);
        this.updateSubmitState(rules, password);
    }

    updateStrength(metCount, hasValue) {
        if (!this.hasStrengthLabelTarget) {
            return;
        }

        const level = hasValue ? Math.max(1, Math.ceil((metCount / this.criterionTargets.length) * 4)) : 0;
        const colorByLevel = { 1: 'var(--cm-red-tx)', 2: 'var(--cm-warn-tx)', 3: 'var(--cm-positive-tx)', 4: 'var(--cm-positive-tx)' };
        const labelByLevel = { 1: this.tooWeakLabelValue, 2: this.mediumLabelValue, 3: this.goodLabelValue, 4: this.strongLabelValue };

        this.strengthSegTargets.forEach((segment, index) => {
            segment.style.background = index < level ? colorByLevel[level] : '';
        });

        this.strengthLabelTarget.textContent = labelByLevel[level] ?? '';
        this.strengthLabelTarget.style.color = level > 0 ? colorByLevel[level] : '';
    }

    updateMatch(password) {
        if (!this.hasMatchIconTarget || !this.hasConfirmInputTarget) {
            return;
        }

        const confirm = this.confirmInputTarget.value;
        this.matchIconTarget.classList.remove('cm-authcard__match-icon--ok', 'cm-authcard__match-icon--bad');

        if ('' === confirm) {
            this.matchIconTarget.textContent = '';
        } else if (confirm === password) {
            this.matchIconTarget.textContent = '✓';
            this.matchIconTarget.classList.add('cm-authcard__match-icon--ok');
        } else {
            this.matchIconTarget.textContent = '✕';
            this.matchIconTarget.classList.add('cm-authcard__match-icon--bad');
        }
    }

    updateSubmitState(rules, password) {
        if (!this.hasSubmitButtonTarget) {
            return;
        }

        const allRulesMet = Object.values(rules).every(Boolean);
        const confirmMatches = this.hasConfirmInputTarget && '' !== password && this.confirmInputTarget.value === password;

        this.submitButtonTarget.disabled = !(allRulesMet && confirmMatches);
    }
}
