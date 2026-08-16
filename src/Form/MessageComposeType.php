<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MessageThread;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\MessageAudienceType;
use App\Service\UploadPolicy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\NotBlank;

// audienceProgram/audienceAllStudents/audienceAllTeachers/audienceAllStaff/audienceManual
// (design/design_handoff_messagerie) are independent checkboxes, not mutually exclusive: the
// sender can combine several at once ("Formations" + "Tous les personnels" in the same send).
// Each is mapped=false and only added when its MessageAudienceType is in
// $options['allowedAudienceTypes'] for this sender's role - same per-role gating
// App\Service\MessagingAccessChecker::allowedAudienceTypes() already enforces.
//
// One field per audience rather than a single multi-select `audienceTypes` (which is what
// AnnouncementType/AgendaEventType/SignupListType bind straight onto the entity) only because the
// handoff draws these as vignettes: the template needs each checkbox on its own to wrap it in its
// tile. The stored shape is the same set either way - App\Controller\MessageController::compose()
// reads the checked fields back into MessageThread's audience through
// applyComposedAudience(), late-joiner sync (App\Service\MessageThreadRecipientSyncer) included.
//
// Manual recipients are deliberately NOT a form field here, same reasoning as AssignmentType's
// manualRecipients: with potentially hundreds of active users, an EntityType/ChoiceType would
// have to render every choice regardless of which ones get picked. The tom-select ajax widget in
// the template submits a plain `recipients[]` array outside this form's namespace, resolved
// server-side by App\Service\MessagingAccessChecker::resolveManualRecipients(), which only ever
// touches the submitted ids and re-validates each against the permission matrix.
//
// No signup-list field (design/design_handoff_messagerie PROMPT.md #2: "pas de liste
// d'inscription à associer") - MessageThread::$signupList stays reachable only for whatever
// already-sent threads set it before this redesign; templates/messages/show.html.twig still
// renders the badge for those, there's simply no way to set a new one from this form anymore.
//
// When the lockedRecipient option is set (the "reply privately to an announcement's sender" flow -
// see MessageController), the whole audience picker is omitted from the form entirely: the
// controller sets the audience to Manual and the single recipient itself before handling the
// request, so there's no picker to hide/show and nothing here to override that.
class MessageComposeType extends AbstractType
{
    // Field name -> the MessageAudienceType it represents, in the design's fixed vignette order.
    // Public: App\Controller\MessageController reads this same map to turn the checked fields
    // back into MessageAudienceType values, both at submit time and for the live recipient-count
    // preview endpoint - see that class's applyComposedAudience()/recipientCount().
    public const array AUDIENCE_CHECKBOX_FIELDS = [
        'audienceProgram' => MessageAudienceType::Program,
        'audienceAllStudents' => MessageAudienceType::AllStudents,
        'audienceAllTeachers' => MessageAudienceType::AllTeachers,
        'audienceAllStaff' => MessageAudienceType::AllStaff,
        'audienceManual' => MessageAudienceType::Manual,
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', TextType::class, [
                'label' => 'messageSubjectFieldLabel',
            ])
            // Not mapped: there's no MessageThread::$body - the body belongs to the thread's
            // first Message, built by the controller from this field's raw value, same as
            // "attachments" below.
            ->add('body', TextareaType::class, [
                'label' => 'messageBodyFieldLabel',
                'mapped' => false,
                'constraints' => [new NotBlank()],
            ])
        ;

        if (null === $options['lockedRecipient']) {
            /** @var list<MessageAudienceType> $allowedAudienceTypes */
            $allowedAudienceTypes = $options['allowedAudienceTypes'];
            /** @var list<Program> $programs */
            $programs = $options['programs'];

            foreach (self::AUDIENCE_CHECKBOX_FIELDS as $field => $type) {
                if (\in_array($type, $allowedAudienceTypes, true)) {
                    $builder->add($field, CheckboxType::class, [
                        'label' => $type->labelKey(),
                        // None of these correspond to a real MessageThread property - see this
                        // class's docblock, App\Controller\MessageController::
                        // applyComposedAudience() reads them straight off the FormView/submitted
                        // data instead.
                        'mapped' => false,
                        'required' => false,
                    ]);
                }
            }

            $builder
                ->add('programs', EntityType::class, [
                    'class' => Program::class,
                    'choices' => $programs,
                    'choice_label' => static fn (Program $program): string => $program->getDisplayShortName(),
                    'label' => 'messageAudienceProgramsFieldLabel',
                    'multiple' => true,
                    'expanded' => true,
                    'required' => false,
                ])
                ->add('includeStudents', CheckboxType::class, [
                    'label' => 'messageAudienceRoleStudentsLabel',
                    'required' => false,
                ])
                ->add('includeTeachers', CheckboxType::class, [
                    'label' => 'messageAudienceRoleTeachersLabel',
                    'required' => false,
                ])
            ;

            AudienceFormValidation::addProgramAudienceValidation($builder);
        }

        $builder
            ->add('attachments', FilePickerType::class, [
                'label' => 'messageAttachmentsFieldLabel',
                'mapped' => false,
                'multiple' => true,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                // The "documents" narrowing of the platform upload policy - the same twelve types
                // this field used to enumerate, declared once (design/validated/upload-policy.md).
                // The All() a multiple field needs is FilePickerType's business now.
                'policy' => UploadPolicy::documents(),
                // Course material reaches other people through messaging, so the library tab
                // belongs here - it arrives with the library itself (lot 4).
                'library' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'messageSendAction',
            ])
        ;

        $builder->setEmptyData(static fn (): MessageThread => new MessageThread($options['sender']));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['data_class' => MessageThread::class, 'lockedRecipient' => null])
            ->setRequired(['sender', 'allowedAudienceTypes', 'programs'])
            ->setAllowedTypes('sender', User::class)
            ->setAllowedTypes('allowedAudienceTypes', 'array')
            ->setAllowedTypes('programs', 'array')
            ->setAllowedTypes('lockedRecipient', ['null', User::class])
        ;
    }
}
