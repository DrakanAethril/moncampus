<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enterprise;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\ContractTypeCode;
use App\Repository\EnterpriseRepository;
use App\Service\FormValue;
use App\Service\InternshipTutorFormResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InternshipTutorLinkType extends AbstractType
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
        /** @var Program $program */
        $program = $options['program'];
        $editedLink = $builder->getData();

        $builder
            // Not a form field: "student" is picked via an ajax tom-select field embedded
            // directly in internship_tutor_link_new.html.twig (resolved from a top-level
            // "student" POST field by Program\InternshipTutorController), same convention as
            // LessonSessionType's teacher field - only the program's own students are eligible.
            // Same shared block as the "Créer une alternance" screen - see
            // App\Form\InternshipTutorFieldsType. On an edit it opens on "tuteur existant" with
            // the link's current tutor pre-selected, so leaving it alone is a no-op.
            ->add('tutor', InternshipTutorFieldsType::class)
            // Picking an existing Enterprise here takes priority; the two fields below are only
            // consulted (and only shown, via enterprise_picker_controller.js) when this is left
            // blank - reconciled into InternshipTutorLink::$enterprise by the SUBMIT listener
            // below, which runs before the entity's own NotNull constraint gets validated.
            ->add('enterprise', EntityType::class, [
                'class' => Enterprise::class,
                'choices' => $this->enterpriseChoices($editedLink instanceof InternshipTutorLink ? $editedLink->getEnterprise() : null),
                'choice_label' => 'name',
                'placeholder' => 'internshipTutorLinkNewEnterprisePlaceholder',
                'required' => false,
                'label' => 'internshipTutorLinkEnterpriseFieldLabel',
            ])
            ->add('newEnterpriseName', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'internshipTutorLinkNewEnterpriseNameFieldLabel',
            ])
            ->add('newEnterpriseAddress', TextareaType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'internshipTutorLinkNewEnterpriseAddressFieldLabel',
            ])
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
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

        // Must run before ValidationListener's POST_SUBMIT (priority -4096) so the entity-level
        // NotNull constraint on $enterprise sees the resolved value, not the still-null one an
        // EntityType placeholder selection maps to - see "How to Dynamically Modify Forms Using
        // Form Events" in the Symfony docs for this SUBMIT-listener-before-validation pattern.
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            /** @var InternshipTutorLink $tutorLink */
            $tutorLink = $event->getData();

            $carriedEnterprise = $this->tutorResolver->resolve($event->getForm()->get('tutor'), $tutorLink);

            if (null === $tutorLink->getEnterprise() && null !== $carriedEnterprise) {
                $tutorLink->setEnterprise($carriedEnterprise);

                return;
            }

            if (null !== $tutorLink->getEnterprise()) {
                return;
            }

            $newEnterpriseName = FormValue::trimmed($event->getForm(), 'newEnterpriseName');

            if ('' === $newEnterpriseName) {
                return;
            }

            $enterprise = new Enterprise($newEnterpriseName, $event->getForm()->get('newEnterpriseAddress')->getData() ?: null);
            /** @var User $currentUser */
            $currentUser = $this->security->getUser();
            $enterprise->setCreatedBy($currentUser);

            $this->entityManager->persist($enterprise);
            $tutorLink->setEnterprise($enterprise);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => InternshipTutorLink::class])
            ->setRequired('program')
            ->setAllowedTypes('program', Program::class)
        ;
    }

    /**
     * Test-scoped employer list (see EnterpriseRepository::findAllActiveOrderedByName()) that
     * always keeps the link's own current Enterprise, whichever side of the fence it sits on.
     * Unlike the creation form this one edits an existing row: a test account opening an
     * alternance whose employer is a real company would otherwise submit "This value is not
     * valid" on a field it never touched.
     *
     * @return list<Enterprise>
     */
    private function enterpriseChoices(?Enterprise $current): array
    {
        $user = $this->security->getUser();
        $enterprises = $this->enterpriseRepository->findAllActiveOrderedByName($user instanceof User ? $user : null);

        if (null !== $current && !\in_array($current, $enterprises, true)) {
            $enterprises[] = $current;
            usort($enterprises, static fn (Enterprise $a, Enterprise $b): int => $a->getName() <=> $b->getName());
        }

        return $enterprises;
    }
}
