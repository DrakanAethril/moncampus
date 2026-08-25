<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The five groups the feature catalogue is read in - Paramètres > Fonctionnalités draws one block
 * per family, in this order (design/validated/feature-access.md §4).
 *
 * A presentation device, nothing more: the resolver never reads a family, and moving a feature
 * from one to another changes no answer.
 */
enum FeatureFamily: string
{
    case Pedagogy = 'pedagogy';
    case Schooling = 'schooling';
    case Communication = 'communication';
    case Alternance = 'alternance';
    case Technical = 'technical';

    public function labelKey(): string
    {
        return match ($this) {
            self::Pedagogy => 'featureFamilyPedagogyLabel',
            self::Schooling => 'featureFamilySchoolingLabel',
            self::Communication => 'featureFamilyCommunicationLabel',
            self::Alternance => 'featureFamilyAlternanceLabel',
            self::Technical => 'featureFamilyTechnicalLabel',
        };
    }
}
