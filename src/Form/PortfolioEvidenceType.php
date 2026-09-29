<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * One piece of evidence for a réalisation, an E6 fiche or the E5 attestations - a file or a link.
 *
 * Not entity-backed, like App\Form\LessonLogAttachmentType: the controller decides which of the
 * two was filled and builds the App\Entity\PortfolioEvidence. No « Bibliothèque de fichiers » tab:
 * the author is a student, and students have no library.
 */
class PortfolioEvidenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'portfolioEvidenceLabelFieldLabel',
                'required' => false,
                'help' => 'portfolioEvidenceLabelFieldHelp',
            ])
            ->add('file', FilePickerType::class, [
                'label' => 'portfolioEvidenceFileFieldLabel',
                'mapped' => false,
                'required' => false,
                'help' => FileUploadDefaults::MAX_SIZE_HELP_KEY,
                'policy' => UploadPolicy::documents(),
                'library' => false,
                'external_link' => 'url',
            ])
            ->add('url', UrlType::class, [
                'label' => false,
                'mapped' => false,
                'required' => false,
                'default_protocol' => 'https',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'portfolioEvidenceAddAction',
            ])
        ;
    }
}
