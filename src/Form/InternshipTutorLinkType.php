<?php

namespace App\Form;

use App\Entity\Enterprise;
use App\Entity\InternshipTutorLink;
use App\Entity\Program;
use App\Entity\User;
use App\Repository\EnterpriseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Program $program */
        $program = $options['program'];

        $builder
            // Not a form field: "student" is picked via an ajax tom-select field embedded
            // directly in internship_tutor_link_new.html.twig (resolved from a top-level
            // "student" POST field by ProgramInternshipController), same convention as
            // LessonSessionType's teacher field - only the program's own students are eligible.
            ->add('tutorFirstName', TextType::class, [
                'label' => 'internshipTutorLinkTutorFirstNameFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('tutorLastName', TextType::class, [
                'label' => 'internshipTutorLinkTutorLastNameFieldLabel',
                'empty_data' => '',
            ])
            ->add('tutorEmail', TextType::class, [
                'label' => 'internshipTutorLinkTutorEmailFieldLabel',
                'empty_data' => '',
            ])
            ->add('tutorPhone', TelType::class, [
                'label' => 'internshipTutorLinkTutorPhoneFieldLabel',
                'empty_data' => '',
            ])
            // Picking an existing Enterprise here takes priority; the two fields below are only
            // consulted (and only shown, via enterprise_picker_controller.js) when this is left
            // blank - reconciled into InternshipTutorLink::$enterprise by the SUBMIT listener
            // below, which runs before the entity's own NotNull constraint gets validated.
            ->add('enterprise', EntityType::class, [
                'class' => Enterprise::class,
                'choices' => $this->enterpriseRepository->findAllActiveOrderedByName(),
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

            if (null !== $tutorLink->getEnterprise()) {
                return;
            }

            $newEnterpriseName = trim((string) $event->getForm()->get('newEnterpriseName')->getData());

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
}
