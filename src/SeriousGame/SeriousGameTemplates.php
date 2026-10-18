<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameTemplate;
use App\Entity\User;
use App\Repository\SeriousGameTemplateRepository;
use App\SeriousGame\Engine\DatabaseTemplateSource;
use App\SeriousGame\Engine\GeneratedQuestion;
use App\SeriousGame\Template\TemplateBench;
use App\SeriousGame\Template\TemplateFormatException;
use App\SeriousGame\Template\TemplateInterpreter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The versions of a game's question templates (design/validated/serious-games.md §5.4, §9) - the
 * one door the administration screen and the connector's tools write through.
 *
 * - propose(): a new **draft**, refused whole at the first format error, put on the bench at once.
 *   A draft never serves a student.
 * - validate(): a draft whose bench is green becomes the unit's current version; the previous one
 *   is superseded, and stays - the questions it served are replayed from it.
 * - Nothing is ever deleted.
 */
final readonly class SeriousGameTemplates
{
    public const int PROPOSE_SEEDS = 1000;
    public const int MAX_SEEDS = 2000;

    public function __construct(
        private SeriousGameTemplateRepository $templates,
        private TemplateInterpreter $interpreter,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @throws TemplateFormatException when the document does not follow the format
     * @throws \DomainException        when the unit is not one of the game's
     */
    public function propose(SeriousGame $game, string $unit, array $document, ?User $author, ?string $note = null): SeriousGameTemplate
    {
        $manifest = new SeriousGameManifest($game->getManifest());
        if (null === $manifest->unit($unit)) {
            throw new \DomainException(\sprintf('L’unité « %s » n’existe pas dans le jeu « %s ».', $unit, $game->getSlug()));
        }

        $this->interpreter->validate($document);
        if (($document['unit'] ?? null) !== $unit) {
            throw new TemplateFormatException('unit', \sprintf('le gabarit doit porter l’unité « %s »', $unit));
        }

        /** @var array<string, mixed> $document */
        $draft = new SeriousGameTemplate($game, $unit, $this->templates->nextVersion($game, $unit), $document, $author);
        $draft->setNote($note);
        $this->entityManager->persist($draft);
        $this->bench($draft, self::PROPOSE_SEEDS);

        return $draft;
    }

    /**
     * @return array<string, mixed> the report, stored on the version
     */
    public function bench(SeriousGameTemplate $template, int $seeds = self::PROPOSE_SEEDS): array
    {
        $report = (new TemplateBench($this->interpreter))->run(DatabaseTemplateSource::definition($template), max(1, min(self::MAX_SEEDS, $seeds)));
        $template->setBenchReport($report);
        $this->entityManager->flush();

        return $report;
    }

    /**
     * @throws \DomainException when the version is not a draft, or its bench is not green
     */
    public function validate(SeriousGameTemplate $draft, ?User $by): void
    {
        if (!$draft->isDraft()) {
            throw new \DomainException('Seul un brouillon se valide.');
        }
        if (!$draft->isBenchGreen()) {
            throw new \DomainException('Le banc de ce brouillon n’est pas au vert : il ne peut pas devenir la version courante.');
        }

        $current = $this->templates->findCurrent($draft->getGame(), $draft->getUnitCode());
        $current?->markSuperseded();
        $draft->markValidated($by);
        $this->entityManager->flush();
    }

    /**
     * Questions drawn from a version, to read what it gives.
     *
     * @return list<GeneratedQuestion>
     */
    public function samples(SeriousGameTemplate $template, int $count): array
    {
        $definition = DatabaseTemplateSource::definition($template);
        $questions = [];
        foreach (TemplateBench::seeds($count) as $seed) {
            $questions[] = $this->interpreter->generate($definition, $seed + 7);
        }

        return $questions;
    }
}
