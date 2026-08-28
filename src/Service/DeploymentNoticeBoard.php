<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DeploymentNotice;
use App\Enum\DeploymentOutcome;
use App\Repository\DeploymentNoticeRepository;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * « Une mise à jour est en cours » - whether a deployment is under way, and the two gestures that
 * say so.
 *
 * The deploy workflow raises the notice where it announces the start on Discord and lowers it where
 * it announces the outcome, so the banner covers exactly the window the channel already describes.
 * In between, the container is replaced: see App\Entity\DeploymentNotice for why that decides the
 * notice lives in the database and nowhere else.
 *
 * **The memoisation is reset between requests, and that is not optional here.** FrankenPHP serves in
 * worker mode: the container outlives the request, so a service that remembers an answer without
 * implementing ResetInterface hands the *previous* request's answer to the next visitor. On this
 * particular value that would mean a banner that appears - or refuses to disappear - for one worker
 * and not another, for as long as that worker lives. Measured, on this codebase, 2026-08-28.
 */
class DeploymentNoticeBoard implements ResetInterface
{
    /**
     * How long a notice nobody closed goes on being shown. Deploys measured between 7.5 and 10.3
     * minutes (median 9.1), and the banner is a warning about the next quarter of an hour - so this
     * is generous enough that a slow deploy never loses its banner, and short enough that a run
     * which died between the two calls stops lying before the next lesson.
     */
    public const int DEFAULT_WINDOW_MINUTES = 30;

    private bool $read = false;

    private ?DeploymentNotice $current = null;

    public function __construct(
        private readonly DeploymentNoticeRepository $notices,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Announces a deployment. Any notice still open is closed first: a second deploy starting while
     * one is somehow still announced must leave one banner behind, not two rows racing to be found.
     */
    public function open(?string $version = null, int $windowMinutes = self::DEFAULT_WINDOW_MINUTES): DeploymentNotice
    {
        $now = new \DateTimeImmutable();

        foreach ($this->notices->findAllOpen($now) as $stale) {
            $stale->finish($now, DeploymentOutcome::Failed);
        }

        $notice = new DeploymentNotice(
            $now,
            $now->add(new \DateInterval(\sprintf('PT%dM', max(1, $windowMinutes)))),
            $version,
        );

        $this->entityManager->persist($notice);
        $this->entityManager->flush();
        $this->reset();

        return $notice;
    }

    /**
     * Ends the deployment under way, whatever it did. Answers false when there was nothing open -
     * an expired notice, or a second announcement of the same end, neither of which is an error the
     * workflow should be told about.
     */
    public function close(DeploymentOutcome $outcome): bool
    {
        $now = new \DateTimeImmutable();
        $open = $this->notices->findAllOpen($now);

        foreach ($open as $notice) {
            $notice->finish($now, $outcome);
        }

        $this->entityManager->flush();
        $this->reset();

        return [] !== $open;
    }

    /**
     * The deployment a visitor should be warned about, read once per request.
     *
     * A database that will not answer is answered « rien à signaler » rather than allowed through.
     * This is asked on every page of the application, the login screen included, and a banner is
     * never worth turning a reachable site into a 500 - the same trade App\Service\Changelog makes
     * with a changelog it cannot parse.
     */
    public function current(): ?DeploymentNotice
    {
        if (!$this->read) {
            try {
                $this->current = $this->notices->findCurrent(new \DateTimeImmutable());
            } catch (DBALException|ORMException) {
                $this->current = null;
            }
            $this->read = true;
        }

        return $this->current;
    }

    public function reset(): void
    {
        $this->read = false;
        $this->current = null;
    }
}
