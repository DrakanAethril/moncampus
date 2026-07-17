<?php

namespace App\Service;

use App\Repository\EcoCourseRepository;

// Generates the 6-character course join code shown on 1g/1d (e.g. "7GX4K2") - excludes 0/O/1/I to
// avoid ambiguity when a runner reads it off a screen or has it read aloud (screen 3d's join
// field).
class EcoCourseCodeGenerator
{
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const int LENGTH = 6;

    public function __construct(private readonly EcoCourseRepository $courseRepository)
    {
    }

    public function generate(): string
    {
        do {
            $code = $this->randomCode();
        } while (null !== $this->courseRepository->findOneByCode($code));

        return $code;
    }

    private function randomCode(): string
    {
        $alphabetLength = \strlen(self::ALPHABET);
        $code = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $code .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $code;
    }
}
