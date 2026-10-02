<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\OnlineCourseMaterialKind;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One material of an online course: its file, and - when the nature's own name will not do - what
 * its tab is called (design/validated/cours-en-ligne.md, §8).
 *
 * The form is per nature (`kind`), because the nature is what decides what the field accepts: the
 * policy is App\Enum\OnlineCourseMaterialKind's, stated once and read here, by the public player
 * and by the Claude connector alike.
 *
 * `with_label` is false when replacing a material's file: the tab keeps its name.
 */
class OnlineCourseMaterialType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $kind = $options['kind'];
        \assert($kind instanceof OnlineCourseMaterialKind);

        if (true === $options['with_label']) {
            $builder->add('label', TextType::class, [
                'label' => 'onlineCourseMaterialLabelFieldLabel',
                'help' => 'onlineCourseMaterialLabelFieldHelp',
                'required' => false,
                'attr' => ['maxlength' => 100],
            ]);
        }

        $builder->add('file', FilePickerType::class, [
            'label' => 'onlineCourseMaterialFileFieldLabel',
            'help' => $kind->isBundle() ? 'onlineCourseMaterialBundleFieldHelp' : 'onlineCourseMaterialFileFieldHelp',
            'policy' => $kind->uploadPolicy(),
            'required' => true,
            // Teacher-authored course material, so the « Bibliothèque de fichiers » tab is offered.
            // Unlike everywhere else a file picked there is **copied** into the course's own folder
            // (App\Service\OnlineCourse\OnlineCourseMaterialStore): the help line says so.
            'library' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['with_label' => true])
            ->setRequired('kind')
            ->setAllowedTypes('kind', OnlineCourseMaterialKind::class)
            ->setAllowedTypes('with_label', 'bool');
    }
}
