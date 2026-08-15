<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Group;
use App\Entity\GroupType as GroupTypeEntity;
use App\Repository\GroupRepository;
use App\Repository\GroupTypeRepository;
use App\Service\GroupHierarchy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
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
    public function __construct(
        private readonly GroupTypeRepository $groupTypeRepository,
        private readonly GroupRepository $groupRepository,
        private readonly GroupHierarchy $hierarchy,
    ) {
    }

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
            // Purely a display grouping (see GroupType entity's own docblock) - orthogonal to
            // isLdapSynced, so unlike name/role/manuallyAssignable above this is always editable
            // regardless of where the group itself comes from.
            ->add('groupType', EntityType::class, [
                'class' => GroupTypeEntity::class,
                'choices' => $this->groupTypeRepository->findAllActive(),
                'choice_label' => static fn (GroupTypeEntity $groupType): string => $groupType->getName(),
                'label' => 'groupTypeFieldLabel',
                'placeholder' => 'groupTypeNonePlaceholder',
                'required' => false,
                // The select's own value is the GroupType id, which is what the filter compares
                // each parent option's data-group-type against.
                'attr' => [
                    'data-group-parent-filter-target' => 'type',
                    'data-action' => 'change->group-parent-filter#refresh',
                ],
            ])
            // Optional, and at most one: the group this one sits inside (see Group::$parent).
            // Every active group is offered except this one and its own branch - what makes a
            // choice invalid beyond that is its *type*, which is editable in the same submit, so
            // the rule can only be settled server-side (Group::validateParent()). The picker still
            // marks each option with its type id so group_parent_filter_controller.js can grey out
            // the same-type ones as soon as the type dropdown changes, rather than letting the form
            // come back with an error for something the screen already knew.
            ->add('parent', EntityType::class, [
                'class' => Group::class,
                'choices' => $this->parentChoices($builder->getData()),
                'choice_label' => static fn (Group $group): string => $group->getName(),
                'choice_attr' => static fn (Group $group): array => [
                    'data-group-type' => (string) $group->getGroupType()?->getId(),
                ],
                'group_by' => static fn (Group $group): string => $group->getGroupType()?->getName() ?? 'groupTypeOthersLabel',
                'label' => 'groupParentFieldLabel',
                'help' => 'groupParentFieldHelp',
                'placeholder' => 'groupParentNonePlaceholder',
                'required' => false,
                'attr' => ['data-group-parent-filter-target' => 'parent'],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    /**
     * A group cannot hang off itself, nor off anything already below it - that would close a loop
     * and leave a branch nothing can reach. Everything else active is offered, including groups of
     * the same type, which Group::validateParent() refuses on submit.
     *
     * @return list<Group>
     */
    private function parentChoices(mixed $group): array
    {
        $candidates = $this->groupRepository->findActiveOrderedByType();

        if (!$group instanceof Group || null === $group->getId()) {
            return $candidates;
        }

        $excluded = $this->hierarchy->branchIds($group->getId(), $this->groupRepository->findParentMap());

        return array_values(array_filter(
            $candidates,
            static fn (Group $candidate): bool => !\in_array($candidate->getId(), $excluded, true),
        ));
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
