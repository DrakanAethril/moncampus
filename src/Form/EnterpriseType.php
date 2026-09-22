<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enterprise;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The employer's own details, edited from its fiche (« UFA > Entreprises »).
 *
 * Only what the company *is* - the alternances it carries, and who they name, are facts of the
 * contracts and are edited there. `testEnterprise` is not offered either: what marks an employer as
 * fake is the submission that created it (see InternshipAlternanceType), and turning a real company
 * into a test one from here would hide it from every real account at once.
 *
 * The name is the only required field, as it is on every screen that creates an employer: a
 * contract arrives with a company name long before anyone knows its SIRET.
 */
class EnterpriseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'ufaEnterpriseNameFieldLabel'])
            ->add('siret', TextType::class, ['label' => 'ufaEnterpriseSiretFieldLabel', 'required' => false])
            ->add('phone', TelType::class, ['label' => 'ufaEnterprisePhoneFieldLabel', 'required' => false])
            ->add('city', TextType::class, ['label' => 'ufaEnterpriseCityFieldLabel', 'required' => false])
            ->add('address', TextareaType::class, [
                'label' => 'ufaEnterpriseAddressFieldLabel',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('submit', SubmitType::class, ['label' => 'submitSaveAction'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Enterprise::class]);
    }
}
