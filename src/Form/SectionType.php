<?php

namespace App\Form;

use App\Entity\LdapManageGroup;
use App\Entity\Section;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'structureNameColumnLabel',
                // Explicit '' (not the default) activates TextType's own null->'' safety net for
                // blank submissions on this non-nullable property - see TextType::buildForm().
                'empty_data' => '',
            ])
            ->add('ldapGroup', EntityType::class, [
                'class' => LdapManageGroup::class,
                'choice_label' => 'name',
                'label' => 'structureLdapGroupColumnLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            // Plain text, not ChoiceType: the ~5000-icon catalog lives client-side (see
            // icon_picker_controller.js) rather than as server-rendered <option>s, so this stays a
            // free string field constrained only by Section::$icon's column length - the picker is
            // the only way staff actually set it, an out-of-catalog value has no worse failure mode
            // than the icon silently not rendering in the nav.
            ->add('icon', TextType::class, [
                'label' => 'structureIconFieldLabel',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'submitCreateAction',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Section::class,
            // Section's constructor requires a name, so a fresh entity can't be built via plain
            // reflection - construct it here once the name field has actually been submitted.
            'empty_data' => static function (FormInterface $form): Section {
                return new Section($form->get('name')->getData() ?? '');
            },
        ]);
    }
}
