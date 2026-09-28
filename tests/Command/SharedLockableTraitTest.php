<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SharedLockableTrait;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The lock a command takes, now that the scheduler's worker runs the same command instance again
 * and again in one process.
 *
 * The first test is the bug this trait exists for: with Symfony's LockableTrait, which never
 * released, the second run in the same process threw « A lock is already in place » - under cron
 * each run was its own process and nobody could see it.
 */
class SharedLockableTraitTest extends TestCase
{
    public function testTheSameInstanceRunsTwiceInOneProcess(): void
    {
        $command = $this->command(new LockFactory(new InMemoryStore()));

        self::assertSame('ran', $this->execute($command));
        self::assertSame('ran', $this->execute($command));
    }

    public function testAnotherHolderOfTheLockKeepsTheCommandOut(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $held = $factory->createLock('app:test:locked');
        self::assertTrue($held->acquire());

        self::assertSame('busy', $this->execute($this->command($factory)));
    }

    public function testTheLockIsFreeAgainOnceTheRunReturns(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $this->execute($this->command($factory));

        // What a manual run in the other container would try next.
        self::assertTrue($factory->createLock('app:test:locked')->acquire());
    }

    public function testTheLockIsFreeAgainWhenTheCommandThrows(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $command = $this->command($factory, throws: true);

        try {
            $this->execute($command);
            self::fail('The command was expected to throw.');
        } catch (\RuntimeException) {
        }

        self::assertTrue($factory->createLock('app:test:locked')->acquire());
    }

    private function execute(Command $command): string
    {
        $output = new BufferedOutput();
        $command->run(new ArrayInput([]), $output);

        return trim($output->fetch());
    }

    private function command(LockFactory $factory, bool $throws = false): Command
    {
        $command = new class($throws) extends Command {
            use SharedLockableTrait;

            public function __construct(private readonly bool $throws)
            {
                parent::__construct('app:test:locked');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                if (!$this->lock()) {
                    $output->writeln('busy');

                    return Command::SUCCESS;
                }

                if ($this->throws) {
                    throw new \RuntimeException('boom');
                }

                // Returns without releasing, like every command that used LockableTrait did.
                $output->writeln('ran');

                return Command::SUCCESS;
            }
        };
        $command->setSharedLockFactory($factory);

        return $command;
    }
}
