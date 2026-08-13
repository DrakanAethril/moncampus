<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LibraryBlocTag;
use App\Entity\LibraryNiveauTag;
use App\Entity\LibraryOptionTag;
use App\Entity\SeancePhaseTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\EvaluationNature;
use App\Repository\LibraryBlocTagRepository;
use App\Repository\LibraryNiveauTagRepository;
use App\Repository\LibraryOptionTagRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The confirmed payload turned into entities - the only place in the séquence import that writes.
 *
 * It persists every entity it builds and flushes none of them - the caller's own flush() picks the
 * lot up, exactly as App\Service\LibraryTagResolver does with a tag. Persisting each one is not
 * optional: none of these associations cascades persist (this repository maps none of them that way,
 * and App\Command\ImportNotionSequencesCommand persists its séquence, séances and phases one by
 * one), so handing Doctrine only the séquence raises "multiple non-persisted new entities were found
 * through the given association graph" at flush time.
 *
 * What it owns is the shape of the three destinations, and the one rule they share - **the import
 * never replaces**. It creates a séquence, appends séances to an existing one, or fills the *empty* fields of an
 * existing séance. Converting the same kit twice therefore produces duplicates, which a teacher can
 * see and delete; a second conversion that silently overwrote a month of edits would leave no way
 * back (design/comparaison/conception_sequence_seance_ia.md, § 8.14).
 *
 * Tags follow the library's own rule: Niveau/Option/Blocs are free text private to the teacher, so a
 * label nobody used before becomes a new tag of theirs (App\Service\LibraryTagResolver). That is
 * also why the import asks for the teacher's real labels *before* building the prompt - a model
 * left to invent them produces "BTS SIO 2ème année" next to the "BTS SIO 2" they already use.
 */
