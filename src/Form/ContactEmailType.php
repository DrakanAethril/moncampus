<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

// Self-service counterpart to App\Form\UserProfileType (the staff-facing Gestion > Utilisateurs
// form) - kept separate rather than reused since that one also bundles admin-only fields
// (manualGroups) a user must never be able to edit for themselves.
class ContactEmailType extends AbstractType
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('pendingContactEmail', EmailType::class, [
                'label' => 'contactEmailNewAddressFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitSaveAction',
            ])
        ;

        // User's own #[UniqueEntity] on pendingContactEmail only compares against other people's
        // *pending* addresses, so an address someone else has already confirmed sails through here
        // and only blows up much later, as a raw unique-constraint violation at the moment
        // ContactEmailVerifier::confirmByToken() promotes it onto $contactEmail. Rejected up front
        // instead, on the field that caused it. Lives in the form rather than in either controller
        // so the web profile and the mobile API (Api\ProfileController) are both covered.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $field = $event->getForm()->get('pendingContactEmail');
            $email = $field->getData();

            if (null === $email || '' === $email) {
                return;
            }

            /** @var User $user */
            $user = $event->getData();
            $holder = $this->userRepository->findOneBy(['contactEmail' => $email]);

            if (null !== $holder && $holder !== $user) {
                $field->addError(new FormError($this->translator->trans('contactEmailAlreadyUsedMessage')));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
