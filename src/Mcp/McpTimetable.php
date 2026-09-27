<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\LessonLog;
use App\Entity\LessonSession;
use App\Entity\ProgressionSeancePlacement;
use App\Enum\LessonLogSection;
use App\Enum\LessonLogVisibility;
use App\Repository\LessonSessionRepository;
use App\Security\Voter\LessonLogVoter;
use App\Service\HtmlPlainText;
use App\Service\SeanceContentResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The emploi du temps and the cahier de texte as the connector reaches them - the same doors as
 * App\Controller\LessonLogController, in the same order: the formation runs its timetable here
 * (`Program::$timetableManagementEnabled`), then App\Security\Voter\LessonLogVoter decides, VIEW to
 * read a séance, EDIT to write in it. EDIT is App\Security\LessonLogEditors' narrow door: the
 * teacher who delivers the créneau and their co-animator, never staff on the strength of their role.
 *
 * It also holds the one shape a créneau is described in, so the timetable, the cahier de texte and
 * the progression tools name a séance the same way and Claude can carry an id from one to the other.
 */
final readonly class McpTimetable
{
    public function __construct(
        private LessonSessionRepository $sessions,
        private AuthorizationCheckerInterface $authorization,
        private HtmlPlainText $plainText,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @throws McpToolException when the créneau does not exist or is not open to that gesture - one
     *                          answer for both, like the screens' 404
     */
    public function session(int $id, string $attribute = LessonLogVoter::VIEW): LessonSession
    {
        $session = $this->sessions->find($id);

        if (!$session instanceof LessonSession || !$session->getProgram()->isTimetableManagementEnabled()
            || !$this->authorization->isGranted(LessonLogVoter::VIEW, $session)) {
            throw new McpToolException(\sprintf('Séance %d introuvable dans votre emploi du temps.', $id));
        }

        if (LessonLogVoter::EDIT === $attribute && !$this->authorization->isGranted(LessonLogVoter::EDIT, $session)) {
            throw new McpToolException(\sprintf('Vous ne pouvez pas écrire dans le cahier de texte de la séance %d : seul l\'enseignant qui l\'assure (ou son co-animateur) le peut.', $id));
        }

        return $session;
    }

    public function canEdit(LessonSession $session): bool
    {
        return $session->getProgram()->isTimetableManagementEnabled()
            && $this->authorization->isGranted(LessonLogVoter::EDIT, $session);
    }

    /**
     * A créneau's length as taught, from its hours - not LessonSession::$length, which is a figure
     * typed for the financial calculation and never follows the timetable.
     */
    public static function minutes(LessonSession $session): int
    {
        $start = $session->getStartHour();
        $end = $session->getEndHour();

        if (null === $start || null === $end) {
            return 0;
        }

        return max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60));
    }

    /**
     * @return array<string, mixed>
     */
    public function slot(LessonSession $session): array
    {
        $program = $session->getProgram();
        $topic = $session->getTopic();

        $groups = [];
        foreach ($session->getOptions() as $option) {
            $groups[] = $option->getName();
        }

        return [
            'sessionId' => $session->getId(),
            'date' => $session->getDay()?->format('Y-m-d'),
            'weekday' => $this->weekday($session),
            'start' => $session->getStartHour()?->format('H:i'),
            'end' => $session->getEndHour()?->format('H:i'),
            'minutes' => self::minutes($session),
            'program' => ['id' => $program->getId(), 'shortName' => $program->getDisplayShortName(), 'name' => $program->getDisplayName()],
            'topic' => null === $topic ? null : ['id' => $topic->getId(), 'name' => $topic->getName()],
            'title' => $session->getTitle(),
            // A créneau for part of the class: the options it is given to. Empty = the whole class.
            'groups' => $groups,
            'room' => $session->getClassRoom()?->getName(),
            'lessonType' => $session->getLessonType()?->getName(),
        ];
    }

    /**
     * The progression séances planned on a créneau, as its placements name them.
     *
     * @param list<ProgressionSeancePlacement> $placements
     *
     * @return list<array<string, mixed>>
     */
    public function plannedSeances(array $placements): array
    {
        $rows = [];
        foreach ($placements as $placement) {
            $seance = $placement->getProgressionSeance();
            $rows[] = [
                'seance' => $seance?->getTitle(),
                'sequence' => $seance?->getProgressionSequence()?->getTitle(),
                'seanceInstanceId' => $seance?->getSeanceInstance()?->getId(),
                'confirmed' => $placement->isConfirmed(),
            ];
        }

        return $rows;
    }

    /**
     * What a cahier de texte holds, part by part: the text a student would read (plain), its
     * visibility, and the documents filed under it. A part never written is null.
     *
     * @return array<string, array<string, mixed>>
     */
    public function logParts(?LessonLog $log): array
    {
        $parts = [];
        foreach (LessonLogSection::cases() as $section) {
            $content = $log?->getContent($section);
            $visibleAt = $log?->getVisibleAt($section);
            $documents = [];
            foreach ($log?->getAttachmentsForSection($section) ?? [] as $attachment) {
                $documents[] = $attachment->getLabel();
            }

            $parts[$section->value] = [
                'text' => $this->plain($content),
                'visibility' => ($log?->getVisibility($section) ?? LessonLogVisibility::Hidden)->value,
                'visibleAt' => $visibleAt?->format(\DATE_ATOM),
                'documents' => $documents,
            ];
        }

        return $parts;
    }

    /**
     * A text field read as the words it holds. Some of the fields read here are editor HTML and
     * others plain text under `pre-wrap` (App\Util\MarkdownRenderer says which), so a field with no
     * tag at all keeps its own line breaks rather than having them collapsed like HTML whitespace.
     */
    public function plain(?string $content): ?string
    {
        if (!SeanceContentResolver::saysSomething($content)) {
            return null;
        }

        return 1 === preg_match('#</?[a-z][^>]*>#i', (string) $content)
            ? $this->plainText->linesFromHtml($content)
            : trim((string) $content);
    }

    /** A part of the cahier de texte as the screen names it. */
    public static function sectionLabel(LessonLogSection $section): string
    {
        return match ($section) {
            LessonLogSection::Before => 'avant',
            LessonLogSection::During => 'pendant',
            LessonLogSection::After => 'après',
        };
    }

    /** Whether the students read a part, in words. */
    public static function visibilityLabel(LessonLog $log, LessonLogSection $section): string
    {
        return match ($log->getVisibility($section)) {
            LessonLogVisibility::Hidden => 'masquée',
            LessonLogVisibility::Now => 'visible',
            LessonLogVisibility::AfterSession => 'visible à la fin de la séance',
            LessonLogVisibility::Scheduled => 'programmée au '.$log->getVisibleAt($section)?->format('d/m/Y H:i'),
        };
    }

    public function logUrl(LessonSession $session): string
    {
        return $this->urls->generate('app_program_timetable_session_log', [
            'id' => $session->getProgram()->getId(),
            'sessionId' => $session->getId(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function weekday(LessonSession $session): ?string
    {
        $day = $session->getDay();

        return null === $day ? null : ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][(int) $day->format('N') - 1];
    }
}
