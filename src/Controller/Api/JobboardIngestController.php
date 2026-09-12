<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\JobboardBatch;
use App\Entity\JobboardBatchRequest;
use App\Entity\JobboardCursor;
use App\Enum\JobboardSource;
use App\Repository\JobboardBatchRepository;
use App\Repository\JobboardBatchRequestRepository;
use App\Repository\JobboardCursorRepository;
use App\Repository\JobboardOfferRepository;
use App\Service\Jobboard\IngestAuthenticator;
use App\Service\Jobboard\IngestInstructions;
use App\Service\Jobboard\OfferIngestor;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The door the veille pushes offers through - the only place on this platform where an offer is
 * written.
 *
 * **No account, no JWT, no session.** The caller is a collecting agent; it holds one key, and that
 * key decides the filière of everything it deposits (App\Service\Jobboard\IngestAuthenticator).
 * The routes are PUBLIC_ACCESS in access_control for exactly that reason - the same shape as e-CO
 * runners and the deployment banner - and they are named in FeatureCoverageTest's exemptions
 * because a feature resolved against an anonymous visitor could only ever shut the door.
 *
 * Three rules this controller exists to keep:
 *
 * - **a malformed offer never fails the batch** - the answer is line by line;
 * - **the same request sent twice changes nothing**, tally included - that is the Idempotency-Key;
 * - **a batch belongs to the key that opened it**, and to no other: another key's batch answers
 *   404, not 403, because it does not exist for that caller.
 */
