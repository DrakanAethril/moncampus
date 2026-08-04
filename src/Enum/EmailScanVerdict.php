<?php

namespace App\Enum;

/**
 * Verdicts d'analyse SES sur un message entrant, lus dans les en-têtes `X-SES-Spam-Verdict` et
 * `X-SES-Virus-Verdict` que SES ajoute au `.eml` déposé sur S3 (analyse activée sur les règles
 * de réception catch-all).
 *
 * Rien n'est jamais rejeté sur la foi d'un verdict : S3 reste la source de vérité et le message
 * est stocké quoi qu'il arrive. Le verdict ne décide que de la mise en quarantaine à l'affichage.
 */
enum EmailScanVerdict: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case Gray = 'GRAY';
    case ProcessingFailed = 'PROCESSING_FAILED';

    /** Tolérant par construction : un en-tête absent ou inconnu ne doit pas faire échouer le worker. */
    public static function fromHeader(?string $value): ?self
    {
        return null !== $value ? self::tryFrom(strtoupper(trim($value))) : null;
    }

    /** Seul FAIL sur le virus justifie de ne pas mettre la pièce à disposition. */
    public function isDangerous(): bool
    {
        return self::Fail === $this;
    }
}
