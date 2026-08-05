<?php

namespace App\Form;

use App\Entity\EmailAlias;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One row of the School mail address list edited from
 * App\Controller\DirectoryUserController::edit() - see App\Form\UserProfileType.
 *
 * Only the local part is exposed: the domain comes from MAIL_STUDENT_DOMAIN (App\Entity\EmailAlias),
 * the origin is not a choice (an address typed here is manual by construction, the entity's own
 * default) and the creation date belongs to the entity.
 */
class EmailAliasType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // An address is compared and stored in lowercase (App\Repository\EmailAliasRepository
        // searches on the normalised form): what was typed is brought back to that form before any
        // check, so a keyboard-typed "Prenom.Nom" cannot slip past the "prenom.nom" already
        // assigned to another student.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();

            if (\is_array($submitted) && \is_string($submitted['localPart'] ?? null)) {
                $submitted['localPart'] = mb_strtolower(trim($submitted['localPart']));
                $event->setData($submitted);
            }
        });

        // The address built from the name and the one taken from the login follow their source:
        // they show, but are not retouched (App\Enum\EmailAliasOrigin::isManageable()). The field is
        // disabled rather than absent, so that the posted value - the template renders a hidden
        // input, failing which CollectionType would read the row's absence as a deletion - cannot
        // rewrite it.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $alias = $event->getData();

            $event->getForm()->add('localPart', TextType::class, [
                'label' => 'emailAliasLocalPartFieldLabel',
                'required' => false,
                'empty_data' => '',
                'disabled' => $alias instanceof EmailAlias && !$alias->getOrigin()->isManageable(),
                'attr' => [
                    'placeholder' => 'prenom.nom',
                    'maxlength' => 64,
                    'autocapitalize' => 'off',
                    'autocorrect' => 'off',
                    'spellcheck' => 'false',
                ],
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => EmailAlias::class]);
    }
}
