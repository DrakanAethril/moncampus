<?php

namespace App\Service;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Entity\User;
use App\Enum\EcoCheckpointType;
use App\Repository\EcoCheckpointRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Builds a new EcoParcours together with its auto-added Start/Finish checkpoints and N regular
 * ones (screen 1d's note: "+ une balise Départ et une balise Arrivée ajoutées automatiquement") -
 * the only way an EcoParcours is ever created, so this is the single place short codes get
 * generated too.
 */
class EcoParcoursFactory
{
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

        $prefix = $this->shortCodePrefix($name);

        $start = new EcoCheckpoint($parcours);
        $start->setType(EcoCheckpointType::Start);
        $start->setPosition(0);
        $start->setName('Départ');
        $start->setShortCode($this->uniqueShortCode($prefix.'-DEP'));
        $parcours->addCheckpoint($start);

        for ($number = 1; $number <= $checkpointCount; ++$number) {
            $checkpoint = new EcoCheckpoint($parcours);
            $checkpoint->setType(EcoCheckpointType::Checkpoint);
            $checkpoint->setPosition($number);
            $checkpoint->setName(\sprintf('Balise %d', $number));
            $checkpoint->setShortCode($this->uniqueShortCode(\sprintf('%s-B%02d', $prefix, $number)));
            $parcours->addCheckpoint($checkpoint);
        }

        $finish = new EcoCheckpoint($parcours);
        $finish->setType(EcoCheckpointType::Finish);
        $finish->setPosition($checkpointCount + 1);
        $finish->setName('Arrivée');
        $finish->setShortCode($this->uniqueShortCode($prefix.'-ARR'));
        $parcours->addCheckpoint($finish);

        $this->entityManager->persist($parcours);

        return $parcours;
    }

    // 2-3 letter uppercase prefix from the parcours name's own words (e.g. "Parc Victor-Thuillat"
    // -> "PVT"), falling back to the slugged name's first letters for a single-word name (e.g.
    // "Bastide" -> "BAS") - purely a mnemonic for whoever reads the printed QR codes on 1f, not
    // itself required to be unique (uniqueShortCode() below is what actually guarantees that).
    private function shortCodePrefix(string $name): string
    {
        $words = preg_split('/[\s\-]+/', trim($name)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => '' !== $word));

        if (\count($words) >= 2) {
            $initials = array_map(static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), \array_slice($words, 0, 3));

            return implode('', $initials);
        }

        $slug = (new AsciiSlugger())->slug($name)->upper()->toString();

        return mb_substr($slug, 0, 3) ?: 'PAR';
    }

    private function uniqueShortCode(string $candidate): string
    {
        $code = $candidate;
        $suffix = 2;
        while (null !== $this->checkpointRepository->findOneBy(['shortCode' => $code])) {
            $code = \sprintf('%s-%d', $candidate, $suffix++);
        }

        return $code;
    }
}
