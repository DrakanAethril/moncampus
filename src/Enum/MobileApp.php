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
    // The same app compiled for the browser (public/campus-app/): its sessions are listed apart,
    // a browser being signed out on its own.
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
