<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\FileLibraryNode;
use App\Entity\Portfolio;
use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioEvidence;
use App\Entity\PortfolioShowcase;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use Symfony\Component\Form\FormInterface;

/**
 * Turns a submitted App\Form\PortfolioEvidenceType into a piece of evidence - one of three parents,
 * exactly one source.
 *
 * Files go under `portfolio/<portfolio id>/`, so a portfolio's bytes can be found - and one day
 * purged with its retention (conservation one year after the formation) - by prefix.
 */
class PortfolioEvidenceIntake
{
    public const string PREFIX = 'portfolio/';

    public function __construct(
        private readonly UploadIntake $uploads,
    ) {
    }

    /**
     * @return PortfolioEvidence|string the evidence, attached to its parent - or a translation key
     *                                  saying why nothing was added
     */
    public function fromForm(FormInterface $form, PortfolioAchievement|PortfolioShowcase|Portfolio $parent): PortfolioEvidence|string
    {
        $file = $form->get('file')->getData();
        $url = $form->get('url')->getData();
        $labelData = $form->get('label')->getData();
        $label = \is_string($labelData) ? trim($labelData) : '';
        $url = \is_string($url) && '' !== trim($url) ? trim($url) : null;
        $file = $file instanceof StagedUpload || $file instanceof FileLibraryNode ? $file : null;

        if ((null === $file) === (null === $url)) {
            return null === $file ? 'portfolioEvidenceMissingSourceError' : 'portfolioEvidenceBothSourcesError';
        }

        $position = match (true) {
            $parent instanceof Portfolio => $parent->getAttestations()->count(),
            default => $parent->getEvidences()->count(),
        };

        if (null !== $url) {
            try {
                $evidence = PortfolioEvidence::link($parent, $label, $url);
            } catch (\InvalidArgumentException) {
                return 'portfolioEvidenceLinkInvalidError';
            }

            return $evidence->setPosition($position);
        }

        $portfolio = $parent instanceof Portfolio ? $parent : $parent->getPortfolio();
        $key = $this->uploads->store(
            $file,
            self::PREFIX,
            \sprintf('%d/%s-%s.%s', (int) $portfolio?->getId(), date('YmdHis'), bin2hex(random_bytes(4)), UploadIntake::extension($file)),
        );

        return PortfolioEvidence::file($parent, $label, $key, UploadIntake::originalName($file), UploadIntake::mimeType($file), UploadIntake::size($file))
            ->setPosition($position);
    }
}
