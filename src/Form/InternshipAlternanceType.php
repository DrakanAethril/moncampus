<?php

namespace App\Form;

use App\Entity\Enterprise;
use App\Entity\InternshipTutorLink;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Repository\EnterpriseRepository;
use App\Repository\InternshipTutorLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
 * time isValid() runs) - see UfaAlternanceController::createAlternance().
 *
 * Sections 2 (Tuteur) and 3 (Entreprise) each toggle independently between an "existing" pick and
 * inline "new" fields, both resolved here in a single FormEvents::SUBMIT listener so validation
 * sees the final, reconciled entity:
 * - Tuteur: $tutorMode drives assets/controllers/tutor_picker_controller.js's radio-panel toggle;
 *   picking an existing tutor (via $existingTutorLinkId, filled by a tom-select ajax search over
 *   InternshipTutorLinkRepository::searchDistinctTutors()) copies that link's tutor fields AND
 *   carries its Enterprise over as the default for section 3 - "l'entreprise est reprise
 *   automatiquement".
 * - Entreprise: reuses the exact existing-vs-new resolution pattern from InternshipTutorLinkType
 *   (blank EntityType selection = create new from the 4 unmapped fields, via
 *   assets/controllers/enterprise_picker_controller.js) - the carried enterprise from step 2 is
 *   just the EntityType field's pre-selected value, "Changer d'entreprise" is that same picker's
 *   existing toggle, no separate server-side "carried" mode is needed.
 */
class InternshipAlternanceType extends AbstractType
{
    public function __construct(
        private readonly EnterpriseRepository $enterpriseRepository,
        private readonly InternshipTutorLinkRepository $tutorLinkRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tutorMode', ChoiceType::class, [
                'mapped' => false,
                'expanded' => true,
                'choices' => ['ufaAlternanceNewTutorExistingLabel' => 'existing', 'ufaAlternanceNewTutorNewLabel' => 'new'],
                'data' => 'existing',
                'label' => false,
            ])
            ->add('existingTutorLinkId', HiddenType::class, ['mapped' => false, 'required' => false])
            ->add('tutorFirstName', TextType::class, ['label' => 'internshipTutorLinkTutorFirstNameFieldLabel', 'required' => false, 'empty_data' => ''])
            ->add('tutorLastName', TextType::class, ['label' => 'internshipTutorLinkTutorLastNameFieldLabel', 'required' => false, 'empty_data' => ''])
            ->add('tutorEmail', TextType::class, ['label' => 'internshipTutorLinkTutorEmailFieldLabel', 'required' => false, 'empty_data' => ''])
            ->add('tutorPhone', TelType::class, ['label' => 'internshipTutorLinkTutorPhoneFieldLabel', 'required' => false, 'empty_data' => ''])
            ->add('enterprise', EntityType::class, [
                'class' => Enterprise::class,
                'choices' => $this->enterpriseRepository->findAllActiveOrderedByName(),
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

            $carriedEnterprise = null;
            if ('existing' === $form->get('tutorMode')->getData()) {
                $existingLinkId = $form->get('existingTutorLinkId')->getData();
                $existingLink = is_numeric($existingLinkId) ? $this->tutorLinkRepository->find((int) $existingLinkId) : null;

                if (null !== $existingLink) {
                    $tutorLink->setTutorFirstName($existingLink->getTutorFirstName());
                    $tutorLink->setTutorLastName($existingLink->getTutorLastName());
                    $tutorLink->setTutorEmail($existingLink->getTutorEmail());
                    $tutorLink->setTutorPhone($existingLink->getTutorPhone());
                    $carriedEnterprise = $existingLink->getEnterprise();
                }
            }

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
}
