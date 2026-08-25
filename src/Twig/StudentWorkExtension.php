<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Service\StudentWorkBoard;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Powers the « Travail à faire » tab of the student bar, which is only offered when the screen
 * behind it has something on it.
 *
 * **It asks App\Service\StudentWorkBoard, the very builder the screen is drawn from**, rather than
 * counting assignments itself. That is the whole point: the board is where the state rule lives -
 * an audience, an access condition, a work set aside, a deadline already met - and a cheaper count
 * beside it would offer a tab onto an empty page the day one of those rules dropped the last line.
 *
 * It costs what the board costs, once per request and only for a student who has the feature at
 * all, the Twig `and` before it short-circuiting for everybody else. The home page pays it twice,
 * the dashboard building its own board from the controller; that is the price of the two never
 * disagreeing.
 */
class StudentWorkExtension extends AbstractExtension implements ResetInterface
{
    private ?bool $hasWork = null;

    public function __construct(
        private readonly StudentWorkBoard $board,
        private readonly Security $security,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('student_has_work', $this->hasWork(...)),
        ];
    }

    public function hasWork(): bool
    {
        if (null !== $this->hasWork) {
            return $this->hasWork;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return $this->hasWork = false;
        }

        return $this->hasWork = [] !== $this->board->build($user);
    }

    #[\Override]
    public function reset(): void
    {
        $this->hasWork = null;
    }
}
