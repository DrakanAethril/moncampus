<?php

declare(strict_types=1);

namespace App\Service\Guest;

/**
 * Turning a person's name into a Unix login.
 *
 * Pure and separate from everything else because it is the one piece of this whole feature that has
 * to agree with itself across time: a login computed one way in September and another way in
 * October gives the same student two accounts on the same machine, and the syncer would dutifully
 * propose to remove the first.
 *
 * The rules are deliberately narrow - lowercase ASCII letters, digits, and a hyphen, starting with
 * a letter, 32 characters - because that is what useradd accepts everywhere without argument, and
 * accented names are ordinary here.
 */
class UnixLogin
{
    private const int MAX_LENGTH = 32;

    /** Reserved on any Linux; a student called Sarah Yourdon must not become `sys`. */
    private const array RESERVED = ['root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail', 'news', 'proxy', 'www-data', 'backup', 'nobody', 'systemd', 'sshd', 'admin'];

    public function fromName(string $firstname, string $lastname): string
    {
        $first = $this->asciify($firstname);
        $last = $this->asciify($lastname);

        // firstname.lastname is what a school directory reads like, but the dot is not universally
        // welcome in a login, so the two are joined by a hyphen: `marie-dupont`.
        $login = trim($first.'-'.$last, '-');

        if ('' === $login) {
            $login = 'user';
        }

        // A login must not begin with a digit - some tools accept it, useradd warns, and the ones
        // that break do so much later.
        if (ctype_digit($login[0])) {
            $login = 'u'.$login;
        }

        $login = rtrim(substr($login, 0, self::MAX_LENGTH), '-');

        return \in_array($login, self::RESERVED, true) ? $login.'-u' : $login;
    }

    /** Whether a login somebody typed is one useradd would accept. */
    public function isValid(string $login): bool
    {
        if ('' === $login || \strlen($login) > self::MAX_LENGTH || \in_array($login, self::RESERVED, true)) {
            return false;
        }

        return 1 === preg_match('/^[a-z][a-z0-9-]*$/', $login);
    }

    private function asciify(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $ascii = false === $ascii ? $value : $ascii;

        // iconv's transliteration writes things like `"e` for `ë` depending on the locale, so
        // everything that is not a letter or a digit goes, rather than being mapped one by one.
        return trim(strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii)), '-');
    }
}
