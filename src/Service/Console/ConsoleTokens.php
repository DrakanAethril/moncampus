<?php

declare(strict_types=1);

namespace App\Service\Console;

/**
 * The placeholders a snippet carries, filled at the moment it is inserted.
 *
 * Five, and the list is closed: `{ip}`, `{hostname}`, `{login}`, `{batch}`, `{teacher}`. They are
 * the things the platform knows and the person would otherwise have to read off the screen and
 * retype.
 *
 * **Anything else is left alone**, and that is the useful half: `{service}`, `{paquet}`,
 * `{conteneur}` are blanks the person completes, which is exactly why the palette inserts on Enter
 * and only runs on Alt+Enter. A token substitution that silently emptied them would turn
 * `systemctl status {service}` into `systemctl status`, which runs and says nothing.
 */
final class ConsoleTokens
{
    public const array NAMES = ['ip', 'hostname', 'login', 'batch', 'teacher'];

    /** @param array<string, string> $values keyed by token name, without the braces */
    public static function fill(string $command, array $values): string
    {
        foreach (self::NAMES as $name) {
            $value = $values[$name] ?? null;

            if (null !== $value && '' !== $value) {
                $command = str_replace('{'.$name.'}', $value, $command);
            }
        }

        return $command;
    }
}
