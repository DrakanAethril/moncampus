<?php

namespace App\Form;

use App\Entity\Group;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Edits only User's local-only fields (App\Controller\UserManagementController) - username/
// email/firstname/lastname/roles stay LDAP-owned and aren't exposed here at all.
class UserProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contactEmail', EmailType::class, [
                'label' => 'userContactEmailFieldLabel',
                'required' => false,
            ])
            ->add('phoneNumber', TelType::class, [
                'label' => 'userPhoneNumberFieldLabel',
                'required' => false,
            ])
            // Only groups staff opted into manual assignment (Settings > Groups) are offered
            // here - not every mirrored LDAP group, and not inactive ones.
            ->add('manualGroups', EntityType::class, [
                'class' => Group::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('g')
                    ->where('g.manuallyAssignable = true')
                    ->andWhere('g.inactiveDate IS NULL')
                    ->orderBy('g.name', 'ASC'),
                'choice_label' => 'name',
                'label' => 'userManualGroupsFieldLabel',
                'multiple' => true,
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
