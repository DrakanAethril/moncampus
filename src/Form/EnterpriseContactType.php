<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\EnterpriseContact;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** A declared contact of a company of the vivier - typed by an administrator. */
class EnterpriseContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'enterpriseContactNameFieldLabel', 'empty_data' => ''])
            ->add('jobTitle', TextType::class, ['label' => 'enterpriseContactJobTitleFieldLabel', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'enterpriseContactEmailFieldLabel', 'required' => false])
            ->add('phone', TextType::class, ['label' => 'enterpriseContactPhoneFieldLabel', 'required' => false])
            ->add('note', TextType::class, ['label' => 'enterpriseContactNoteFieldLabel', 'required' => false])
            ->add('shareableWithStudents', CheckboxType::class, [
                'label' => 'enterpriseContactShareableFieldLabel',
                'help' => 'enterpriseContactShareableFieldHelp',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EnterpriseContact::class]);
    }
}
