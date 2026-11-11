<?php

declare(strict_types=1);

namespace App\Tests\Functional\Crm;

use App\Entity\CrmOrigin;
use App\Entity\CrmPiece;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceKind;
use App\Service\Crm\CrmSettings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The CRM rows a test needs. Pieces and origins are found by code or label before being created:
 * CI replays the migration that ships the catalogue, the local test schema does not.
 */
trait CrmFixturesTrait
{
    private function crmEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param list<CrmModality> $modalities
     * @param list<string>      $choices
     */
    private function crmPiece(string $code, CrmPhase $phase = CrmPhase::Enrolment, CrmPieceKind $kind = CrmPieceKind::Check, array $modalities = [], array $choices = [], bool $future = false): CrmPiece
    {
        $entityManager = $this->crmEntityManager();
        $piece = $entityManager->getRepository(CrmPiece::class)->findOneBy(['code' => $code]);
        if (!$piece instanceof CrmPiece) {
            $piece = new CrmPiece($code, ucfirst(str_replace('_', ' ', $code)), $code, $phase, $kind, [] === $modalities ? CrmModality::cases() : $modalities, CrmPath::cases(), 900);
            $piece->setChoices($choices);
            $piece->setFutureDateAllowed($future);
            if (CrmPiece::DEPOSIT === $code) {
                $piece->setAsksPaymentMode(true);
            }
            $entityManager->persist($piece);
            $entityManager->flush();
        }
        static::getContainer()->get(CrmSettings::class)->forgetCatalogue();

        return $piece;
    }

    private function crmOrigin(string $label): CrmOrigin
    {
        $entityManager = $this->crmEntityManager();
        $origin = $entityManager->getRepository(CrmOrigin::class)->findOneBy(['label' => $label]);
        if (!$origin instanceof CrmOrigin) {
            $origin = new CrmOrigin($label, 100);
            $entityManager->persist($origin);
            $entityManager->flush();
        }

        return $origin;
    }

    /** A formation followed in the CRM, its year opened (and default). */
    private function crmFollow(Program $program, CrmModality $modality = CrmModality::Initial): Program
    {
        $settings = static::getContainer()->get(CrmSettings::class);
        $settings->setOpened($program->getSchoolYear(), true);
        $crmProgram = $settings->settingsFor($program);
        $crmProgram->setFollowed(true);
        $crmProgram->setDefaultModality($modality);
        $this->crmEntityManager()->flush();

        return $program;
    }

    private function crmOption(Program $program, string $shortName, User $author): Option
    {
        $option = new Option($shortName, $shortName, '#1B6BA8');
        $option->setCreatedBy($author);
        $option->addProgram($program);
        $this->crmEntityManager()->persist($option);
        $this->crmEntityManager()->flush();

        return $option;
    }
}
