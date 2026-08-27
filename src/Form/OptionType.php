<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\LdapManageGroup;
use App\Entity\Option;
use App\Enum\GameTrack;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class OptionType extends AbstractType
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
            ->add('shortName', TextType::class, [
                'label' => 'structureShortNameColumnLabel',
                'empty_data' => '',
            ])
            ->add('color', ColorType::class, [
                'label' => 'structureColorColumnLabel',
            ])
            // Not editable here - Options are only linked to a Program through the Program's
            // own form (see ProgramType::$options / Program::addOption(), which is what actually
            // owns the persist logic for this ManyToMany regardless of which side's form is used).
            ->add('ldapGroup', EntityType::class, [
                'class' => LdapManageGroup::class,
                'choice_label' => 'name',
                'label' => 'structureLdapGroupColumnLabel',
                'required' => false,
                'placeholder' => 'structureLdapGroupPlaceholder',
            ])
            // The campus game's filière, when this option *is* one - SLAM and SISR are options of
            // BTS SIO, and which of them a student belongs to is what decides their level wording
            // and their pseudonym catalogue. Left empty on every option that is not a filière,
            // which is nearly all of them: a group, a bilingual track, a mini-entreprise.
            ->add('gameTrack', EnumType::class, [
                'class' => GameTrack::class,
                'choice_label' => static fn (GameTrack $track): string => $track->labelKey(),
                'label' => 'optionGameTrackFieldLabel',
                'help' => 'optionGameTrackFieldHelpText',
                'placeholder' => 'optionGameTrackPlaceholder',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'optionDescriptionFieldLabel',
                'help' => 'optionDescriptionFieldHelpText',
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
            'data_class' => Option::class,
            // Same reasoning as TrackType::$empty_data: Option's constructor requires a name,
            // a short name and a color, built here from already-submitted sibling fields, with
            // a throwaway fallback so a missing required field is a validation error, not a
            // TypeError.
            'empty_data' => static function (FormInterface $form): Option {
                return new Option(
                    $form->get('name')->getData() ?? '',
                    $form->get('shortName')->getData() ?? '',
                    $form->get('color')->getData() ?? '#206bc4',
                );
            },
        ]);
    }
}
