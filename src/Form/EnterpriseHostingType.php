<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enterprise;
use App\Entity\EnterpriseContact;
use App\Entity\EnterpriseHosting;
use App\Entity\Option;
use App\Entity\Track;
use App\Enum\HostingKind;
use App\Repository\EnterpriseContactRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * « Ajouter un accueil » (design/validated/vivier-entreprises.md §7.1). The type is a choice with
 * **no default** (D2: guessing is the one thing not allowed); the student is an account, picked by
 * the tom-select the template draws, or a name for somebody who has none; the tutor is a contact
 * already declared for the company or a new one typed here.
 */
class EnterpriseHostingType extends AbstractType
{
    /** The history starts here (vivier spec, Q6). */
    public const int FIRST_YEAR = 2010;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Enterprise $enterprise */
        $enterprise = $options['enterprise'];
        $currentYear = (int) date('n') >= 8 ? (int) date('Y') : (int) date('Y') - 1;
        $years = [];
        for ($year = $currentYear; $year >= self::FIRST_YEAR; --$year) {
            $years[$year.'-'.($year + 1)] = $year;
        }

        $builder
            ->add('kind', EnumType::class, [
                'class' => HostingKind::class,
                'choice_label' => static fn (HostingKind $kind): string => $kind->labelKey(),
                'expanded' => true,
                'label' => 'enterpriseHostingKindFieldLabel',
                'placeholder' => false,
            ])
            ->add('yearStart', ChoiceType::class, [
                'choices' => $years,
                'label' => 'enterpriseHostingYearFieldLabel',
            ])
            ->add('track', EntityType::class, [
                'class' => Track::class,
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('t')->orderBy('t.name', 'ASC'),
                'label' => 'enterpriseHostingTrackFieldLabel',
                'placeholder' => 'enterpriseHostingTrackPlaceholder',
            ])
            ->add('option', EntityType::class, [
                'class' => Option::class,
                'choice_label' => 'name',
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('o')->orderBy('o.name', 'ASC'),
                'label' => 'enterpriseHostingOptionFieldLabel',
                'placeholder' => 'enterpriseHostingOptionPlaceholder',
                'required' => false,
            ])
            ->add('studentName', TextType::class, [
                'label' => 'enterpriseHostingStudentNameFieldLabel',
                'help' => 'enterpriseHostingStudentNameFieldHelp',
                'required' => false,
            ])
            ->add('startDate', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false, 'label' => 'enterpriseHostingStartDateFieldLabel'])
            ->add('endDate', DateType::class, ['widget' => 'single_text', 'input' => 'datetime_immutable', 'required' => false, 'label' => 'enterpriseHostingEndDateFieldLabel'])
            ->add('missions', TextareaType::class, [
                'label' => 'enterpriseHostingMissionsFieldLabel',
                'required' => false,
                'attr' => ['rows' => 2, 'maxlength' => 500],
            ])
            ->add('contact', EntityType::class, [
                'class' => EnterpriseContact::class,
                'choice_label' => static fn (EnterpriseContact $contact): string => $contact->getName().(null !== $contact->getJobTitle() ? ' — '.$contact->getJobTitle() : ''),
                'query_builder' => static fn (EnterpriseContactRepository $repository) => $repository->createQueryBuilder('c')
                    ->where('c.enterprise = :enterprise')->andWhere('c.inactiveDate IS NULL')
                    ->setParameter('enterprise', $enterprise)->orderBy('c.name', 'ASC'),
                'label' => 'enterpriseHostingContactFieldLabel',
                'placeholder' => 'enterpriseHostingContactPlaceholder',
                'required' => false,
            ])
            ->add('newContactName', TextType::class, ['mapped' => false, 'required' => false, 'label' => 'enterpriseContactNameFieldLabel'])
            ->add('newContactJobTitle', TextType::class, ['mapped' => false, 'required' => false, 'label' => 'enterpriseContactJobTitleFieldLabel'])
            ->add('newContactEmail', EmailType::class, ['mapped' => false, 'required' => false, 'label' => 'enterpriseContactEmailFieldLabel'])
            ->add('newContactPhone', TextType::class, ['mapped' => false, 'required' => false, 'label' => 'enterpriseContactPhoneFieldLabel'])
            ->add('newContactShareable', CheckboxType::class, ['mapped' => false, 'required' => false, 'label' => 'enterpriseContactShareableFieldLabel']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EnterpriseHosting::class]);
        $resolver->setRequired('enterprise');
        $resolver->setAllowedTypes('enterprise', Enterprise::class);
    }
}
