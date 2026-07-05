<?php

namespace App\Form;

use App\Entity\LdapManageUser;
use App\Repository\LdapManageGroupRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LdapManageUserType extends AbstractType
{
    public function __construct(
        private readonly LdapManageGroupRepository $groupRepository,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', TextType::class, [
                'label' => 'userFirstnameFieldLabel',
            ])
            ->add('lastname', TextType::class, [
                'label' => 'userLastnameFieldLabel',
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
     * Secondary groups can't include "admin" (not grantable through this form) or any of the
     * userType choices (those are primary groups, assigned separately - see create_user.sh).
     *
     * @return list<string>
     */
    private function availableSecondaryGroups(): array
    {
        $excluded = ['admin', ...LdapManageUser::USER_TYPES];

        return array_values(array_diff($this->groupRepository->findAllNames(), $excluded));
    }
}
