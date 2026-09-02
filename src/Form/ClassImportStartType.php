<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Program;
use App\Repository\GroupRepository;
use App\Service\UploadPolicy;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\When;

/**
 * Step ① of the class import: where the students go, which directory groups their accounts get, and
 * the file itself.
 *
 * The destination comes before the file because everything else depends on it - the values a free
 * column may carry are the options and modalities of that very class. No entity behind the form:
 * nothing is created until the analysis it produces has been confirmed.
 *
 * The account type is deliberately not a field. This is a class import, not a user import.
 */
class ClassImportStartType extends AbstractType
{
    public const string MAX_SIZE = '2M';

    public function __construct(private readonly GroupRepository $groupRepository)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $groupNames = LdapManageUserType::availableSecondaryGroups($this->groupRepository);

        $builder
            ->add('program', EntityType::class, [
                'label' => 'classImportProgramFieldLabel',
                'help' => 'classImportProgramFieldHelpText',
                'class' => Program::class,
                // Input, not consultation: an import lands in whichever class is on screen, so the
                // field must never open on one nobody chose.
                'placeholder' => 'classImportProgramPlaceholder',
                'choice_label' => static fn (Program $program): string => $program->getDisplayShortName(),
                'group_by' => static fn (Program $program): string => self::schoolYearLabel($program),
                // Read by class_import_groups_controller.js, which ticks the directory groups the
                // chosen class hangs off. A default, not a rule: the operator stays free to change
                // them, and nothing is ever derived from the file's own option columns.
                'choice_attr' => static fn (Program $program): array => [
                    'data-directory-groups' => implode('|', self::directoryGroupsOf($program)),
                ],
                'query_builder' => static fn (EntityRepository $repository) => $repository->createQueryBuilder('p')
                    ->leftJoin('p.schoolYear', 'y')->addSelect('y')
                    ->leftJoin('p.cohort', 'c')->addSelect('c')
                    ->leftJoin('c.ldapGroup', 'cg')->addSelect('cg')
                    ->leftJoin('c.track', 't')->addSelect('t')
                    ->leftJoin('t.ldapGroup', 'tg')->addSelect('tg')
                    ->leftJoin('t.section', 's')->addSelect('s')
                    ->leftJoin('s.ldapGroup', 'sg')->addSelect('sg')
                    ->where('p.inactiveDate IS NULL')
                    ->orderBy('y.startDate', 'DESC')
                    ->addOrderBy('p.shortName', 'ASC'),
                'constraints' => [new NotNull(message: 'classImportProgramRequiredMessage')],
            ])
            ->add('groups', ChoiceType::class, [
                'label' => 'classImportGroupsFieldLabel',
                'help' => 'classImportGroupsFieldHelpText',
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => array_combine($groupNames, $groupNames),
            ])
            ->add('mustChangePassword', CheckboxType::class, [
                'label' => 'forceInitialPasswordFieldLabel',
                'required' => false,
                // Checked by default, as on the one-account screen: a freshly created account should
                // require its holder to set their own password unless somebody says otherwise.
                'data' => true,
            ])
            // Optional, and empty is the ordinary case: left blank, each created account gets the
            // random password the directory script invents, exactly as before. Typed, that one is
            // used instead - for the accounts this import CREATES and no other, which is why the
            // confirmation field is not a courtesy: a typo would be discovered by thirty students
            // at once, in front of a machine that only says « mot de passe incorrect ».
            //
            // Same rule as the self-service change of App\Form\ChangePasswordType - 12 characters,
            // four families - but only when something was typed, hence the When: constraints on a
            // RepeatedType run whether or not the field is filled.
            ->add('initialPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'required' => false,
                'invalid_message' => 'classImportInitialPasswordMismatchMessage',
                'first_options' => [
                    'label' => 'classImportInitialPasswordFieldLabel',
                    'help' => 'classImportInitialPasswordFieldHelpText',
                ],
                'second_options' => ['label' => 'classImportInitialPasswordConfirmationFieldLabel'],
                'constraints' => [
                    new When(
                        expression: 'value !== null and value !== ""',
                        constraints: [
                            new Length(min: 12, minMessage: 'newPasswordTooShortMessage'),
                            new Regex(
                                pattern: '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/',
                                message: 'newPasswordComplexityMessage',
                            ),
                        ],
                    ),
                ],
            ])
            ->add('file', FilePickerType::class, [
                'label' => 'classImportFileFieldLabel',
                'help' => 'classImportFileFieldHelpText',
                // A genuine CSV is guessed as text/plain, which Assert\File's own extension list
                // refuses - the platform's MIME map already spells the three spellings out.
                'policy' => UploadPolicy::spreadsheets()->restrictTo('csv'),
                'max_size' => self::MAX_SIZE,
                // No library tab: a class list imported once is not course material.
                'library' => false,
                'constraints' => [new NotNull(message: 'classImportFileRequiredMessage')],
            ])
        ;
    }

    /**
     * The directory groups a class hangs off: its own, its track's and its section's, whichever of
     * the three are mirrored into LDAP.
     *
     * @return list<string>
     */
    public static function directoryGroupsOf(Program $program): array
    {
        $cohort = $program->getCohort();
        $track = $cohort?->getTrack();
        $section = $track?->getSection();

        $names = [];
        foreach ([$cohort, $track, $section] as $node) {
            $name = $node?->getLdapGroup()?->getName();
            if (null !== $name && '' !== $name && !\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function schoolYearLabel(Program $program): string
    {
        $schoolYear = $program->getSchoolYear();
        if (null === $schoolYear) {
            return '';
        }

        return $schoolYear->getStartDate()?->format('Y').'-'.$schoolYear->getEndDate()?->format('Y');
    }
}
