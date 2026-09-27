<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\LessonLog;
use App\Enum\Feature;
use App\Enum\LessonLogSection;
use App\Enum\LessonLogVisibility;
use App\Mcp\McpTimetable;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;
use App\Repository\LessonLogRepository;
use App\Security\Voter\LessonLogVoter;
use App\Service\SeanceContentResolver;
use App\Util\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Writes a séance's cahier de texte - the text the teacher approved in the conversation, part by
 * part. Only the teacher who delivers the créneau (or their co-animator) may: the same
 * LessonLogVoter::EDIT the screen asks.
 *
 * Three rules of its own, all of them the connector's « nothing is deleted »:
 * - a part that already says something is **not overwritten** unless the call says `replace` - the
 *   teacher's own words are not replaced by a model's without the teacher having asked;
 * - a part the call does not name is left exactly as it is;
 * - the visibility of a part moves only when the call names it. A cahier de texte opened here for
 *   the first time is therefore hidden from the students, like one opened on the screen, until the
 *   teacher publishes it - here on request, or in MonCampus.
 *
 * The text arrives as Markdown and is stored as the editor's HTML, through the same restricted
 * rendering and the same sanitizer as the library's content (`app.library_content`): a cahier de
 * texte is read by students, and what a model wrote does not reach them unfiltered.
 */
