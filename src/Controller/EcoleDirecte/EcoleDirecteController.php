<?php

declare(strict_types=1);

namespace App\Controller\EcoleDirecte;

use App\Attribute\RequiresFeature;
use App\EcoleDirecte\EcoleDirecteClient;
use App\EcoleDirecte\EcoleDirecteException;
use App\EcoleDirecte\EcoleDirecteLessonLogReader;
use App\EcoleDirecte\EcoleDirecteLoginOutcome;
use App\EcoleDirecte\EcoleDirecteSession;
use App\EcoleDirecte\EcoleDirecteSessionSealer;
use App\Entity\Evaluation;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\EvaluationRepository;
use App\Security\Voter\EvaluationVoter;
use App\Service\JsonRequestPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Outils > École Directe: a teacher signs in to their own École Directe account and reads it from
 * here - the account, the cahier de texte, and (admins) any route raw. What is *sent* there goes
 * through App\Controller\EcoleDirecte\EcoleDirecteSendController.
 *
 * The identifiant and the password arrive in the body of one request (twice when École Directe asks
 * its identity question), go straight to App\EcoleDirecte\EcoleDirecteClient and are dropped with the
 * request: no entity, no session entry, no log line holds them. What survives is the sealed
 * connection (App\EcoleDirecte\EcoleDirecteSessionSealer), which the page keeps in memory and sends
 * back - so the server holds nothing between two calls either.
 *
 * Administrators only for now (App\Controller\EcoleDirecte\EcoleDirecteControllerTrait). Every call
 * to École Directe answers a button: the page never reads nor writes on its own.
 */
#[RequiresFeature(Feature::EcoleDirecte)]
class EcoleDirecteController extends AbstractController
{
    use EcoleDirecteControllerTrait;

    private const string EVALUATIONS_SINCE = '-12 months';
    private const int EVALUATIONS_LIMIT = 80;

    /** The explorer prints what École Directe answered; past this it is a download, not a read. */
    private const int EXPLORER_MAX_BYTES = 200_000;

    public function __construct(
        private readonly EcoleDirecteSessionSealer $sealer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/ecole-directe', name: 'app_ecole_directe', methods: ['GET'])]
    public function index(EvaluationRepository $evaluations): Response
    {
        $teacher = $this->administrator();

        // The evaluations this teacher may send: those of the matières they hold, written by them -
        // EvaluationVoter::MANAGE, the same rule as entering the grades in the first place.
        $sendable = array_values(array_filter(
            $evaluations->findRecentForTeacher($teacher, new \DateTimeImmutable(self::EVALUATIONS_SINCE), self::EVALUATIONS_LIMIT),
            fn (Evaluation $evaluation): bool => $this->isGranted(EvaluationVoter::MANAGE, $evaluation),
        ));

        return $this->render('ecole_directe/index.html.twig', [
            'csrfId' => self::CSRF_ID,
            'evaluations' => $sendable,
        ]);
    }

    #[Route(path: '/ecole-directe/login', name: 'app_ecole_directe_login', methods: ['POST'])]
    public function login(
        Request $request,
        EcoleDirecteClient $client,
        #[Target('ecoledirecte_login')] RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        $user = $this->guard($request);

        // Every teacher reaches École Directe from the same server address: a password mistyped in a
        // loop must hit this ceiling before it gets the establishment's address blocked there.
        if (!$limiter->create('user:'.$user->getId())->consume(1)->isAccepted()) {
            return $this->refusal('ecoleDirecteTooManyAttemptsMessage');
        }

        $payload = JsonRequestPayload::fromRequest($request);
        $identifiant = trim($payload->string('identifiant'));
        $password = $payload->string('password');

        if ('' === $identifiant || '' === $password) {
            return $this->refusal('ecoleDirecteCredentialsRequiredMessage');
        }

        try {
            return $this->outcome($client->login($identifiant, $password), $user);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }
    }

    #[Route(path: '/ecole-directe/challenge', name: 'app_ecole_directe_challenge', methods: ['POST'])]
    public function challenge(Request $request, EcoleDirecteClient $client): JsonResponse
    {
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);

        try {
            $pending = $this->sealer->openPending($payload->string('pending'), $user);

            // The answer must be one of the propositions École Directe offered: anything else is a
            // tampered page, and sending it would spend one of the account's own attempts.
            $choice = $payload->string('choice');
            if (!\in_array($choice, $pending['choices'], true)) {
                return $this->refusal('ecoleDirecteWrongAnswerMessage');
            }

            return $this->outcome($client->answerChallenge(
                trim($payload->string('identifiant')),
                $payload->string('password'),
                $pending['handshake'],
                $choice,
            ), $user);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }
    }

    #[Route(path: '/ecole-directe/lesson-log', name: 'app_ecole_directe_lesson_log', methods: ['POST'])]
    public function lessonLog(Request $request, EcoleDirecteLessonLogReader $reader): JsonResponse
    {
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->string('from'));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->string('to'));
        if (false === $from || false === $to) {
            return $this->refusal('ecoleDirecteInvalidSpanMessage');
        }

        try {
            $read = $reader->read($this->sealer->openSession($payload->string('session'), $user), $from, $to);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }

        return $this->json([
            'ok' => true,
            'session' => $this->sealer->sealSession($read['session'], $user),
            'html' => $this->renderView('ecole_directe/_slots.html.twig', ['slots' => $read['slots']]),
        ]);
    }

    /**
     * Any read, by path, answered raw - the admin's way of finding what a teacher account can be
     * asked before a screen is written for it. Reading only: the client forces `verbe=get`.
     */
    #[Route(path: '/ecole-directe/explore', name: 'app_ecole_directe_explore', methods: ['POST'])]
    public function explore(Request $request, EcoleDirecteClient $client): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);

        $path = ltrim(trim($payload->string('path')), '/');
        if (!EcoleDirecteClient::isRoutePath($path)) {
            return $this->refusal('ecoleDirecteInvalidPathMessage');
        }

        try {
            $read = $client->read($this->sealer->openSession($payload->string('session'), $user), $path);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }

        $raw = json_encode($read->data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE) ?: 'null';

        return $this->json([
            'ok' => true,
            'session' => $this->sealer->sealSession($read->session, $user),
            'raw' => \strlen($raw) > self::EXPLORER_MAX_BYTES ? mb_strcut($raw, 0, self::EXPLORER_MAX_BYTES).PHP_EOL.'…' : $raw,
        ]);
    }

    private function outcome(EcoleDirecteLoginOutcome $outcome, User $user): JsonResponse
    {
        if ($outcome->session instanceof EcoleDirecteSession) {
            return $this->json([
                'ok' => true,
                'step' => 'connected',
                'session' => $this->sealer->sealSession($outcome->session, $user),
                'html' => $this->renderView('ecole_directe/_account.html.twig', ['account' => $outcome->session->account]),
                'teacher' => $outcome->session->account->isTeacher(),
            ]);
        }

        if (null === $outcome->pending) {
            return $this->refusal('ecoleDirecteUnexpectedAnswerMessage');
        }

        return $this->json([
            'ok' => true,
            'step' => 'challenge',
            'pending' => $this->sealer->sealPending($outcome->pending, array_column($outcome->choices, 'value'), $user),
            'question' => $outcome->question,
            'choices' => $outcome->choices,
        ]);
    }
}