final class SequenceImportWriter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LibraryTagResolver $tagResolver,
        private readonly LibraryNiveauTagRepository $niveauTagRepository,
        private readonly LibraryOptionTagRepository $optionTagRepository,
        private readonly LibraryBlocTagRepository $blocTagRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param int                  $order  where the séquence lands in the teacher's own list; the
     *                                     controller counts the existing rows, as the manual form does
     */
    public function createSequence(User $teacher, array $payload, int $order = 0): SequenceTemplate
    {
        /** @var array<string, mixed> $raw */
        $raw = \is_array($payload['sequence'] ?? null) ? $payload['sequence'] : [];

        $sequence = new SequenceTemplate($teacher);
        $sequence->setTitre($this->stringOf($raw['titre'] ?? null));
        $sequence->setOrder($order);
        $sequence->setObjectifs($this->stringOf($raw['objectifs'] ?? null));
        $sequence->setCapacitesAttendues($this->stringOf($raw['capacitesAttendues'] ?? null));
        $sequence->setPreRequis($this->stringOf($raw['preRequis'] ?? null));
        $sequence->setTransversalites($this->stringOf($raw['transversalites'] ?? null));
        $sequence->setSituationProblematique($this->stringOf($raw['situationProblematique'] ?? null));
        $sequence->setSupportsGeneraux($this->stringOf($raw['supportsGeneraux'] ?? null));
        $sequence->setDifferentiation($this->stringOf($raw['differentiation'] ?? null));
        $sequence->setWatchPoints($this->stringOf($raw['watchPoints'] ?? null));

        $sequence->setNiveau($this->tagResolver->resolveOne($this->niveauTagRepository, LibraryNiveauTag::class, $teacher, $this->stringOf($raw['niveau'] ?? null)));
        $sequence->setOption($this->tagResolver->resolveOne($this->optionTagRepository, LibraryOptionTag::class, $teacher, $this->stringOf($raw['option'] ?? null)));
        foreach ($this->tagResolver->resolveMany($this->blocTagRepository, LibraryBlocTag::class, $teacher, $this->labels($raw['blocs'] ?? null)) as $bloc) {
            $sequence->addBloc($bloc);
        }

        $this->entityManager->persist($sequence);
        $this->appendSeances($sequence, $payload);

        return $sequence;
    }

    /**
     * Adds the payload's séances at the end of a séquence, leaving the séquence's own fields alone -
     * this is the destination for a kit converted one fiche at a time, so it must be replayable
     * without the second run undoing the first.
     *
     * @param array<string, mixed> $payload
     */
    public function appendSeances(SequenceTemplate $sequence, array $payload): void
    {
        $ordre = $sequence->getSeanceTemplates()->count();

        foreach ($this->rows($payload['seances'] ?? null) as $raw) {
            $seance = new SeanceTemplate($sequence);
            $seance->setOrdre(++$ordre);
            $seance->setTitre($this->stringOf($raw['titre'] ?? null));
            $seance->setDuree($this->stringOf($raw['duree'] ?? null));
            $seance->setEvaluationNature(EvaluationNature::tryFrom($this->stringOf($raw['evaluationNature'] ?? null) ?? ''));
            $seance->setObjectifs($this->stringOf($raw['objectifs'] ?? null));
            $seance->setAvantDescription($this->stringOf($raw['avantDescription'] ?? null));
            $seance->setApresDescription($this->stringOf($raw['apresDescription'] ?? null));
            $seance->setMaterials($this->stringOf($raw['materials'] ?? null));
            $seance->setWatchPoints($this->stringOf($raw['watchPoints'] ?? null));
            $seance->setCahierDeTexteDescription($this->stringOf($raw['cahierDeTexteDescription'] ?? null));

            $this->appendPhases($seance, $this->rows($raw['phases'] ?? null));

            $this->entityManager->persist($seance);
            $sequence->getSeanceTemplates()->add($seance);
        }
    }

    /**
     * Fills what the séance does not already say, and only that. The teacher asked for the missing
     * half of a séance they had started, not for their own wording to be replaced by a model's - so
     * a field they filled wins, every time, including against a longer or better-written value.
     *
     * The payload's first séance is the one used: "compléter une séance" is a one-to-one gesture,
     * and a document holding several is being pasted onto the wrong destination.
     *
     * @param array<string, mixed> $payload
     */
    public function completeSeance(SeanceTemplate $seance, array $payload): void
    {
        $rows = $this->rows($payload['seances'] ?? null);
        $raw = $rows[0] ?? [];

        $seance->setDuree($seance->getDuree() ?? $this->stringOf($raw['duree'] ?? null));
        $seance->setEvaluationNature($seance->getEvaluationNature() ?? EvaluationNature::tryFrom($this->stringOf($raw['evaluationNature'] ?? null) ?? ''));
        $seance->setObjectifs($seance->getObjectifs() ?? $this->stringOf($raw['objectifs'] ?? null));
        $seance->setAvantDescription($seance->getAvantDescription() ?? $this->stringOf($raw['avantDescription'] ?? null));
        $seance->setApresDescription($seance->getApresDescription() ?? $this->stringOf($raw['apresDescription'] ?? null));
        $seance->setMaterials($seance->getMaterials() ?? $this->stringOf($raw['materials'] ?? null));
        $seance->setWatchPoints($seance->getWatchPoints() ?? $this->stringOf($raw['watchPoints'] ?? null));
        $seance->setCahierDeTexteDescription($seance->getCahierDeTexteDescription() ?? $this->stringOf($raw['cahierDeTexteDescription'] ?? null));

        // Phases are appended rather than merged: a déroulé is a sequence of moments, and there is
        // no way to tell which of the teacher's phases a converted one was meant to complete.
        $this->appendPhases($seance, $this->rows($raw['phases'] ?? null));
    }

    /** @param list<array<string, mixed>> $rows */
    private function appendPhases(SeanceTemplate $seance, array $rows): void
    {
        $ordre = $seance->getSeancePhaseTemplates()->count();

        foreach ($rows as $raw) {
            $phase = new SeancePhaseTemplate($seance);
            $phase->setOrdre(++$ordre);
            $phase->setNom($this->stringOf($raw['nom'] ?? null));
            $phase->setDuree($this->stringOf($raw['duree'] ?? null));
            $phase->setContenu($this->stringOf($raw['contenu'] ?? null));
            $phase->setObjectifs($this->stringOf($raw['objectifs'] ?? null));
            $phase->setEnseignant($this->stringOf($raw['enseignant'] ?? null));
            $phase->setEtudiant($this->stringOf($raw['etudiant'] ?? null));
            $phase->setMoyensSupports($this->stringOf($raw['moyensSupports'] ?? null));
            $phase->setDifficultes($this->stringOf($raw['difficultes'] ?? null));

            $this->entityManager->persist($phase);
            $seance->getSeancePhaseTemplates()->add($phase);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $raw): array
    {
        $rows = [];
        foreach (\is_array($raw) ? array_values($raw) : [] as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function labels(mixed $raw): array
    {
        $labels = [];
        foreach (\is_array($raw) ? array_values($raw) : [] as $label) {
            if (\is_scalar($label) && '' !== trim((string) $label)) {
                $labels[] = trim((string) $label);
            }
        }

        return $labels;
    }

    private function stringOf(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $string = (string) $value;

        return '' === $string ? null : $string;
    }
}