final readonly class LessonLogWriteTool implements McpTool
{
    private const int MAX_LENGTH = 20000;

    /** The visibilities a call may set: « programmée » needs a date, which is the screen's job. */
    private const array SETTABLE = [LessonLogVisibility::Hidden, LessonLogVisibility::AfterSession, LessonLogVisibility::Now];

    public function __construct(
        private McpTimetable $timetable,
        private LessonLogRepository $logs,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        #[Target('app.library_content')] private HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function name(): string
    {
        return 'lesson_log_write';
    }

    public function title(): string
    {
        return 'Écrire le cahier de texte d\'une séance';
    }

    public function description(): string
    {
        return 'Écrit le cahier de texte d\'une séance que l\'enseignant assure (sessionId, voir timetable_get). Trois parties, en Markdown simple (paragraphes, listes, gras, tableaux) : « before » (travail à faire avant la séance), « during » (contenu de la séance), « after » (travail à faire après). Seules les parties fournies sont écrites. Une partie qui contient déjà du texte n\'est remplacée que si replace vaut true. La visibilité pour les étudiants ne change que si elle est fournie (hidden, after_session = à la fin de la séance, now) ; un cahier de texte ouvert ici pour la première fois reste masqué aux étudiants. À n\'appeler qu\'après que l\'enseignant a validé le texte.';
    }

    public function inputSchema(): array
    {
        $part = ['type' => 'string', 'maxLength' => self::MAX_LENGTH];
        $visibility = ['type' => 'string', 'enum' => array_map(static fn (LessonLogVisibility $v): string => $v->value, self::SETTABLE)];

        return [
            'type' => 'object',
            'properties' => [
                'sessionId' => ['type' => 'integer', 'description' => 'La séance (voir timetable_get).'],
                'before' => $part + ['description' => 'Travail à faire avant la séance (Markdown).'],
                'during' => $part + ['description' => 'Contenu de la séance, ce qui a été fait (Markdown).'],
                'after' => $part + ['description' => 'Travail à faire après la séance (Markdown).'],
                'replace' => ['type' => 'boolean', 'default' => false, 'description' => 'Remplacer une partie qui contient déjà du texte. Uniquement si l\'enseignant l\'a demandé.'],
                'visibilityBefore' => $visibility,
                'visibilityDuring' => $visibility,
                'visibilityAfter' => $visibility,
            ],
            'required' => ['sessionId'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function features(): array
    {
        return [Feature::LessonLog];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $session = $this->timetable->session($call->requiredId('sessionId'), LessonLogVoter::EDIT);

        // Everything is read and checked before anything is written: a refused part leaves the
        // cahier de texte as it was, never half-written.
        $contents = [];
        $visibilities = [];
        foreach (LessonLogSection::cases() as $section) {
            $markdown = trim($call->arguments->string($section->value));
            if ('' !== $markdown) {
                $contents[$section->value] = $this->html($markdown, $section);
            }

            $key = 'visibility'.ucfirst($section->value);
            if ('' !== $call->arguments->string($key)) {
                $visibility = LessonLogVisibility::tryFrom($call->arguments->string($key));
                if (null === $visibility || !\in_array($visibility, self::SETTABLE, true)) {
                    throw new McpToolException(\sprintf('« %s » vaut hidden, after_session ou now.', $key));
                }
                $visibilities[$section->value] = $visibility;
            }
        }

        if ([] === $contents && [] === $visibilities) {
            throw new McpToolException('Rien à écrire : fournissez au moins une partie (before, during, after) ou une visibilité.');
        }

        $log = $this->logs->findOneBySession($session);
        $isNew = null === $log;
        $log ??= new LessonLog($session);

        $replace = $call->arguments->bool('replace');
        $occupied = [];
        foreach (array_keys($contents) as $part) {
            if (!$replace && SeanceContentResolver::saysSomething($log->getContent(LessonLogSection::from($part)))) {
                $occupied[] = McpTimetable::sectionLabel(LessonLogSection::from($part));
            }
        }
        if ([] !== $occupied) {
            throw new McpToolException('Ces parties contiennent déjà du texte ; rien n\'a été enregistré. Relisez-les avec lesson_log_get, montrez-les à l\'enseignant, et ne renvoyez avec replace=true que s\'il demande de les remplacer :', $occupied);
        }

        foreach ($contents as $part => $html) {
            match (LessonLogSection::from($part)) {
                LessonLogSection::Before => $log->setTravailAvantDescription($html),
                LessonLogSection::During => $log->setContenuRealise($html),
                LessonLogSection::After => $log->setTravailApresDescription($html),
            };
        }
        foreach ($visibilities as $part => $visibility) {
            $log->setVisibility(LessonLogSection::from($part), $visibility);
        }

        if ($isNew) {
            $log->setCreatedBy($call->user);
        } else {
            $log->setLastUpdatedBy($call->user);
            $log->setLastUpdatedDate($this->clock->now());
        }
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $url = $this->timetable->logUrl($session);
        $parts = $this->timetable->logParts($log);

        return McpToolResult::data(
            \sprintf(
                'Cahier de texte du %s (%s) enregistré : %s. Visibilité pour les étudiants - %s. À vérifier ici : %s',
                $session->getDay()?->format('d/m/Y'),
                $session->getDisplayName(),
                [] === $contents ? 'visibilité seule' : implode(', ', array_map(fn (string $part): string => McpTimetable::sectionLabel(LessonLogSection::from($part)), array_keys($contents))),
                implode(', ', array_map(fn (LessonLogSection $section): string => McpTimetable::sectionLabel($section).' : '.McpTimetable::visibilityLabel($log, $section), LessonLogSection::cases())),
                $url,
            ),
            [
                'sessionId' => $session->getId(),
                'url' => $url,
                'written' => array_keys($contents),
                'parts' => $parts,
            ],
            ['kind' => 'lesson_log', 'id' => (int) $log->getId()],
        );
    }

    private function html(string $markdown, LessonLogSection $section): string
    {
        if (mb_strlen($markdown) > self::MAX_LENGTH) {
            throw new McpToolException(\sprintf('La partie « %s » dépasse %d caractères.', McpTimetable::sectionLabel($section), self::MAX_LENGTH));
        }

        $html = $this->sanitizer->sanitize((string) MarkdownRenderer::toRichHtml($markdown));

        if (!SeanceContentResolver::saysSomething($html)) {
            throw new McpToolException(\sprintf('La partie « %s » ne contient aucun texte une fois mise en forme.', McpTimetable::sectionLabel($section)));
        }

        return $html;
    }
}
