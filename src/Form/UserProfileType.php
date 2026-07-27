<?php

namespace App\Form;

use App\Entity\Group;
use App\Entity\LdapManageUser;
use App\Entity\User;
use App\Repository\GroupRepository;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Backs directory/user_form.html.twig in both its create mode (App\Form\LdapManageUserType,
// data_class LdapManageUser) and edit mode (this class, data_class User) - see
// App\Controller\DirectoryUserController::edit(). firstname/lastname/userType/userGroups are
// rendered here too (field names matching LdapManageUserType's, so the shared template's markup
// doesn't need to know which form type it's holding) but only for read-only display: they're
// disabled, so Symfony's form component never writes submitted data back onto them regardless of
// what a tampered request might send - LDAP stays the sole owner of those, exactly as before.
class UserProfileType extends AbstractType
{
    public function __construct(
        private readonly GroupRepository $groupRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', TextType::class, [
                'label' => 'userFirstnameFieldLabel',
                'disabled' => true,
            ])
            ->add('lastname', TextType::class, [
                'label' => 'userLastnameFieldLabel',
                'disabled' => true,
            ])
            ->add('userType', ChoiceType::class, [
                'label' => 'userTypeColumnLabel',
                'choice_translation_domain' => false,
                'choices' => array_combine(LdapManageUser::USER_TYPES, LdapManageUser::USER_TYPES),
                'mapped' => false,
                'disabled' => true,
                'data' => $options['resolvedType'],
            ])
            ->add('userGroups', ChoiceType::class, [
                'label' => 'userGroupsFieldLabel',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine(
                    LdapManageUserType::availableSecondaryGroups($this->groupRepository),
                    LdapManageUserType::availableSecondaryGroups($this->groupRepository),
                ),
                'mapped' => false,
                'disabled' => true,
                'data' => $options['adGroupNames'],
            ])
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
                'expanded' => true,
                'required' => false,
            ])
            ->add('mustChangePassword', CheckboxType::class, [
                'label' => 'forcePasswordRenewalFieldLabel',
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
        // Computed by the controller from the edited User's live App\Entity\User::getLdapRoles()
        // (not stored anywhere on User itself) - see LdapManageUserRoleResolver::resolveTypeFromRoles()
        // and DirectoryUserController::edit().
        $resolver->setRequired(['resolvedType', 'adGroupNames']);
        $resolver->setAllowedTypes('resolvedType', ['null', 'string']);
        $resolver->setAllowedTypes('adGroupNames', 'array');
    }
}
