<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enterprise;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Repository\EnterpriseRepository;
use App\Service\InternshipTutorFormResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Créer une alternance" (32a/32b) - single submission that creates the InternshipTutorLink plus,
 * as needed, the tutor and the entreprise it points at. The "student"/"formation" side (section 1
 * of the screen) is deliberately NOT part of this form: like InternshipTutorLinkType's own
 * $student field, it's resolved from a raw POST field by the controller before handleRequest()
 * runs (InternshipTutorLink::$student carries an Assert\NotNull, so it must already be set by the
 * time isValid() runs) - see Ufa\AlternanceController::createAlternance().
 *
 * Sections 2 (Tuteur) and 3 (Entreprise) each toggle independently between an "existing" pick and
 * inline "new" fields, both resolved here in a single FormEvents::SUBMIT listener so validation
 * sees the final, reconciled entity:
 * - Tuteur: the shared App\Form\InternshipTutorFieldsType block, resolved into the link's $tutor
 *   User by App\Service\InternshipTutorFormResolver - picking an existing tutor also carries that
 *   tutor's current Enterprise over as the default for section 3 ("l'entreprise est reprise
 *   automatiquement").
 * - Entreprise: reuses the exact existing-vs-new resolution pattern from InternshipTutorLinkType
 *   (blank EntityType selection = create new from the 4 unmapped fields, via
 *   assets/controllers/enterprise_picker_controller.js) - the carried enterprise from step 2 is
 *   just the EntityType field's pre-selected value, "Changer d'entreprise" is that same picker's
 *   existing toggle, no separate server-side "carried" mode is needed.
 *
 * The "alternance de test" box marks the link itself plus everything this submission creates -
 * see InternshipTutorLink::$testAlternance for the full rule and where the tutor half of it lands.
 */
class InternshipAlternanceType extends AbstractType
{
    public function __construct(
        private readonly EnterpriseRepository $enterpriseRepository,
        private readonly InternshipTutorFormResolver $tutorResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tutor', InternshipTutorFieldsType::class)
            ->add('enterprise', EntityType::class, [
                'class' => Enterprise::class,
                'choices' => $this->enterpriseRepository->findAllActiveOrderedByName($this->currentUser()),
                'choice_label' => 'name',
                'placeholder' => 'internshipTutorLinkNewEnterprisePlaceholder',
                'required' => false,
                'label' => 'internshipTutorLinkEnterpriseFieldLabel',
            ])
            ->add('newEnterpriseName', TextType::class, ['mapped' => false, 'required' => false, 'label' => 'internshipTutorLinkNewEnterpriseNameFieldLabel'])
            ->add('newEnterpriseSiret', TextType::class, ['mapped' => false, 'required' => false, 'label' => 'ufaAlternanceNewEnterpriseSiretFieldLabel'])
            ->add('newEnterpriseAddress', TextareaType::class, ['mapped' => false, 'required' => false, 'label' => 'internshipTutorLinkNewEnterpriseAddressFieldLabel'])
            ->add('newEnterprisePhone', TelType::class, ['mapped' => false, 'required' => false, 'label' => 'ufaAlternanceNewEnterprisePhoneFieldLabel'])
            ->add('contractType', EnumType::class, [
                'class' => ContractTypeCode::class,
                'choice_label' => static fn (ContractTypeCode $type): string => $type->labelKey(),
                'label' => 'internshipTutorLinkContractTypeFieldLabel',
            ])
            ->add('contractStartDate', DateType::class, [
                'label' => 'internshipTutorLinkContractStartDateFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            ->add('contractEndDate', DateType::class, [
                'label' => 'internshipTutorLinkContractEndDateFieldLabel',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
            ])
            // Mapped, unlike the other toggles here: the flag is a real column on the link, and
            // the SUBMIT listener below reads it straight off the entity (children are mapped onto
            // it before SUBMIT is dispatched) to stamp a brand new Enterprise with it.
            ->add('testAlternance', CheckboxType::class, [
                'label' => 'ufaAlternanceNewTestFieldLabel',
                'help' => 'ufaAlternanceNewTestFieldHelp',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, ['label' => 'ufaAlternanceNewSubmitLabel'])
        ;

        // Must run before ValidationListener's POST_SUBMIT (priority -4096), same reasoning as
        // InternshipTutorLinkType's own listener - the entity-level constraints on tutor
        // fields/$enterprise must see the fully reconciled data, not whatever
        // handleRequest() mapped straight from the (partially hidden) submitted fields.
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            /** @var InternshipTutorLink $tutorLink */
            $tutorLink = $event->getData();
            $form = $event->getForm();

            // Sets $tutor (picked account, or one provisioned on the spot) and hands back the
            // employer that tutor was last seen at, if any.
            $carriedEnterprise = $this->tutorResolver->resolve($form->get('tutor'), $tutorLink);

            if (null === $tutorLink->getEnterprise() && null !== $carriedEnterprise) {
                $tutorLink->setEnterprise($carriedEnterprise);

                return;
            }

            if (null !== $tutorLink->getEnterprise()) {
                return;
            }

            $newEnterpriseName = trim((string) $form->get('newEnterpriseName')->getData());
            if ('' === $newEnterpriseName) {
                return;
            }

            $enterprise = new Enterprise($newEnterpriseName, $form->get('newEnterpriseAddress')->getData() ?: null);
            $enterprise->setSiret($form->get('newEnterpriseSiret')->getData() ?: null);
            $enterprise->setPhone($form->get('newEnterprisePhone')->getData() ?: null);
            // Only ever on an employer THIS submission creates - the "existing enterprise" branch
            // above returns early, so picking a real company for a test alternance (or carrying
            // one over from an existing tutor) can never re-brand it as fake.
            $enterprise->setTestEnterprise($tutorLink->isTestAlternance());
            /** @var User $currentUser */
            $currentUser = $this->security->getUser();
            $enterprise->setCreatedBy($currentUser);

            $this->entityManager->persist($enterprise);
            $tutorLink->setEnterprise($enterprise);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => InternshipTutorLink::class]);
    }

    private function currentUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
