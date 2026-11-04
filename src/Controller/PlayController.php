<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Entity\User;
use App\Enum\Feature;
use App\Service\Play\PlayHub;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Jouer », the student's page of games (design/validated/quiz-tournament.md §15): the tournaments
 * that reach them, then the serious games open to them, each card in its content's own dress.
 *
 * It belongs to two features at once and exists while either is lit (RequiresFeature reads them as
 * an OR); past that, it is the student bar's entry's own rule (PlayHub::isOpenTo()) - a pure student
 * with something to play - and a 404 for anybody else, an empty page being an entry that should not
 * have been drawn.
 */
#[RequiresFeature(Feature::QuizTournaments, Feature::SeriousGames)]
class PlayController extends AbstractController
{
    #[Route(path: '/play', name: 'app_play', methods: ['GET'])]
    public function index(PlayHub $hub, ClockInterface $clock): Response
    {
        $user = $this->getUser();
        $now = $clock->now();
        if (!$user instanceof User || !$hub->isOpenTo($user, $now)) {
            throw $this->createNotFoundException();
        }

        return $this->render('play/index.html.twig', [
            'tournaments' => $hub->tournamentCards($user, $now),
            'games' => $hub->gameCards($user),
        ]);
    }
}
