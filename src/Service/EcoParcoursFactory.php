<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Repository\EcoCheckpointRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds a new EcoParcours together with its auto-added Start/Finish checkpoints and N regular
 * ones (screen 1d's note: "+ une balise Départ et une balise Arrivée ajoutées automatiquement") -
 * the only way an EcoParcours is ever created, so this is the single place short codes get
 * generated too.
 *
 * A short code is what validates a flag when typed by hand, so it must say nothing about the
 * others: each one is drawn at random (EcoRandomCode), one character longer than a course code
 * so the two never read alike - a runner holding both knows which one a field is asking for.
 * The codes used to be spelt from the parcours' initials and the flag's number (« PVT-B03 »),
 * and knowing one was knowing them all - a runner could validate the whole parcours from the
 * start line.
 */
class EcoParcoursFactory
{
    public const int SHORT_CODE_LENGTH = 7;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EcoCheckpointRepository $checkpointRepository,
    ) {
    }

    public function create(User $teacher, string $name, int $checkpointCount): EcoParcours
    {
        $parcours = new EcoParcours($teacher);
        $parcours->setName($name);
        $parcours->setCreatedBy($teacher);

        // The codes drawn by this call: none of them is flushed yet, so the repository cannot see them.
        $drawn = [];

        $start = new EcoCheckpoint($parcours);
        $start->setType(EcoCheckpointType::Start);
        $start->setPosition(0);
        $start->setName('Départ');
        $start->setShortCode($this->uniqueShortCode($drawn));
        $parcours->addCheckpoint($start);

        for ($number = 1; $number <= $checkpointCount; ++$number) {
            $checkpoint = new EcoCheckpoint($parcours);
            $checkpoint->setType(EcoCheckpointType::Checkpoint);
            $checkpoint->setPosition($number);
            $checkpoint->setName(\sprintf('Balise %d', $number));
            $checkpoint->setShortCode($this->uniqueShortCode($drawn));
            $parcours->addCheckpoint($checkpoint);
        }

        $finish = new EcoCheckpoint($parcours);
        $finish->setType(EcoCheckpointType::Finish);
        $finish->setPosition($checkpointCount + 1);
        $finish->setName('Arrivée');
        $finish->setShortCode($this->uniqueShortCode($drawn));
        $parcours->addCheckpoint($finish);

        $this->entityManager->persist($parcours);

        return $parcours;
    }

    /**
     * A code no checkpoint carries yet - neither in the database nor among the ones drawn for this
     * same parcours. Old-style codes (« PVT-B03 ») cannot collide: they hold a hyphen.
     *
     * @param array<string, true> $drawn
     */
    private function uniqueShortCode(array &$drawn): string
    {
        do {
            $code = EcoRandomCode::draw(self::SHORT_CODE_LENGTH);
        } while (isset($drawn[$code]) || null !== $this->checkpointRepository->findOneBy(['shortCode' => $code]));

        $drawn[$code] = true;

        return $code;
    }
}
