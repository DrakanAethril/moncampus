<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Repository\QuizTournamentRepository;
use App\Service\Play\PlayHub;
use App\Service\QuizTournament\QuizTournamentAccess;
use App\Service\QuizTournament\QuizTournamentEligibility;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The two menu entries of the games (design/validated/quiz-tournament.md §15), each the same rule as
 * the screen behind it, so an entry never opens a 404:
 *
 * - `play_hub_visible()` - « Jouer » in a pure student's bar: a tournament reaches them or a serious
 *   game is open to them (PlayHub::isOpenTo(), which /play asks too);
 * - `quiz_tournaments_followed()` - « Tournois » under Outils › Animer la classe: an administrator
 *   once a tournament exists, a teacher once an opened tournament targets a class they teach - the
 *   tournaments the list `/tournaments` then shows them.
 *
 * Both are asked on every page, so both are held for the request and dropped between requests: the
 * FrankenPHP worker keeps this extension alive.
 */
final class PlayExtension extends AbstractExtension implements ResetInterface
{
    private ?bool $hubVisible = null;
    private ?bool $tournamentsFollowed = null;

    public function __construct(
        private readonly Security $security,
        private readonly PlayHub $hub,
        private readonly QuizTournamentAccess $tournamentAccess,
        private readonly QuizTournamentRepository $tournaments,
        private readonly ClockInterface $clock,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('play_hub_visible', $this->hubVisible(...)),
            new TwigFunction('quiz_tournaments_followed', $this->tournamentsFollowed(...)),
        ];
    }

    public function hubVisible(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->hubVisible ??= $this->hub->isOpenTo($user, $this->clock->now());
    }

    public function tournamentsFollowed(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->tournamentsFollowed ??= $this->follows($user);
    }

    private function follows(User $user): bool
    {
        if (!$this->tournamentAccess->isFeatureLit($user)) {
            return false;
        }
        if (QuizTournamentEligibility::canManage($user->getRoles())) {
            return [] !== $this->tournaments->findAllOrdered();
        }
        if (!\in_array('ROLE_TEACHER', $user->getRoles(), true)) {
            return false;
        }

        foreach ($this->tournaments->findOpened() as $tournament) {
            if ($this->tournamentAccess->teachesATargetedProgram($user, $tournament)) {
                return true;
            }
        }

        return false;
    }

    #[\Override]
    public function reset(): void
    {
        $this->hubVisible = null;
        $this->tournamentsFollowed = null;
    }
}
