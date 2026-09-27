<?php

declare(strict_types=1);

namespace App\Controller\EcoleDirecte;

use App\Attribute\RequiresFeature;
use App\EcoleDirecte\EcoleDirecteException;
use App\EcoleDirecte\EcoleDirecteGradebookCatalog;
use App\EcoleDirecte\EcoleDirecteGradebookTarget;
use App\EcoleDirecte\EcoleDirecteGradebookWriter;
use App\EcoleDirecte\EcoleDirecteLessonLogWriter;
use App\EcoleDirecte\EcoleDirecteSessionSealer;
use App\Entity\Evaluation;
use App\Enum\Feature;
use App\Repository\EvaluationRepository;
use App\Security\Voter\EvaluationVoter;
use App\Service\JsonRequestPayload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Outils > École Directe: sending a teacher's cahier de texte and an evaluation's grades to their
 * own École Directe account.
 *
 * Every send is two calls from the page: a preview, which reads École Directe and says line by line
 * what would change, and the send itself, which reads it again and writes only what the teacher
 * ticked - never on the strength of a screen that may be stale. Nothing is ever deleted there.
 *
 * The connection is the sealed one App\Controller\EcoleDirecte\EcoleDirecteController opened; no
 * credential reaches this controller.
 */
#[RequiresFeature(Feature::EcoleDirecte)]
class EcoleDirecteSendController extends AbstractController
{
    use EcoleDirecteControllerTrait;

    public function __construct(
        private readonly EcoleDirecteSessionSealer $sealer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/ecole-directe/lesson-log/preview', name: 'app_ecole_directe_lesson_log_preview', methods: ['POST'])]
    public function lessonLogPreview(Request $request, EcoleDirecteLessonLogWriter $writer): JsonResponse
    {
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);
        $span = $this->span($payload);
        if (null === $span) {
            return $this->refusal('ecoleDirecteInvalidSpanMessage');
        }

        try {
            $preview = $writer->preview($user, $this->sealer->openSession($payload->string('session'), $user), $span[0], $span[1]);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }

        return $this->json([
            'ok' => true,
            'session' => $this->sealer->sealSession($preview['session'], $user),
            'html' => $this->renderView('ecole_directe/_lesson_log_preview.html.twig', $preview),
        ]);
    }

    #[Route(path: '/ecole-directe/lesson-log/send', name: 'app_ecole_directe_lesson_log_send', methods: ['POST'])]
    public function lessonLogSend(Request $request, EcoleDirecteLessonLogWriter $writer): JsonResponse
    {
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);
        $span = $this->span($payload);
        if (null === $span) {
            return $this->refusal('ecoleDirecteInvalidSpanMessage');
        }

        $keys = $payload->strings('keys');
        if ([] === $keys) {
            return $this->refusal('ecoleDirecteNothingSelectedMessage');
        }

        try {
            $sent = $writer->send($user, $this->sealer->openSession($payload->string('session'), $user), $span[0], $span[1], $keys);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }

        return $this->json([
            'ok' => true,
            'session' => $this->sealer->sealSession($sent['session'], $user),
            'html' => $this->renderView('ecole_directe/_lesson_log_sent.html.twig', ['results' => $sent['results']]),
        ]);
    }

    #[Route(path: '/ecole-directe/gradebook/targets', name: 'app_ecole_directe_gradebook_targets', methods: ['POST'])]
    public function gradebookTargets(Request $request, EcoleDirecteGradebookCatalog $catalog): JsonResponse
    {
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);

        try {
            $read = $catalog->options($this->sealer->openSession($payload->string('session'), $user));
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }

        return $this->json([
            'ok' => true,
            'session' => $this->sealer->sealSession($read['session'], $user),
            'options' => $read['options'],
        ]);
    }

    #[Route(path: '/ecole-directe/gradebook/preview', name: 'app_ecole_directe_gradebook_preview', methods: ['POST'])]
    public function gradebookPreview(Request $request, EvaluationRepository $evaluations, EcoleDirecteGradebookWriter $writer): JsonResponse
    {
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);

        $evaluation = $this->sendableEvaluation($evaluations, $payload);
        $target = EcoleDirecteGradebookTarget::fromKey($payload->string('target'));
        if (null === $evaluation || null === $target) {
            return $this->refusal('ecoleDirecteGradebookChoiceMissingMessage');
        }

        try {
            $preview = $writer->preview($this->sealer->openSession($payload->string('session'), $user), $evaluation, $target);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }

        return $this->json([
            'ok' => true,
            'session' => $this->sealer->sealSession($preview['session'], $user),
            'html' => $this->renderView('ecole_directe/_gradebook_preview.html.twig', [...$preview, 'subject' => $evaluation]),
            'sendable' => null === $preview['refusal'] && [] !== array_filter($preview['rows'], static fn ($row): bool => $row->state->sends()),
        ]);
    }

    #[Route(path: '/ecole-directe/gradebook/send', name: 'app_ecole_directe_gradebook_send', methods: ['POST'])]
    public function gradebookSend(Request $request, EvaluationRepository $evaluations, EcoleDirecteGradebookWriter $writer): JsonResponse
    {
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);

        $evaluation = $this->sendableEvaluation($evaluations, $payload);
        $target = EcoleDirecteGradebookTarget::fromKey($payload->string('target'));
        if (null === $evaluation || null === $target) {
            return $this->refusal('ecoleDirecteGradebookChoiceMissingMessage');
        }

        try {
            $sent = $writer->send($this->sealer->openSession($payload->string('session'), $user), $evaluation, $target);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }

        return $this->json([
            'ok' => true,
            'session' => $this->sealer->sealSession($sent['session'], $user),
            'html' => $this->renderView('ecole_directe/_gradebook_sent.html.twig', $sent),
        ]);
    }

    /**
     * The evaluation named by the page, if this teacher may send it - the rule of entering its grades
     * (EvaluationVoter::MANAGE): a titulaire of the matière who wrote it.
     */
    private function sendableEvaluation(EvaluationRepository $evaluations, JsonRequestPayload $payload): ?Evaluation
    {
        $id = $payload->int('evaluation');
        $evaluation = null === $id ? null : $evaluations->find($id);

        return $evaluation instanceof Evaluation && null === $evaluation->getInactiveDate() && $this->isGranted(EvaluationVoter::MANAGE, $evaluation) ? $evaluation : null;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null */
    private function span(JsonRequestPayload $payload): ?array
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->string('from'));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $payload->string('to'));

        return false === $from || false === $to ? null : [$from, $to];
    }
}
