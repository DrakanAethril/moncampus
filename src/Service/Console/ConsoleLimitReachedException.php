<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * Four consoles are already open, and this would be the fifth.
 *
 * The ceiling is a decision rather than a forgotten setting: each open console holds one FrankenPHP
 * worker for the length of its exchange, and a thread blocked for eight seconds is not a free
 * thread. `CONSOLE_MAX_SESSIONS` is the number, and the same kind of truth as `memory_limit` at
 * 256M in production - a value that looks like nothing and without which the thing falls over.
 *
 * **It carries who holds the others.** An anonymous ceiling is a breakdown; a named one is a
 * conversation - « fermez-en une, ou demandez-leur ».
 */
class ConsoleLimitReachedException extends \RuntimeException
{
    /** @param list<string> $holders one line per person, naming the machines they are on */
    public function __construct(public readonly array $holders)
    {
        parent::__construct('consoleLimitReachedMessage');
    }
}
