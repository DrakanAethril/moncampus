<?php

namespace App\Form;

use App\Entity\Group;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Backs directory/user_form.html.twig in edit mode (App\Controller\DirectoryUserController::edit());
// create mode uses the separate App\Form\LdapManageUserType. Only holds fields staff can actually
// change here - identifiant/prénom/nom/type/groupes d'annuaire are LDAP-owned and shown read-only
// straight off the entity in the template, not routed through this form at all (per the design
// handoff: they "ne font pas partie du formulaire soumis", not just disabled client-side).
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
            // here - not every mirrored LDAP group, and not inactive ones - and never one already
            // granted via the annuaire, so a group can never be both inherited and manually
            // attributed at once (see design/design_handoff_utilisateurs/README.md rule 4).
            // choice_value: 'name' so the template's chip loop can match children by group name,
            // the same technique LdapManageUserType's userGroups field uses.
            ->add('manualGroups', EntityType::class, [
                'class' => Group::class,
                'query_builder' => static function (EntityRepository $er) use ($options) {
                    $qb = $er->createQueryBuilder('g')
                        ->where('g.manuallyAssignable = true')
                        ->andWhere('g.inactiveDate IS NULL')
                        ->orderBy('g.name', 'ASC');

                    if ([] !== $options['adGroupNames']) {
                        $qb->andWhere('g.name NOT IN (:adGroupNames)')->setParameter('adGroupNames', $options['adGroupNames']);
                    }

                    return $qb;
                },
                'choice_value' => 'name',
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
            // App-level, not LDAP: a test account only ever sees test formations, and sees them
            // on the screens that hide them from everyone else. Off unless staff say otherwise.
            ->add('testUser', CheckboxType::class, [
                'label' => 'testUserFieldLabel',
                'help' => 'testUserFieldHelp',
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
        // The edited User's LDAP-inherited group names (App\Entity\User::getLdapRoles() resolved
        // to Group names by the controller) - see manualGroups' query_builder above.
        $resolver->setRequired(['adGroupNames']);
        $resolver->setAllowedTypes('adGroupNames', 'array');
    }
}
