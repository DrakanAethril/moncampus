<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameAnswer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The detail of one student's answers in a game, for the teachers of their classes and the
 * administrators (design/validated/serious-games.md §8): their typical errors, their « je ne sais
 * pas », and the questions they had - each one replayable exactly, since a question is its seed and
 * its template version. No behavioural data: neither the hour played nor the hesitations.
 */
final readonly class SeriousGameStudentReading
{
    public const int ANSWERS = 60;

    public function __construct(
        private SeriousGamePlay $play,
        private SeriousGameClassReading $classReading,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function forStudent(User $student, SeriousGame $game): array
    {
        $manifest = SeriousGamePlay::manifest($game);
        $source = $this->play->source($game);

        /** @var list<SeriousGameAnswer> $answers */
        $answers = $this->entityManager->createQueryBuilder()
            ->select('a', 's')
            ->from(SeriousGameAnswer::class, 'a')
            ->innerJoin('a.session', 's')
            ->andWhere('s.game = :game')
            ->andWhere('s.player = :player')
            ->andWhere('a.answeredAt IS NOT NULL')
            ->setParameter('game', $game)
            ->setParameter('player', $student)
            ->orderBy('a.answeredAt', 'DESC')
            ->setMaxResults(self::ANSWERS)
            ->getQuery()
            ->getResult();

        return [
            'game' => $game,
            'errors' => $this->classReading->errors($game, [$student]),
            'answers' => array_map(static fn (SeriousGameAnswer $a): array => [
                'answer' => $a,
                'mode' => $a->getSession()->getMode()->value,
                'unitName' => $manifest->unit($a->getUnitCode())['label'] ?? $a->getUnitCode(),
                'misconceptionLabel' => null === $a->getMisconception() ? null : $source->version($a->getUnitCode(), $a->getTemplateVersion())?->misconceptionLabel($a->getMisconception()),
            ], $answers),
        ];
    }

    /**
     * The question exactly as the student had it, with what they answered.
     *
     * @return array<string, mixed>
     */
    public function replay(SeriousGameAnswer $answer): array
    {
        $game = $answer->getSession()->getGame();
        $question = $this->play->question($game, ['unit' => $answer->getUnitCode(), 'version' => $answer->getTemplateVersion(), 'seed' => $answer->getSeed()]);
        $template = $this->play->source($game)->version($answer->getUnitCode(), $answer->getTemplateVersion());

        return [
            'answer' => $answer,
            'question' => $question,
            'unitName' => SeriousGamePlay::manifest($game)->unit($answer->getUnitCode())['label'] ?? $answer->getUnitCode(),
            'misconceptionLabel' => null === $answer->getMisconception() ? null : $template?->misconceptionLabel($answer->getMisconception()),
        ];
    }
}
