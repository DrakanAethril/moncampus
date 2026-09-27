<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\LessonLog;
use App\Entity\SeanceInstance;
use App\Enum\Feature;
use App\Mcp\McpTimetable;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolResult;
use App\Repository\LessonLogRepository;
use App\Security\Voter\LessonLogVoter;
use App\Service\SeanceContentResolver;

/**
 * One séance's cahier de texte, and what to write it from: the three parts as they stand, the
 * progression séance planned on the créneau (what App\Service\SeanceContentResolver offers the
 * screen to « reprendre »), and the last cahiers de texte of the same matière - where the class is
 * at. Everything Claude needs to reformulate what the teacher says they did.
 */
final readonly class LessonLogGetTool implements McpTool
{
    private const int PREVIOUS = 3;

    public function __construct(
        private McpTimetable $timetable,
        private LessonLogRepository $logs,
        private SeanceContentResolver $seances,
    ) {
    }

    public function name(): string
    {
        return 'lesson_log_get';
    }

    public function title(): string
    {
        return 'Lire le cahier de texte d\'une séance';
    }

    public function description(): string
    {
        return 'Renvoie le cahier de texte d\'une séance (sessionId, voir timetable_get) : ses trois parties « avant », « pendant » et « après » avec leur visibilité pour les étudiants, la séance de progression prévue sur ce créneau (titre, objectifs, contenu prévu pour chaque partie) et les derniers cahiers de texte remplis dans la même matière. Indique si l\'enseignant peut y écrire.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sessionId' => ['type' => 'integer', 'description' => 'La séance (voir timetable_get).'],
            ],
            'required' => ['sessionId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::LessonLog];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $session = $this->timetable->session($call->requiredId('sessionId'), LessonLogVoter::VIEW);
        $log = $this->logs->findOneBySession($session);
        $seance = $this->seances->forLessonSession($session);

        $previous = [];
        foreach ($this->logs->findPreviousFilledForTopic($session, self::PREVIOUS) as $earlier) {
            $earlierSession = $earlier->getLessonSession();
            $previous[] = [
                'sessionId' => $earlierSession?->getId(),
                'date' => $earlierSession?->getDay()?->format('Y-m-d'),
                'before' => $this->timetable->plain($earlier->getTravailAvantDescription()),
                'during' => $this->timetable->plain($earlier->getContenuRealise()),
                'after' => $this->timetable->plain($earlier->getTravailApresDescription()),
            ];
        }

        $canEdit = $this->timetable->canEdit($session);
        $slot = $this->timetable->slot($session);

        return McpToolResult::data(
            \sprintf(
                'Cahier de texte du %s, %s - %s : %s. %s',
                $session->getDay()?->format('d/m/Y'),
                $session->getProgram()->getDisplayShortName(),
                $session->getDisplayName(),
                $log instanceof LessonLog ? 'déjà ouvert' : 'encore vide',
                $canEdit ? 'Vous pouvez y écrire.' : 'Lecture seule : vous n\'assurez pas cette séance.',
            ),
            [
                'session' => $slot,
                'url' => $this->timetable->logUrl($session),
                'canEdit' => $canEdit,
                'parts' => $this->timetable->logParts($log),
                'plannedSeance' => $this->seance($seance),
                'previousLessonLogs' => $previous,
            ],
        );
    }

    /** @return array<string, mixed>|null */
    private function seance(?SeanceInstance $seance): ?array
    {
        if (null === $seance) {
            return null;
        }

        $defaults = $this->seances->defaultsFor($seance);

        return [
            'seanceInstanceId' => $seance->getId(),
            'title' => $seance->getTitre(),
            'sequence' => $seance->getSequenceInstance()?->getTitre(),
            'objectives' => $this->timetable->plain($seance->getObjectifs()),
            // What the séance already says for each part - the screen's « reprendre » buttons.
            'plannedParts' => array_map($this->timetable->plain(...), $defaults),
        ];
    }
}
