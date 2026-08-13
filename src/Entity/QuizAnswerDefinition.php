<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * The two ends of an answer that anything outside the grading actually reads: which one it is, and
 * what it says.
 *
 * QuizAnswer (the library) and QuizInstanceAnswer (the frozen copy in a launched quiz) carry the
 * same two, and the questions above them have shared App\Entity\QuizQuestionDefinition since the
 * types shipped - the answers simply never needed it, because every reader had one kind or the
 * other in hand.
 *
 * They need it now: App\Service\QuizQuestionPayload builds the JSON the mobile app reads, and the
 * same question reaches it from a launched quiz (instance answers) and from a video marker (library
 * answers). Without this, that builder would exist twice, and the twelve question types would be
 * described to the app in two places free to drift.
 *
 * Deliberately this small. Everything else about an answer - whether it is correct, its feedback,
 * its order - belongs to the grading, which keeps its own concrete types.
 */
interface QuizAnswerDefinition
{
    public function getId(): ?int;

    public function getLabel(): ?string;
}
