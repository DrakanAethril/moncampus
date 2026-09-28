<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Symfony's LockableTrait, fixed for the two things the scheduler's worker changed.
 *
 * - **The same command runs many times in one process.** A command is a shared service, and the
 *   worker keeps it for an hour. LockableTrait releases only when asked, and none of these commands
 *   asked: under cron that cost nothing, the process died with its lock. In the worker, the second
 *   run met its own first lock and threw « A lock is already in place », and a manual run was
 *   refused for as long as the worker lived. Here the lock is released when run() returns, however
 *   execute() left.
 * - **The lock must be seen from the other container.** LockableTrait builds its own store on the
 *   local /tmp, which the `php` container (a manual run, a web screen) and the `worker` container do
 *   not share. This takes the application's LockFactory instead - LOCK_DSN, a flock directory on a
 *   volume both containers mount (compose.prod.yaml) - so a manual run still cannot land on top of
 *   a scheduled one.
 *
 * Same two methods as LockableTrait, so a command switches by changing its `use` line alone.
 */
trait SharedLockableTrait
{
    private ?LockFactory $sharedLockFactory = null;
    private ?LockInterface $heldLock = null;

    #[Required]
    public function setSharedLockFactory(LockFactory $lockFactory): void
    {
        $this->sharedLockFactory = $lockFactory;
    }

    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } finally {
            $this->release();
        }
    }

    /**
     * Takes the lock named after the command, without waiting: false means another run holds it.
     */
    private function lock(): bool
    {
        if (null === $this->sharedLockFactory) {
            throw new \LogicException(\sprintf('%s needs a LockFactory: it is set by autowiring, see setSharedLockFactory().', static::class));
        }

        $lock = $this->sharedLockFactory->createLock((string) $this->getName());

        if (!$lock->acquire()) {
            return false;
        }

        $this->heldLock = $lock;

        return true;
    }

    private function release(): void
    {
        $this->heldLock?->release();
        $this->heldLock = null;
    }
}
