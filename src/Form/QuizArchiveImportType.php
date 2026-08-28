<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotNull;

// The second door into step 3 of the quiz assistant (App\Controller\QuizImportAssistantController):
// the documents as files rather than as a paste. A `.zip` of `.json` files is the batch a teacher
// who saved a model's answers one at a time actually holds; a lone `.json` is the same paste, sent
// as a file.
//
// Both extensions are narrowings of the platform list, so neither can drift outside it - and the
// field stages its bytes through App\Form\FilePickerType, which is what has them scanned before
// this form is ever submitted.
class QuizArchiveImportType extends AbstractType
{
    public const string MAX_SIZE = '10M';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FilePickerType::class, [
                'label' => 'quizBatchArchiveFieldLabel',
                'help' => 'quizBatchArchiveFieldHelpText',
                'policy' => UploadPolicy::platform()->restrictTo('zip', 'json'),
                'max_size' => self::MAX_SIZE,
                // No library tab: a document imported once is not course material
                // (design/validated/file-library.md, "The component").
                'library' => false,
                'constraints' => [
                    new NotNull(message: 'quizBatchArchiveRequiredMessage'),
                ],
            ])
        ;
    }
}
