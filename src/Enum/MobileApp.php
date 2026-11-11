<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which companion app opened a mobile session - what « Mon profil » names each connected phone by.
 * Declared by the app at sign-in (`client` in the body); an app too old to say is « Other ».
 */
enum MobileApp: string
{
    case Campus = 'moncampus';
    // The same app compiled for the browser, once served at /campus-app/ and retired since the
    // site itself became the installable app (« Formatool », design/validated/quiz-tournament.md
    // §13). Kept so that its last sessions stay listed apart in « Mon profil » until they expire,
    // and so that a browser still running it is sent the site's own sign-in link
    // (Api\MagicLoginController).
    case CampusWeb = 'moncampus-web';
    case Eco = 'eco';
    case Other = 'other';

    public static function fromDeclared(string $declared): self
    {
        return self::tryFrom($declared) ?? self::Other;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Campus => 'mobileAppCampusLabel',
            self::CampusWeb => 'mobileAppCampusWebLabel',
            self::Eco => 'mobileAppEcoLabel',
            self::Other => 'mobileAppOtherLabel',
        };
    }
}
