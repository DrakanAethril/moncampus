<?php

declare(strict_types=1);

namespace App\OAuth;

/**
 * The scopes the connector understands.
 *
 * One real scope today, `library`: everything the first lot of tools does. `offline_access` is
 * announced because claude.ai asks for it when it is listed, and it is what the refresh token
 * already is - every grant gets one, so the scope changes nothing but the answer to the question.
 * A later lot that reads students' data would be a new scope, and a new consent.
 */
final class ConnectorScope
{
    public const string LIBRARY = 'library';

    public const string OFFLINE_ACCESS = 'offline_access';

    /** @var list<string> */
    public const array SUPPORTED = [self::LIBRARY, self::OFFLINE_ACCESS];

    /**
     * The scope a grant is recorded with, from what the client asked for - or null when it asked for
     * something this server does not know. An empty request means the default scope.
     */
    public static function normalize(string $requested): ?string
    {
        $scopes = array_values(array_unique(array_filter(preg_split('/\s+/', trim($requested)) ?: [], static fn (string $scope): bool => '' !== $scope)));

        if ([] === $scopes) {
            return self::LIBRARY;
        }

        if ([] !== array_diff($scopes, self::SUPPORTED)) {
            return null;
        }

        if (!\in_array(self::LIBRARY, $scopes, true)) {
            array_unshift($scopes, self::LIBRARY);
        }

        return implode(' ', $scopes);
    }
}
