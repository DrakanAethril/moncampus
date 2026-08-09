<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enterprise;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Repository\InternshipTutorLinkRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns App\Form\InternshipTutorFieldsType's raw inputs into the one thing InternshipTutorLink
 * actually stores - a $tutor User - for both forms that embed that block.
 *
 * Lives here rather than in either form type because both need it and because provisioning a
 * tutor account is a domain operation, not form plumbing. Called from the parent form's SUBMIT
 * listener, i.e. before validation, so the entity's Assert\NotNull on $tutor sees the resolved
 * value and a rejected e-mail surfaces as a field-level error on the input that caused it.
 */
class InternshipTutorFormResolver
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly InternshipTutorLinkRepository $tutorLinkRepository,
        private readonly InternshipTutorProvisioningService $provisioningService,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
    ) {
    }

    /**
     * Resolves $tutorForm onto $tutorLink and returns the Enterprise that tutor was last seen at,
     * if any - "l'entreprise est reprise automatiquement" (32a) when an existing tutor is picked.
     * Null whenever nothing was carried over, which includes every new-tutor submission.
     *
     * Leaves $tutor untouched (and adds no error of its own) when the inputs are simply empty:
     * that's the entity's NotNull to report, not this method's.
     */
    public function resolve(FormInterface $tutorForm, InternshipTutorLink $tutorLink): ?Enterprise
    {
        if ('existing' === $tutorForm->get('mode')->getData()) {
            $tutorId = $tutorForm->get('existingTutorId')->getData();
            $tutor = is_numeric($tutorId) ? $this->userRepository->find((int) $tutorId) : null;

            if (null === $tutor) {
                return null;
            }

            $tutorLink->setTutor($tutor);

            return $this->tutorLinkRepository->findMostRecentEnterpriseForTutor($tutor);
        }

        $email = FormValue::trimmed($tutorForm, 'email');
        $firstname = FormValue::trimmed($tutorForm, 'firstname');
        $lastname = FormValue::trimmed($tutorForm, 'lastname');

        if ('' === $email || '' === $firstname || '' === $lastname) {
            return null;
        }

        // User::$contactEmail is unique, and an address already in use could just as well belong to
        // a teacher or a student as to another tutor - so this refuses rather than quietly
        // attaching the alternance to whoever holds it. Staff are pointed at the "tuteur existant"
        // mode, which is the deliberate way to reuse an account.
        if (!$this->provisioningService->isEmailAvailable($email)) {
            $tutorForm->get('email')->addError(new FormError($this->translator->trans('internshipTutorLinkTutorEmailAlreadyUsedMessage')));

            return null;
        }

        /** @var User $currentUser */
        $currentUser = $this->security->getUser();
        $this->provisioningService->provision(
            $tutorLink,
            $firstname,
            $lastname,
            $email,
            FormValue::trimmed($tutorForm, 'phone'),
            $tutorLink->isTestAlternance(),
            $currentUser,
        );

        return null;
    }
}
