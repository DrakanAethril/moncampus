<?php

declare(strict_types=1);

namespace App\Controller\EcoleDirecte;

use App\Attribute\RequiresFeature;
use App\EcoleDirecte\EcoleDirecteException;
use App\EcoleDirecte\EcoleDirecteGradebookCatalog;
use App\EcoleDirecte\EcoleDirecteGradebookTarget;
use App\EcoleDirecte\EcoleDirecteGradebookWriter;
use App\EcoleDirecte\EcoleDirecteGradeOptions;
use App\EcoleDirecte\EcoleDirecteGradeRow;
use App\EcoleDirecte\EcoleDirecteLessonLogWriter;
use App\EcoleDirecte\EcoleDirecteSession;
use App\EcoleDirecte\EcoleDirecteSessionSealer;
use App\EcoleDirecte\EcoleDirecteStudentLinker;
use App\Entity\Evaluation;
use App\Entity\User;
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

        $choice = $this->gradebookChoice($evaluations, $payload);
        if (!\is_array($choice)) {
            return $this->refusal($choice);
        }

        try {
            return $this->gradebookPreviewAnswer($writer, $this->sealer->openSession($payload->string('session'), $user), $user, ...$choice);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }
    }

    /**
     * Remembers who one MonCampus student of the evaluation is in École Directe, then answers the
     * preview again - the row it was asked from now reads matched.
     */
    #[Route(path: '/ecole-directe/gradebook/link', name: 'app_ecole_directe_gradebook_link', methods: ['POST'])]
    public function gradebookLink(Request $request, EvaluationRepository $evaluations, EcoleDirecteStudentLinker $linker, EcoleDirecteGradebookWriter $writer): JsonResponse
    {
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);

        $choice = $this->gradebookChoice($evaluations, $payload);
        if (!\is_array($choice)) {
            return $this->refusal($choice);
        }
        $student = $payload->int('student');
        $ecoleDirecteStudent = $payload->int('ecoleDirecteStudent');
        if (null === $student || null === $ecoleDirecteStudent) {
            return $this->refusal('ecoleDirecteLinkChoiceMissingMessage');
        }

        try {
            $session = $linker->link($this->sealer->openSession($payload->string('session'), $user), $choice[0], $choice[1], $student, $ecoleDirecteStudent, $user);

            return $this->gradebookPreviewAnswer($writer, $session, $user, ...$choice);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }
    }

    /** Forgets who one MonCampus student is in École Directe; they are matched by name again. */
    #[Route(path: '/ecole-directe/gradebook/unlink', name: 'app_ecole_directe_gradebook_unlink', methods: ['POST'])]
    public function gradebookUnlink(Request $request, EvaluationRepository $evaluations, EcoleDirecteStudentLinker $linker, EcoleDirecteGradebookWriter $writer): JsonResponse
    {
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);

        $choice = $this->gradebookChoice($evaluations, $payload);
        if (!\is_array($choice)) {
            return $this->refusal($choice);
        }
        $student = $payload->int('student');
        if (null === $student) {
            return $this->refusal('ecoleDirecteLinkChoiceMissingMessage');
        }

        try {
            $session = $this->sealer->openSession($payload->string('session'), $user);
            $linker->unlink($choice[0], $student);

            return $this->gradebookPreviewAnswer($writer, $session, $user, ...$choice);
        } catch (EcoleDirecteException $exception) {
            return $this->failure($exception);
        }
    }

    #[Route(path: '/ecole-directe/gradebook/send', name: 'app_ecole_directe_gradebook_send', methods: ['POST'])]
    public function gradebookSend(Request $request, EvaluationRepository $evaluations, EcoleDirecteGradebookWriter $writer): JsonResponse
    {
        $user = $this->guard($request);
        $payload = JsonRequestPayload::fromRequest($request);

        $choice = $this->gradebookChoice($evaluations, $payload);
        if (!\is_array($choice)) {
            return $this->refusal($choice);
        }

        try {
            $sent = $writer->send($this->sealer->openSession($payload->string('session'), $user), ...$choice);
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
     * @throws EcoleDirecteException
     */
    private function gradebookPreviewAnswer(EcoleDirecteGradebookWriter $writer, EcoleDirecteSession $session, User $user, Evaluation $evaluation, EcoleDirecteGradebookTarget $target, EcoleDirecteGradeOptions $options): JsonResponse
    {
        $preview = $writer->preview($session, $evaluation, $target, $options);

        return $this->json([
            'ok' => true,
            'session' => $this->sealer->sealSession($preview['session'], $user),
            'html' => $this->renderView('ecole_directe/_gradebook_preview.html.twig', [
                ...$preview,
                'subject' => $evaluation,
                'scale' => $options->scale($evaluation->getScale()),
                'coefficient' => $options->coefficient,
                'outOf20' => $options->outOf20 && $options->scale($evaluation->getScale()) !== $evaluation->getScale(),
            ]),
            'sendable' => null === $preview['refusal'] && [] !== array_filter($preview['rows'], static fn (EcoleDirecteGradeRow $row): bool => $row->state->sends()),
        ]);
    }

    /**
     * What the page asked to send, and how: the evaluation, where in École Directe, brought back to
     * 20 or not, which coefficient. The translation key of the refusal when any of it is missing.
     *
     * @return array{0: Evaluation, 1: EcoleDirecteGradebookTarget, 2: EcoleDirecteGradeOptions}|string
     */
    private function gradebookChoice(EvaluationRepository $evaluations, JsonRequestPayload $payload): array|string
    {
        $evaluation = $this->sendableEvaluation($evaluations, $payload);
        $target = EcoleDirecteGradebookTarget::fromKey($payload->string('target'));
        if (null === $evaluation || null === $target) {
            return 'ecoleDirecteGradebookChoiceMissingMessage';
        }

        $options = EcoleDirecteGradeOptions::of($payload->bool('outOf20'), $payload->float('coefficient'));
        if (null === $options) {
            return 'ecoleDirecteInvalidCoefficientMessage';
        }

        return [$evaluation, $target, $options];
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
