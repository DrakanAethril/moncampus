<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\EcoCourseRepository;

// Generates the 6-character course join code shown on 1g/1d (e.g. "7GX4K2"), from the alphabet
// EcoRandomCode shares with the checkpoint codes (screen 3d's join field).
class EcoCourseCodeGenerator
{
    private const int LENGTH = 6;

    public function __construct(private readonly EcoCourseRepository $courseRepository)
    {
    }

    public function generate(): string
    {
        do {
            $code = EcoRandomCode::draw(self::LENGTH);
        } while (null !== $this->courseRepository->findOneByCode($code));

        return $code;
    }
}
