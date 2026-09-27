<?php

declare(strict_types=1);

namespace App\EcoleDirecte;

use App\Entity\LessonSession;
use App\Entity\User;
use App\Repository\LessonLogRepository;
use App\Repository\LessonSessionRepository;

/**
 * Sends a teacher's MonCampus cahier de texte to their École Directe one.
 *
 * Two steps, and the second redoes the first: preview() reads École Directe and says what would
 * change (App\EcoleDirecte\EcoleDirecteLessonLogPlanner); send() reads it *again* and sends only the
 * slots the teacher ticked, as they stand at that moment - a slot filled in École Directe between the
 * preview and the click is compared afresh rather than overwritten on the strength of a stale screen.
 *
 * Each part is its own call, the way École Directe's website saves them: the whole slot goes back
 * with `verbe=put`, only its `contenu` changed (base64-encoded HTML). A refused part is reported and
 * the next one still goes; an expired connection stops everything.
 *
 * Only the séances the teacher gives themselves are read - LessonSession::$teacher - so nobody sends
 * a colleague's cahier de texte.
 */
class EcoleDirecteLessonLogWriter
{
    /** How far past the span École Directe is read, to find the lessons before and after. */
    private const int LOOKAROUND_DAYS = 14;

    /** École Directe's website refuses more than this, encoded. */
    private const int MAX_ENCODED_BYTES = 1_048_576;

    public function __construct(
        private readonly EcoleDirecteClient $client,
        private readonly EcoleDirecteLessonLogReader $reader,
        private readonly EcoleDirecteLessonLogPlanner $planner,
        private readonly LessonSessionRepository $lessonSessions,
        private readonly LessonLogRepository $lessonLogs,
    ) {
    }

    /**
     * @return array{targets: list<EcoleDirecteLessonLogTarget>, unmatched: list<EcoleDirecteLessonLogEntry>, unplaced: list<EcoleDirecteLessonLogEntry>, session: EcoleDirecteSession}
     *
     * @throws EcoleDirecteException
     */
    public function preview(User $teacher, EcoleDirecteSession $session, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        EcoleDirecteLessonLogReader::assertSpan($from, $to);

        $raw = $this->reader->rawSlots(
            $session,
            $from->modify(\sprintf('-%d days', self::LOOKAROUND_DAYS)),
            $to->modify(\sprintf('+%d days', self::LOOKAROUND_DAYS)),
        );

        return [...$this->planner->plan($this->entries($teacher, $from, $to), $raw['rows']), 'session' => $raw['session']];
    }

    /**
     * @param list<string> $keys the slots the teacher ticked on the preview
     *
     * @return array{results: list<array{target: EcoleDirecteLessonLogTarget, part: 'seance'|'afaire', ok: bool, messageKey: string, apiMessage: string}>, session: EcoleDirecteSession}
     *
     * @throws EcoleDirecteException when the connection itself fails - a refused part does not throw
     */
    public function send(User $teacher, EcoleDirecteSession $session, \DateTimeImmutable $from, \DateTimeImmutable $to, array $keys): array
    {
        $preview = $this->preview($teacher, $session, $from, $to);
        $session = $preview['session'];
        $results = [];

        foreach ($preview['targets'] as $target) {
            if (!\in_array($target->key, $keys, true) || !$target->sendsSomething()) {
                continue;
            }

            $slot = $target->slot;

            if (null !== $target->contentHtml && true === $target->contentState?->sends()) {
                [$slot, $session, $results[]] = $this->sendPart($session, $target, $slot, 'seance', self::withContent($slot, $target->contentHtml));
            }

            if (null !== $target->homeworkHtml && true === $target->homeworkState?->sends()) {
                [$slot, $session, $results[]] = $this->sendPart($session, $target, $slot, 'afaire', self::withHomework($slot, $target->homeworkHtml, $target->homeworkGivenOn));
            }
        }

        return ['results' => $results, 'session' => $session];
    }

