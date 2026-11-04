<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Program;
use App\Entity\Section;
use App\Entity\StructureTargetable;
use App\Entity\Track;
use App\Service\StructureTargeting\StructureTargetChoices;
use App\Service\StructureTargeting\StructureTargetTree;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one targeting field (design/validated/quiz-tournament.md §14): the tree Section › filière ›
 * formation as checkboxes, several choices at every level, combinable - what a StructureTargetable
 * (StructureTargetsTrait) is opened to. Used by the quiz tournaments, and by the serious games once
 * they are opened by targeting.
 *
 *     ->add('targets', StructureTargetsType::class, ['targetable' => $tournament])
 *
 * **It inherits its data** (`inherit_data`): its three children write `targetSections`,
 * `targetTracks` and `targetPrograms` straight onto the parent's data - an entity using the trait
 * (through its adders and removers), or an array with those three keys.
 *
 * Only the active structure is offered (StructureTargetChoices), test formations apart; what the
 * `targetable` already names stays on offer even once deactivated. Ticking a section covers every
 * filière and formation under it: the template says so (structure_targets_controller.js), the
 * children keep their own boxes - the rule is StructureTargetRule's, never the form's.
 *
 * `required` (true by default) refuses a targeting that names nothing - « au moins une section,
 * filière ou formation » - since an empty targeting reaches nobody.
 */
class StructureTargetsType extends AbstractType
{
    public function __construct(
        private readonly StructureTargetChoices $choices,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $targetable = $options['targetable'];
        $tree = $this->choices->for($targetable instanceof StructureTargetable ? $targetable : null);
        $builder->setAttribute('tree', $tree);

        $id = static fn (?object $node): string => null === $node || !method_exists($node, 'getId') ? '' : (string) $node->getId();
        $common = [
            'multiple' => true,
            'expanded' => true,
            'required' => false,
            'label' => false,
            'by_reference' => false,
            'choice_value' => $id,
            // The children are named after the id, so the template finds each box in the tree.
            'choice_name' => $id,
        ];

        $builder
            ->add('targetSections', EntityType::class, $common + [
                'class' => Section::class,
                'choices' => $tree->sections,
                'choice_label' => static fn (Section $section): string => $section->getName(),
            ])
            ->add('targetTracks', EntityType::class, $common + [
                'class' => Track::class,
                'choices' => $tree->tracks,
                'choice_label' => static fn (Track $track): string => $track->getName(),
            ])
            ->add('targetPrograms', EntityType::class, $common + [
                'class' => Program::class,
                'choices' => $tree->programs,
                'choice_label' => static fn (Program $program): string => $program->getShortName(),
            ]);

        if (true === $options['required']) {
            $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
                $form = $event->getForm();
                foreach (['targetSections', 'targetTracks', 'targetPrograms'] as $child) {
                    $data = $form->get($child)->getData();
                    if (is_countable($data) && \count($data) > 0) {
                        return;
                    }
                }

                $form->addError(new FormError($this->translator->trans('structureTargetsRequiredError')));
            });
        }
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $tree = $form->getConfig()->getAttribute('tree');
        $view->vars['tree'] = $tree instanceof StructureTargetTree ? $tree : null;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'inherit_data' => true,
                'targetable' => null,
                'required' => true,
                'error_bubbling' => false,
            ])
            ->setAllowedTypes('targetable', ['null', StructureTargetable::class]);
    }

    public function getBlockPrefix(): string
    {
        return 'structure_targets';
    }
}
