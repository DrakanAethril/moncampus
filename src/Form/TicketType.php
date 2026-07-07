<?php

namespace App\Form;

use App\Entity\Room;
use App\Entity\Ticket;
use App\Entity\TicketCategory;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// The reporter is fixed by the controller (the current user) before this form is built, not an
// editable field - same idea as LaptopLoanLendType leaving laptop/borrower out of the form.
class TicketType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('subject', TextType::class, [
                'label' => 'ticketSubjectFieldLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('category', EntityType::class, [
                'class' => TicketCategory::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('c')
                    ->where('c.inactiveDate IS NULL')
                    ->orderBy('c.name', 'ASC'),
                'choice_label' => 'name',
                'label' => 'ticketCategoryFieldLabel',
                'placeholder' => 'ticketCategoryPlaceholder',
            ])
            ->add('room', EntityType::class, [
                'class' => Room::class,
                'query_builder' => static fn (EntityRepository $er) => $er->createQueryBuilder('r')
                    ->where('r.inactiveDate IS NULL')
                    ->orderBy('r.name', 'ASC'),
                'choice_label' => 'name',
                'label' => 'ticketRoomFieldLabel',
                'required' => false,
                'placeholder' => 'ticketRoomPlaceholder',
            ])
            ->add('otherLocation', TextType::class, [
                'label' => 'ticketOtherLocationFieldLabel',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'ticketDescriptionFieldLabel',
                'empty_data' => '',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Ticket::class,
        ]);
    }
}
