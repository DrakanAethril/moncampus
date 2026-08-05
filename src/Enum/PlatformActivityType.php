<?php

namespace App\Enum;

/**
 * Ce qu'une ligne de App\Entity\PlatformActivity raconte - le journal hors UFA. Même mécanique
 * d'extension que App\Enum\UfaActivityType : un case, une clé, un appel au recorder.
 *
 * Volontairement limité aux connexions réussies pour l'instant : les échecs de connexion ne sont
 * pas journalisés (décision produit - ils porteraient sur des identifiants inexistants et
 * changeraient la nature de la table), et la déconnexion non plus.
 */
enum PlatformActivityType: string
{
    case LoginPassword = 'login_password';
    case LoginMagicLink = 'login_magic_link';

    /**
     * An admin removed an unlinked school mail from the platform (screen 5a). Logged because it is
     * somebody else's incoming mail being erased from the app - the raw `.eml` stays on S3, and the
     * payload keeps the keys that make it findable again.
     */
    case SchoolMailUnlinkedDeleted = 'school_mail_unlinked_deleted';

    /** Placeholder disponible : %user%. */
    public function messageKey(): string
    {
        return match ($this) {
            self::LoginPassword => 'platformActivityLoginPasswordText',
            self::LoginMagicLink => 'platformActivityLoginMagicLinkText',
            self::SchoolMailUnlinkedDeleted => 'platformActivitySchoolMailUnlinkedDeletedText',
        };
    }
}
