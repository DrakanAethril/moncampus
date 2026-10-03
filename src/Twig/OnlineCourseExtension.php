<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\LearningPathEnrollmentRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * « Cours en ligne » in Twig:
 *
 * - public_source_url(): the address of the source code. AGPL §13 asks that anybody using the
 *   application over a network can obtain it, and a visitor of a public course page is using it
 *   without ever seeing « À propos »;
 * - has_started_learning_path(): whether the signed-in person follows a path - what the menu entry
 *   « Parcours » waits for, so it never opens on an empty list. Asked fresh every time: a memo here
 *   would outlive its request in worker mode.
 */
class OnlineCourseExtension extends AbstractExtension
{
    /**
     * @param array<string, mixed> $about
     */
    public function __construct(
        #[Autowire(param: 'app.about')]
        private readonly array $about,
        private readonly Security $security,
        private readonly LearningPathEnrollmentRepository $enrollments,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('public_source_url', fn (): string => \is_string($this->about['source_url'] ?? null) ? $this->about['source_url'] : ''),
            new TwigFunction('has_started_learning_path', $this->hasStartedLearningPath(...)),
        ];
    }

    private function hasStartedLearningPath(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->enrollments->hasAnyForUser($user);
    }
}
