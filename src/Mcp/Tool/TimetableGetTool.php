<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\LessonLog;
use App\Enum\Feature;
use App\Mcp\McpTimetable;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\LessonLogRepository;
use App\Repository\LessonSessionRepository;
use App\Repository\ProgressionSeancePlacementRepository;
use App\Security\FeatureAccess;
use App\Security\ProgramTimetableAccess;
use App\Service\SeanceContentResolver;
use Symfony\Component\Clock\ClockInterface;

/**
 * The teacher's own emploi du temps over a few days: each créneau with its class, its matière, its
 * groups and its room, the progression séance planned on it, and whether its cahier de texte says
 * something yet. It is what turns « aujourd'hui avec mes SIO1 en B1 » into a séance id.
 *
 * Read exactly like the teacher's timetable screen (App\Controller\TeacherTimetableController): the
 * créneaux they deliver, in the formations whose timetable they may see.
 */
final readonly class TimetableGetTool implements McpTool
{
    private const int MAX_DAYS = 62;

    public function __construct(
        private LessonSessionRepository $sessions,
        private LessonLogRepository $logs,
        private ProgressionSeancePlacementRepository $placements,
        private ProgramTimetableAccess $timetableAccess,
        private FeatureAccess $featureAccess,
        private McpTimetable $timetable,
        private ClockInterface $clock,
    ) {
    }

    public function name(): string
    {
        return 'timetable_get';
    }

    public function title(): string
    {
        return 'Mon emploi du temps';
    }

    public function description(): string
    {
        return 'Renvoie les séances de l\'emploi du temps de l\'enseignant entre deux dates (par défaut la semaine en cours) : identifiant de séance (sessionId), date, horaires, durée, classe (formation), matière, groupes, salle, séance de progression prévue sur le créneau, et état du cahier de texte (rempli ou non, modifiable ou non). Filtrable par classe (programId) ou matière (topicId). 62 jours au plus par appel.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'format' => 'date', 'description' => 'Premier jour, AAAA-MM-JJ (par défaut le lundi de la semaine en cours).'],
                'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Dernier jour inclus, AAAA-MM-JJ (par défaut six jours après « from »).'],
                'programId' => ['type' => 'integer', 'description' => 'Une seule classe (voir whoami).'],
                'topicId' => ['type' => 'integer', 'description' => 'Une seule matière.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::Timetable];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $today = $this->clock->now()->setTime(0, 0);
        $from = $this->date($call->arguments->string('from'), 'from') ?? $today->modify('monday this week');
        $to = $this->date($call->arguments->string('to'), 'to') ?? $from->modify('+6 days');

        if ($to < $from) {
            throw new McpToolException('« to » doit être postérieur ou égal à « from ».');
        }
        if ($from->diff($to)->days > self::MAX_DAYS) {
            throw new McpToolException(\sprintf('La période demandée dépasse %d jours : découpez-la.', self::MAX_DAYS));
        }

        $onlyProgram = $call->optionalId('programId');
        $onlyTopic = $call->optionalId('topicId');

        $sessions = array_values(array_filter(
            $this->sessions->findAllForTeacherBetween($call->user, $from, $to, $this->timetableAccess->visibleTiers()),
            static fn ($session): bool => (null === $onlyProgram || $session->getProgram()->getId() === $onlyProgram)
                && (null === $onlyTopic || $session->getTopic()?->getId() === $onlyTopic),
        ));

        // The cahier de texte columns are only said to a teacher who has the cahier de texte:
        // mentioning a screen that does not exist for them is a plan that fails halfway.
        $withLog = $this->featureAccess->isEnabled(Feature::LessonLog, $call->user);
        $logs = [];
        if ($withLog) {
            foreach ($this->logs->findForSessions($sessions) as $log) {
                $logs[(int) $log->getLessonSession()?->getId()] = $log;
            }
        }
        $placements = $this->placements->findForLessonSessions($sessions);

        $rows = [];
        foreach ($sessions as $session) {
            $row = $this->timetable->slot($session);
            $row['plannedSeances'] = $this->timetable->plannedSeances($placements[(int) $session->getId()] ?? []);

            if ($withLog) {
                $log = $logs[(int) $session->getId()] ?? null;
                $row['lessonLog'] = [
                    'filled' => $log instanceof LessonLog && $this->saysSomething($log),
                    'canEdit' => $this->timetable->canEdit($session),
                ];
            }

            $rows[] = $row;
        }

        return McpToolResult::data(
            \sprintf('%d séances du %s au %s.', \count($rows), $from->format('d/m/Y'), $to->format('d/m/Y')),
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'sessions' => $rows],
        );
    }

    private function saysSomething(LessonLog $log): bool
    {
        return SeanceContentResolver::saysSomething($log->getContenuRealise())
            || SeanceContentResolver::saysSomething($log->getTravailAvantDescription())
            || SeanceContentResolver::saysSomething($log->getTravailApresDescription());
    }

    private function date(string $value, string $key): ?\DateTimeImmutable
    {
        if ('' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false === $date ? throw new McpToolException(\sprintf('« %s » s\'écrit AAAA-MM-JJ.', $key)) : $date;
    }
}
