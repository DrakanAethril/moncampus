<?php

namespace App\Form;

use App\Entity\LdapManageUser;
use App\Repository\GroupRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LdapManageUserType extends AbstractType
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
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('lastname', TextType::class, [
                'label' => 'userLastnameFieldLabel',
                'empty_data' => '',
            ])
            // Not mapped onto LdapManageUser (this queue entity has no such column) - read
            // directly off the form by App\Controller\DirectoryUserController::new() and applied
            // to the User row it creates alongside this queue entry. Optional: many accounts
            // (e.g. students) have no personal address worth collecting up front.
            ->add('contactEmail', EmailType::class, [
                'label' => 'userContactEmailFieldLabel',
                'required' => false,
                'mapped' => false,
            ])
            ->add('userType', ChoiceType::class, [
                'label' => 'userTypeColumnLabel',
                'placeholder' => 'userTypePlaceholder',
                'choice_translation_domain' => false,
                'choices' => array_combine(LdapManageUser::USER_TYPES, LdapManageUser::USER_TYPES),
            ])
            ->add('userGroups', ChoiceType::class, [
                'label' => 'userGroupsFieldLabel',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine($this->availableSecondaryGroups(), $this->availableSecondaryGroups()),
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;

        $builder->get('userGroups')->addModelTransformer(new CallbackTransformer(
            static fn (string $groupsAsString): array => array_values(array_filter(explode('|', $groupsAsString))),
            static fn (?array $groupsAsArray): string => implode('|', $groupsAsArray ?? []),
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LdapManageUser::class,
        ]);
    }

    /**
     * Secondary groups can't include "admin" (not grantable through this form, see
     * LdapManageUserRoleResolver's ROLE_ADMIN strip) or any of the userType choices (those are
     * primary groups, assigned separately - see create_user.sh) - both real LDAP groups that DO
     * get mirrored into App\Entity\Group like any other (see LdapUserMapper::mirrorGroup()), so
     * they must be excluded explicitly rather than assumed absent. Sourced from App\Entity\Group
     * (LDAP-mirrored or local-only alike) rather than the ldap_manage_group request queue - see
     * GroupRepository::findAllActiveGroupedByType()'s docblock - so the template can render this
     * same list as chips grouped by GroupType (see directory/user_new.html.twig); this method
     * only needs the flat list for the choice constraint, built via the exact same excluded-names
     * call as DirectoryUserController::new() passes for the template's buckets, so the two can
     * never drift apart.
     *
     * @return list<string>
     */
    private function availableSecondaryGroups(): array
    {
        $names = [];

        foreach ($this->groupRepository->findAllActiveGroupedByType(self::excludedGroupNames()) as $bucket) {
            foreach ($bucket['groups'] as $group) {
                $names[] = $group->getName();
            }
        }

        return $names;
    }

    /** @return list<string> */
    public static function excludedGroupNames(): array
    {
        return ['admin', ...LdapManageUser::USER_TYPES];
    }
}
