<?php

declare(strict_types=1);

namespace App\Tests\Service\Portfolio;

use App\Entity\Option;
use App\Entity\Portfolio;
use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioClaim;
use App\Entity\Referential;
use App\Entity\ReferentialBlock;
use App\Entity\ReferentialCompetency;
use App\Entity\User;
use App\Enum\PortfolioSetting;
use App\Enum\ReferentialBlockRole;

/**
 * An in-memory BTS SIO référentiel - bloc 1 (six competencies, the 2026 template's words) and the
 * SISR bloc 2 - with ids set by reflection, so the rules can be tested without a database.
 */
trait PortfolioFixtures
{
    private int $nextId = 1;

    private function referential(?Option $sisr = null): Referential
    {
        $referential = new Referential('bts-sio', 'BTS SIO', 'RNCP40792');
        $this->setId($referential);

        $bloc1 = new ReferentialBlock($referential, 'B1', 'Support et mise à disposition de services informatiques', 0);
        $bloc1->setRole(ReferentialBlockRole::Synthesis);
        $this->setId($bloc1);
        foreach ([
            'Gérer le patrimoine informatique',
            'Répondre aux incidents et aux demandes d’assistance et d’évolution',
            'Développer la présence en ligne de l’organisation',
            'Travailler en mode projet',
            'Mettre à disposition des utilisateurs un service informatique',
            'Organiser son développement professionnel',
        ] as $rank => $label) {
            $this->setId(new ReferentialCompetency($bloc1, 'B1.'.($rank + 1), $label, [], $rank));
        }

        $bloc2 = new ReferentialBlock($referential, 'B2', 'Administration des systèmes et des réseaux (Option A, « Solutions d’infrastructure, systèmes et réseaux »)', 1);
        $bloc2->setRole(ReferentialBlockRole::Showcase);
        $this->setId($bloc2);
        if (null !== $sisr) {
            $bloc2->addOption($sisr);
        }
        foreach ([
            'Concevoir une solution d’infrastructure réseau',
            'Installer, tester et déployer une solution d’infrastructure réseau',
            'Exploiter, dépanner et superviser une solution d’infrastructure réseau',
        ] as $rank => $label) {
            $this->setId(new ReferentialCompetency($bloc2, 'B2.'.($rank + 1), $label, [], $rank));
        }

        return $referential;
    }

    private function sisr(): Option
    {
        $option = new Option('SISR', 'SISR', '#123456');
        $this->setId($option);

        return $option;
    }

    private function portfolio(Referential $referential): Portfolio
    {
        $student = new User('lea.martin');
        $student->setFirstname('Léa')->setLastname('Martin');
        $this->setId($student);
        $portfolio = new Portfolio($student, $referential);
        $this->setId($portfolio);

        return $portfolio;
    }

    /**
     * @param list<int> $claimed  competency positions in bloc 1 (0-based), then 100+ for bloc 2
     * @param list<int> $retained the subset retained when the achievement is validated
     */
    private function achievement(Portfolio $portfolio, string $title, PortfolioSetting $setting, string $from, string $to, array $claimed, array $retained = [], bool $validate = true): PortfolioAchievement
    {
        $achievement = new PortfolioAchievement($portfolio);
        $this->setId($achievement);
        $achievement->setTitle($title)->setSetting($setting)
            ->setStartsOn(new \DateTimeImmutable($from))->setEndsOn(new \DateTimeImmutable($to));

        foreach ($claimed as $position) {
            $competency = $this->competency($portfolio->getReferential(), $position);
            $this->setId(new PortfolioClaim($achievement, $competency, 'Parce que.'));
        }

        $achievement->submit();

        if ($validate) {
            foreach ($achievement->getClaims() as $claim) {
                $position = 'B2' === $claim->getCompetency()?->getBlock()?->getCode() ? 100 + $claim->getCompetency()->getPosition() : $claim->getCompetency()?->getPosition();
                $claim->decide(\in_array($position, $retained, true), null);
            }
            $achievement->markValidated();
        }

        return $achievement;
    }

    private function competency(?Referential $referential, int $position): ReferentialCompetency
    {
        $block = $referential?->getBlocks()->get($position >= 100 ? 1 : 0);
        $competency = $block?->getCompetencies()->get($position >= 100 ? $position - 100 : $position);

        if (null === $competency) {
            throw new \LogicException('No such competency.');
        }

        return $competency;
    }

    private function setId(object $entity): void
    {
        // The id may be declared by a parent - Option's is AbstractStructureNode's.
        $class = new \ReflectionClass($entity);
        while (!$class->hasProperty('id') && false !== $class->getParentClass()) {
            $class = $class->getParentClass();
        }
        $class->getProperty('id')->setValue($entity, $this->nextId++);
    }
}