class JobboardIngestController extends AbstractController
{
    public function __construct(
        private readonly IngestAuthenticator $authenticator,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/api/jobboard/batches', name: 'api_jobboard_batch_open', methods: ['POST'])]
    public function openBatch(Request $request): JsonResponse
    {
        $token = $this->authenticator->authenticate($request);

        $batch = JobboardBatch::forToken($token);
        $this->entityManager->persist($batch);
        $this->entityManager->flush();

        return new JsonResponse([
            'batch_id' => $batch->getId(),
            'section' => $token->getSection()->getName(),
        ], Response::HTTP_CREATED);
    }

    #[Route(path: '/api/jobboard/batches/{id}/offers', name: 'api_jobboard_batch_offers', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function pushOffers(
        int $id,
        Request $request,
        JobboardBatchRepository $batches,
        JobboardBatchRequestRepository $requests,
        OfferIngestor $ingestor,
    ): JsonResponse {
        $token = $this->authenticator->authenticate($request);
        $batch = $batches->find($id);

        if (null === $batch || $batch->getToken() !== $token) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'unknown_batch');
        }

        if ($batch->isClosed()) {
            return $this->problem(Response::HTTP_CONFLICT, 'batch_closed');
        }

        $key = trim((string) $request->headers->get('Idempotency-Key', ''));

        if ('' === $key) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'missing_idempotency_key');
        }

        $hash = hash('sha256', $request->getContent());
        $already = $requests->findOneByKey($batch, $key);

        if (null !== $already) {
            // Replayed, not re-applied: the stored answer goes back untouched, which is what makes
            // a retried request cost nothing - the tally included.
            return $already->getPayloadHash() === $hash
                ? new JsonResponse($already->getResponse())
                : $this->problem(Response::HTTP_CONFLICT, 'idempotency_key_reused');
        }

        $rows = array_map(
            static fn (JsonRequestPayload $row): array => $row->toArray(),
            JsonRequestPayload::fromRequest($request)->objects('offers'),
        );

        if (\count($rows) > IngestInstructions::MAX_OFFERS_PER_REQUEST) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'too_many_offers');
        }

        $report = $ingestor->ingest($batch, $rows);
        $answer = $report->toArray();

        $this->entityManager->persist(new JobboardBatchRequest($batch, $key, $hash, $answer));
        $this->entityManager->flush();

        return new JsonResponse($answer);
    }

    #[Route(path: '/api/jobboard/batches/{id}/close', name: 'api_jobboard_batch_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function closeBatch(int $id, Request $request, JobboardBatchRepository $batches): JsonResponse
    {
        $token = $this->authenticator->authenticate($request);
        $batch = $batches->find($id);

        if (null === $batch || $batch->getToken() !== $token) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'unknown_batch');
        }

        // Closing twice is not an error: an agent that lost the answer to its first close must be
        // able to ask again and read the same tally.
        $batch->close();
        $this->entityManager->flush();

        return new JsonResponse([
            'batch_id' => $batch->getId(),
            'created' => $batch->getCreatedCount(),
            'reviewed' => $batch->getReviewedCount(),
            'rejected' => $batch->getRejectedCount(),
            'closed_at' => $batch->getClosedAt()?->format(\DATE_ATOM),
        ]);
    }

    #[Route(path: '/api/jobboard/cursors', name: 'api_jobboard_cursors_read', methods: ['GET'])]
    public function readCursors(Request $request, JobboardCursorRepository $cursors): JsonResponse
    {
        $token = $this->authenticator->authenticate($request);

        return new JsonResponse([
            'cursors' => array_map(static fn (JobboardCursor $cursor): array => [
                'source' => $cursor->getSource()->value,
                'search' => $cursor->getSearch(),
                'last_ref' => $cursor->getLastRef(),
                'last_published_at' => $cursor->getLastPublishedAt()?->format('Y-m-d'),
                'updated_at' => $cursor->getUpdatedAt()->format(\DATE_ATOM),
            ], $cursors->findForSection($token->getSection())),
        ]);
    }

    #[Route(path: '/api/jobboard/cursors', name: 'api_jobboard_cursors_write', methods: ['PUT'])]
    public function writeCursor(Request $request, JobboardCursorRepository $cursors, ClockInterface $clock): JsonResponse
    {
        $token = $this->authenticator->authenticate($request);
        $payload = JsonRequestPayload::fromRequest($request);

        $source = JobboardSource::tryFromLoose($payload->string('source'));
        $search = trim($payload->string('search'));

        if (null === $source || '' === $search) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'invalid_cursor');
        }

        $publishedAt = null;
        $rawDate = trim($payload->string('last_published_at'));

        if ('' !== $rawDate) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($rawDate, 0, 10));

            if (false === $parsed || $parsed > $clock->now()->setTime(0, 0)) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'invalid_cursor');
            }

            $publishedAt = $parsed;
        }

        $cursor = $cursors->findOneByScope($token->getSection(), $source, mb_substr($search, 0, 190));

        if (null === $cursor) {
            $cursor = new JobboardCursor($token->getSection(), $source, mb_substr($search, 0, 190));
            $this->entityManager->persist($cursor);
        }

        $lastRef = trim($payload->string('last_ref'));
        $cursor->moveTo('' === $lastRef ? null : mb_substr($lastRef, 0, 190), $publishedAt);
        $this->entityManager->flush();

        return new JsonResponse(['source' => $source->value, 'search' => $cursor->getSearch()]);
    }

    #[Route(path: '/api/jobboard/offers/close', name: 'api_jobboard_offers_close', methods: ['POST'])]
    public function closeOffers(Request $request, JobboardOfferRepository $offers, ClockInterface $clock): JsonResponse
    {
        $token = $this->authenticator->authenticate($request);

        $identities = [];
        foreach (JsonRequestPayload::fromRequest($request)->objects('offers') as $row) {
            $source = JobboardSource::tryFromLoose($row->string('source'));
            $ref = trim($row->string('source_ref'));

            if (null !== $source && '' !== $ref) {
                $identities[] = ['source' => $source, 'ref' => $ref];
            }
        }

        $found = $offers->findByIdentities($token->getSection(), $identities);
        $now = $clock->now();
        $closed = 0;

        foreach ($identities as $identity) {
            $offer = $found[$identity['source']->value.'|'.$identity['ref']] ?? null;

            if (null !== $offer && !$offer->isClosed()) {
                // Closed, never deleted: how long an advert stayed online is an information in
                // itself, and nothing on this platform erases a row.
                $offer->close($now);
                ++$closed;
            }
        }

        $this->entityManager->flush();

        return new JsonResponse(['closed' => $closed, 'submitted' => \count($identities)]);
    }

    private function problem(int $status, string $error): JsonResponse
    {
        return new JsonResponse(['error' => $error], $status);
    }
}
