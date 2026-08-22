<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Entity\User;
use App\Repository\ConsoleSessionRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a console session proposes to the day's lesson log.
 *
 * The last gesture of §7.6, and the one that costs nothing to build because there is no new screen
 * in it: the lesson log of the day is opened *pre-filled*, and what fills it is a draft nobody has
 * saved yet - exactly as if the teacher had typed it.
 *
 * **The commands, not the transcript.** A lesson log is read: four screens of apt output are not
 * read, and pasting them would make the log worse rather than richer. The commands are the story of
 * what the class did, and they are already extracted for the palette
 * (App\Service\Console\ConsoleHistory) - the same reading, put to a second use.
 *
 * Answers null for anything that is not this person's own open console: this is reached with an id
 * from a query string, and there is no reason it should ever read somebody else's session.
 */
class ConsoleLessonLogDraft
{
    /** Enough to describe a session; past that, a lesson log is being used as a log file. */
    private const int MAX_COMMANDS = 40;

    public function __construct(
        private readonly ConsoleSessionRepository $sessions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function contentFor(?int $sessionId, User $reader): ?string
    {
        if (null === $sessionId) {
            return null;
        }

        $session = $this->sessions->find($sessionId);

        if (null === $session || $session->getOpenedBy()?->getId() !== $reader->getId()) {
            return null;
        }

        $commands = ConsoleHistory::extract([$session->getTranscript() ?? '']);

        if ([] === $commands) {
            return null;
        }

        // HTML, because the field is edited by HugeRTE and a plain-text paste there arrives as one
        // long line. A list is what somebody would have written by hand.
        $lines = array_map(
            static fn (string $command): string => '<li><code>'.htmlspecialchars($command, \ENT_QUOTES).'</code></li>',
            // Oldest first here, unlike the palette: a lesson log tells the séance in order.
            array_reverse(\array_slice($commands, 0, self::MAX_COMMANDS)),
        );

        return \sprintf(
            '<p>%s</p><ul>%s</ul>',
            htmlspecialchars($this->translator->trans('consoleLessonLogIntroText', [
                '%machine%' => $session->getGuestName() ?? \sprintf('VM %d', $session->getVmid()),
            ]), \ENT_QUOTES),
            implode('', $lines),
        );
    }
}
