<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\LaptopConditionType;
use App\Repository\LaptopConditionTypeRepository;
use App\Service\UploadPolicy;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * Step ① of the laptop inventory import (App\Controller\LaptopImportController): the file, and the
 * état every machine it creates starts on.
 *
 * No entity behind the form: nothing is created until the analysis it produces has been confirmed.
 *
 * The état is asked once for the whole file, and is optional. A delivery of new machines is all in
 * the same state - which is precisely the one thing an inventory spreadsheet never records - and a
 * file of machines already in service is better left with no état at all than with a wrong one.
 */
class LaptopImportStartType extends AbstractType
{
    public const string MAX_SIZE = '2M';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FilePickerType::class, [
                'label' => 'laptopImportFileFieldLabel',
                'help' => 'laptopImportFileFieldHelpText',
                // A genuine CSV is guessed as text/plain, which Assert\File's own extension list
                // refuses - the platform's MIME map already spells the three spellings out.
                'policy' => UploadPolicy::spreadsheets()->restrictTo('csv'),
                'max_size' => self::MAX_SIZE,
                // No library tab: an inventory list imported once is not course material.
                'library' => false,
                'constraints' => [new NotNull(message: 'laptopImportFileRequiredMessage')],
            ])
            ->add('initialConditionType', EntityType::class, [
                'class' => LaptopConditionType::class,
                'label' => 'laptopImportInitialConditionFieldLabel',
                'help' => 'laptopImportInitialConditionFieldHelpText',
                'choice_label' => 'name',
                // The condition's color travels in data-color, which tom_select_controller.js
                // renders as a chip before the label.
                'choice_attr' => static fn (LaptopConditionType $type): array => ['data-color' => $type->getColor()],
                'placeholder' => 'laptopImportInitialConditionPlaceholder',
                'required' => false,
                'attr' => ['data-controller' => 'tom-select'],
                'query_builder' => static fn (LaptopConditionTypeRepository $repository) => $repository->createQueryBuilder('t')
                    ->andWhere('t.inactiveDate IS NULL')
                    ->orderBy('t.orderIndex', 'ASC'),
            ])
        ;
    }
}
