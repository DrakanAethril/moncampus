<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Room;
use App\Entity\Ticket;
use App\Entity\TicketCategory;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// Handler-only panel updating a subset of Ticket's fields (status/priority/assignee, plus the
// category and location the reporter filled in and handlers routinely have to correct) - the
// rest of the entity (subject, description, reporter...) is left untouched by this form, same
// shape as LaptopLoanReturnType only mapping returnCondition/returnStateNotes onto a LaptopLoan.
class TicketManageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $ticket = $builder->getData();
        $currentCategoryId = $ticket instanceof Ticket ? $ticket->getCategory()?->getId() : null;
        $currentRoomId = $ticket instanceof Ticket ? $ticket->getRoom()?->getId() : null;

        $builder
            ->add('status', ChoiceType::class, [
                'label' => 'ticketStatusFieldLabel',
                'choices' => [
                    'ticketStatusOpenLabel' => Ticket::STATUS_OPEN,
                    'ticketStatusAwaitingInfoLabel' => Ticket::STATUS_AWAITING_INFO,
                    'ticketStatusInProgressLabel' => Ticket::STATUS_IN_PROGRESS,
                    'ticketStatusResolvedLabel' => Ticket::STATUS_RESOLVED,
                    'ticketStatusClosedLabel' => Ticket::STATUS_CLOSED,
                ],
                'placeholder' => false,
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'ticketPriorityFieldLabel',
                'choices' => [
                    'ticketPriorityLowLabel' => Ticket::PRIORITY_LOW,
                    'ticketPriorityMediumLabel' => Ticket::PRIORITY_MEDIUM,
                    'ticketPriorityHighLabel' => Ticket::PRIORITY_HIGH,
                    'ticketPriorityUrgentLabel' => Ticket::PRIORITY_URGENT,
                ],
                'placeholder' => false,
            ])
            // Unlike TicketType's own category/room fields, these two keep the value the ticket
            // already carries in the list even once it has been deactivated: filtering it out
            // would silently blank the field and make the panel refuse a submission that only
            // changed the status, blaming the wrong one.
            ->add('category', EntityType::class, [
                'class' => TicketCategory::class,
                'query_builder' => static fn (EntityRepository $er): QueryBuilder => self::activeOrCurrent($er->createQueryBuilder('c'), 'c', 'name', $currentCategoryId),
                'choice_label' => 'name',
                'label' => 'ticketCategoryFieldLabel',
                'placeholder' => 'ticketCategoryPlaceholder',
            ])
            ->add('room', EntityType::class, [
                'class' => Room::class,
                'query_builder' => static fn (EntityRepository $er): QueryBuilder => self::activeOrCurrent($er->createQueryBuilder('r'), 'r', 'name', $currentRoomId),
                'choice_label' => 'name',
                'label' => 'ticketRoomFieldLabel',
                'required' => false,
                'placeholder' => 'ticketRoomPlaceholder',
            ])
            ->add('otherLocation', TextType::class, [
                'label' => 'ticketOtherLocationFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'ticketManageSubmitAction',
            ])
        ;

        // Not a form field: "assignee" is picked via an ajax tom-select field embedded
        // directly in ticket/show.html.twig (resolved from a top-level "assignee" POST field
        // by TicketController::manageTicket()), same convention as LessonSessionType's
        // teacher field - only active users matching TicketVoter::HANDLER_ROLES are eligible.
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Ticket::class]);
    }

    private static function activeOrCurrent(QueryBuilder $qb, string $alias, string $orderField, ?int $currentId): QueryBuilder
    {
        $qb->where(sprintf('%s.inactiveDate IS NULL', $alias))
            ->orderBy(sprintf('%s.%s', $alias, $orderField), 'ASC');

        if (null !== $currentId) {
            $qb->orWhere(sprintf('%s.id = :currentId', $alias))->setParameter('currentId', $currentId);
        }

        return $qb;
    }
}
