<?php

declare(strict_types=1);

namespace App\Service\Eco;

use App\Entity\EcoCheckpoint;
use App\Entity\EcoParcours;
use App\Service\JsonRequestPayload;

/**
 * The IGN's reading of a parcours as the teacher app shows it - the same card as the web's
 * configuration screen (templates/eco/_parcours_terrain.html.twig): where the parcours is, the legs
 * as the ground makes them, and the safety sheet of every located flag.
 *
 * The analysis is a JSON snapshot (EcoParcours::$terrainAnalysis, see EcoParcoursTerrainAnalyzer
 * for its shape): it is read back here key by key rather than handed out raw, so the app gets the
 * same fields whatever an older snapshot happened to hold, and never the `routes` and
 * `fingerprint` internals.
 *
 * Whether the reading still describes the flags is `current`: a flag moved since reads the
 * analysis as out of date, which the app says, and asks for a new one.
 *
 * @phpstan-type EcoTerrainSheetLeg array{fromLabel: string, toLabel: string, straightMeters: ?float, pathMeters: ?float, pathRatio: ?float, climbMeters: ?float, descentMeters: ?float, maxSlopePercent: ?float, forestShare: ?float, effortKm: ?float}
 * @phpstan-type EcoTerrainSheetCheckpoint array{id: int, label: string, name: string, groundAltitude: ?float, canopyHeight: ?float, nearestCarRoadMeters: ?float, nearestWaterMeters: ?float, publicForest: ?string}
 * @phpstan-type EcoTerrainSheetData array{
 *     pending: bool,
 *     canAnalyze: bool,
 *     analyzedAt: ?string,
 *     current: bool,
 *     analysis: ?array{commune: ?string, nearbyPlace: ?string, publicForests: list<string>, incomplete: bool, legs: list<EcoTerrainSheetLeg>, checkpoints: list<EcoTerrainSheetCheckpoint>},
 * }
 */
final class EcoTerrainSheet
{
    /** @return EcoTerrainSheetData */
    public function of(EcoParcours $parcours): array
    {
        $pending = null !== $parcours->getTerrainRequestedAt();
        $raw = $parcours->getTerrainAnalysis();

        return [
            'pending' => $pending,
            // The web's « Analyser le terrain » rule: something must stand on the ground to read.
            'canAnalyze' => !$pending && $parcours->getLocatedCheckpointCount() > 0,
            'analyzedAt' => $parcours->getTerrainAnalyzedAt()?->format(\DateTimeInterface::ATOM),
            'current' => $parcours->hasCurrentTerrainAnalysis(),
            'analysis' => null !== $raw ? $this->analysis($parcours, JsonRequestPayload::fromArray($raw)) : null,
        ];
    }

    /** @return array{commune: ?string, nearbyPlace: ?string, publicForests: list<string>, incomplete: bool, legs: list<EcoTerrainSheetLeg>, checkpoints: list<EcoTerrainSheetCheckpoint>} */
    private function analysis(EcoParcours $parcours, JsonRequestPayload $analysis): array
    {
        $safetyById = [];
        foreach ($analysis->objects('checkpoints') as $row) {
            $safetyById[(int) $row->int('id', 0)] = $row;
        }

        $checkpoints = array_values(array_filter($parcours->getCheckpoints()->toArray(), static fn (EcoCheckpoint $checkpoint): bool => $checkpoint->isLocated()));
        usort($checkpoints, static fn (EcoCheckpoint $a, EcoCheckpoint $b): int => $a->getPosition() <=> $b->getPosition());

        return [
            'commune' => self::nullableString($analysis, 'commune'),
            'nearbyPlace' => self::nullableString($analysis, 'nearbyPlace'),
            'publicForests' => $analysis->strings('publicForests'),
            'incomplete' => $analysis->bool('incomplete'),
            'legs' => array_map(static fn (JsonRequestPayload $leg): array => [
                'fromLabel' => $leg->string('fromLabel'),
                'toLabel' => $leg->string('toLabel'),
                'straightMeters' => $leg->float('straightMeters'),
                'pathMeters' => $leg->float('pathMeters'),
                'pathRatio' => $leg->float('pathRatio'),
                'climbMeters' => $leg->float('climbMeters'),
                'descentMeters' => $leg->float('descentMeters'),
                'maxSlopePercent' => $leg->float('maxSlopePercent'),
                'forestShare' => $leg->float('forestShare'),
                'effortKm' => $leg->float('effortKm'),
            ], $analysis->objects('legs')),
            // The flags as they stand now, not as the snapshot saw them: the altitude and the
            // canopy are read per flag (EcoCheckpointTerrainReader), the road and the water come
            // from the analysis, and a flag it never saw simply has neither.
            'checkpoints' => array_map(static function (EcoCheckpoint $checkpoint) use ($safetyById): array {
                $safety = $safetyById[(int) $checkpoint->getId()] ?? null;

                return [
                    'id' => (int) $checkpoint->getId(),
                    'label' => $checkpoint->getType()->shortLetter() ?? (string) $checkpoint->getPosition(),
                    'name' => $checkpoint->getName() ?? '',
                    'groundAltitude' => $checkpoint->getGroundAltitude(),
                    'canopyHeight' => $checkpoint->getCanopyHeight(),
                    'nearestCarRoadMeters' => $safety?->float('nearestCarRoadMeters'),
                    'nearestWaterMeters' => $safety?->float('nearestWaterMeters'),
                    'publicForest' => null !== $safety ? self::nullableString($safety, 'publicForest') : null,
                ];
            }, $checkpoints),
        ];
    }

    private static function nullableString(JsonRequestPayload $payload, string $key): ?string
    {
        $value = $payload->string($key);

        return '' !== $value ? $value : null;
    }
}
