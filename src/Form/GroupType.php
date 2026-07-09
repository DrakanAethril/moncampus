<?php

namespace App\Form;

use App\Entity\Group;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Shared by both "add a local group" and "edit an existing group" (local or LDAP-mirrored) -
// name/role stay editable only for a local group (isLdapSynced option); an LDAP-mirrored group's
// name/role/ldapCn are LDAP-owned, so only manuallyAssignable is ever editable for those.
class GroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isLdapSynced = $options['isLdapSynced'];

        $builder
            ->add('name', TextType::class, [
                'label' => 'groupNameColumnLabel',
                'disabled' => $isLdapSynced,
                'empty_data' => '',
            ])
            ->add('role', TextType::class, [
                'label' => 'groupRoleFieldLabel',
                'disabled' => $isLdapSynced,
                'help' => $isLdapSynced ? null : 'groupRoleFieldHelp',
                'empty_data' => '',
            ])
            ->add('manuallyAssignable', CheckboxType::class, [
                'label' => 'groupManuallyAssignableFieldLabel',
                'required' => false,
                'disabled' => !$isLdapSynced,
                'help' => $isLdapSynced ? 'groupManuallyAssignableFieldHelp' : null,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => Group::class,
                // Group's constructor requires a name and role, so a fresh entity can't be built
                // via plain reflection - construct it here once those fields are submitted. Only
                // relevant for a new local group (isLdapSynced groups always come from
                // SettingsGroupsController already loaded, never built via this closure).
                'empty_data' => static function (FormInterface $form): Group {
                    return new Group(
                        $form->get('name')->getData() ?? '',
                        $form->get('role')->getData() ?? '',
                        null,
                        true,
                    );
                },
            ])
            ->setRequired('isLdapSynced')
            ->setAllowedTypes('isLdapSynced', 'bool')
        ;
    }
}