    /**
     * @param array<array-key, mixed> $slot
     * @param 'seance'|'afaire'       $part
     * @param array<string, mixed>    $body
     *
     * @return array{0: array<array-key, mixed>, 1: EcoleDirecteSession, 2: array{target: EcoleDirecteLessonLogTarget, part: 'seance'|'afaire', ok: bool, messageKey: string, apiMessage: string}}
     *
     * @throws EcoleDirecteSessionExpiredException
     */
    private function sendPart(EcoleDirecteSession $session, EcoleDirecteLessonLogTarget $target, array $slot, string $part, array $body): array
    {
        $record = $body['seance' === $part ? 'seance' : 'aFaire'] ?? null;
        $encoded = \is_array($record) && \is_string($record['contenu'] ?? null) ? $record['contenu'] : '';
        if (\strlen($encoded) > self::MAX_ENCODED_BYTES) {
            return [$slot, $session, ['target' => $target, 'part' => $part, 'ok' => false, 'messageKey' => 'ecoleDirecteContentTooLargeMessage', 'apiMessage' => '']];
        }

        $path = \sprintf(
            'cahierdetexte/%s/%s/%s/%s.awp',
            $part,
            rawurlencode(self::text($slot['entityCode'] ?? null)),
            EcoleDirecteSubjectCode::lessonLogSegment(self::text($slot['matiereCode'] ?? null)),
            $target->date,
        );

        try {
            $result = $this->client->send($session, $path, 'put', $body);
        } catch (EcoleDirecteSessionExpiredException $exception) {
            throw $exception;
        } catch (EcoleDirecteException $exception) {
            return [$slot, $session, ['target' => $target, 'part' => $part, 'ok' => false, 'messageKey' => $exception->getMessage(), 'apiMessage' => $exception->apiMessage]];
        }

        // The slot now has a cahier de texte entry of its own: the second part must name it, as
        // École Directe's website does once its store has been updated from this answer.
        $data = \is_array($result->data) ? $result->data : [];
        if (\is_int($data['idCDT'] ?? null)) {
            $body['idCDT'] = $data['idCDT'];
        }

        return [$body, $result->session, ['target' => $target, 'part' => $part, 'ok' => true, 'messageKey' => 'ecoleDirecteSentLabel', 'apiMessage' => '']];
    }

    /**
     * The slot with its session content replaced - everything else as École Directe sent it.
     *
     * @param array<array-key, mixed> $slot
     *
     * @return array<string, mixed>
     */
    public static function withContent(array $slot, string $html): array
    {
        $body = self::keyed($slot);
        $record = \is_array($body['seance'] ?? null) ? $body['seance'] : ['documents' => [], 'commentaires' => [], 'elementsProg' => [], 'liensManuel' => []];
        $record['contenu'] = base64_encode($html);
        $record['modeEdit'] = false;
        $body['seance'] = $record;
        $body['contenuDeSeance'] = true;

        return $body;
    }

    /**
     * The slot with its homework replaced. École Directe's website resets the tags and the
     * differentiated homework from its form; here the ones already there are kept.
     *
     * @param array<array-key, mixed> $slot
     *
     * @return array<string, mixed>
     */
    public static function withHomework(array $slot, string $html, string $givenOn): array
    {
        $body = self::keyed($slot);
        $record = \is_array($body['aFaire'] ?? null) ? $body['aFaire'] : [
            'idDevoir' => 0,
            'rendreEnLigne' => false,
            'saisieLe' => '',
            'documents' => [],
            'commentaires' => [],
            'elementsProg' => [],
            'liensManuel' => [],
        ];
        $record['contenu'] = base64_encode($html);
        $record['donneLe'] = $givenOn;
        $record['rendreEnLigne'] ??= false;
        $record['tags'] ??= [];
        $record['cdtPersonnalises'] ??= [];
        $record['modeEdit'] = false;
        $body['aFaire'] = $record;
        $body['travailAFaire'] = true;

        return $body;
    }

    /**
     * @param array<array-key, mixed> $slot
     *
     * @return array<string, mixed>
     */
    private static function keyed(array $slot): array
    {
        $body = [];
        foreach ($slot as $key => $value) {
            $body[(string) $key] = $value;
        }

        return $body;
    }

    /** @return list<EcoleDirecteLessonLogEntry> */
    private function entries(User $teacher, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $sessions = $this->lessonSessions->findAllForTeacherBetween($teacher, $from, $to);

        $logs = [];
        foreach ($this->lessonLogs->findForSessions($sessions) as $log) {
            $id = $log->getLessonSession()?->getId();
            if (null !== $id) {
                $logs[$id] = $log;
            }
        }

        $entries = [];
        foreach ($sessions as $lessonSession) {
            $log = $logs[$lessonSession->getId()] ?? null;
            $id = $lessonSession->getId();
            $day = $lessonSession->getDay();
            $start = $lessonSession->getStartHour();
            if (null === $log || null === $id || null === $day || null === $start) {
                continue;
            }

            $entries[] = new EcoleDirecteLessonLogEntry(
                $id,
                $day->format('Y-m-d'),
                $start->format('H:i'),
                self::label($lessonSession),
                $log->getContenuRealise() ?? '',
                $log->getTravailAvantDescription() ?? '',
                $log->getTravailApresDescription() ?? '',
            );
        }

        return $entries;
    }

    private static function label(LessonSession $session): string
    {
        return trim(\sprintf(
            '%s %s · %s%s',
            $session->getDay()?->format('d/m') ?? '',
            $session->getStartHour()?->format('H:i') ?? '',
            $session->getProgram()?->getDisplayShortName() ?? '',
            null !== $session->getTopic() ? ' · '.$session->getTopic()->getName() : '',
        ));
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
