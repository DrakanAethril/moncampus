<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardBatch;
use App\Entity\JobboardOffer;
use App\Entity\Section;
use App\Enum\JobboardLevelSource;
use App\Repository\JobboardOfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Where every offer of this platform is filed, whoever brought it - the collecting agent through
 * the API, or an administrator through the import screen. One implementation, because these rules
 * written twice would disagree the first time one of them was touched.
 *
 * **The asymmetry is the whole thing.** A second, more thorough pass must be able to enrich a row;
 * a hurried pass must never degrade what had been verified. Three fields carry it - the level, the
 * publication date, the remote-work value - and each one only ever moves towards more certainty.
 *
 * And above all: `premiere_vue` is written at creation and never again. No site can give it back.
 */
final readonly class OfferIngestor
{
    public function __construct(
        private OfferPayloadParser $parser,
        private JobboardOfferRepository $offers,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array<array-key, mixed>> $rows
     */
    public function ingest(JobboardBatch $batch, array $rows, bool $legacy = false): IngestReport
    {
        $now = $this->clock->now();
        $lines = [];
        // An offer sent twice in the same call is a client bug, not a second offer: filing it twice
        // would create it and then immediately review it, which reads as two different events.
        $seen = [];

        foreach ($rows as $index => $row) {
            try {
                $payload = $this->parser->parse($row, $legacy);
            } catch (OfferRejectedException $exception) {
                $lines[] = new IngestLine(
                    index: $index,
                    outcome: IngestOutcome::Rejected,
                    reason: $exception->reason,
                    field: $exception->field,
                );

                continue;
            }

            if (isset($seen[$payload->identity()])) {
                $lines[] = new IngestLine(
                    index: $index,
                    outcome: IngestOutcome::Rejected,
                    source: $payload->source->value,
                    sourceRef: $payload->sourceRef,
                    position: $payload->position,
                    reason: JobboardRejection::DuplicateInBatch,
                );

                continue;
            }

            $seen[$payload->identity()] = true;

            $existing = $this->offers->findOneByIdentity($batch->getSection(), $payload->source, $payload->sourceRef);

            if (null === $existing) {
                $offer = new JobboardOffer(
                    $batch->getSection(),
                    $payload->source,
                    $payload->sourceRef,
                    // The import brings a first-seen date that predates the platform; the API never
                    // does, and stamping "now" there is the only honest answer.
                    $payload->firstSeenAt ?? $now,
                );

                $this->overwrite($offer, $payload);
                $offer->setPublication($payload->publishedAt, $payload->publishedAtApprox);
                $offer->setLevel($payload->level)->setLevelSource($payload->levelSource);
                $offer->setRemote($payload->remote);
                $offer->markSeen($now);

                $this->entityManager->persist($offer);
                $outcome = IngestOutcome::Created;
            } else {
                $this->review($existing, $payload, $now);
                $outcome = IngestOutcome::Reviewed;
            }

            $lines[] = new IngestLine(
                index: $index,
                outcome: $outcome,
                source: $payload->source->value,
                sourceRef: $payload->sourceRef,
                position: $payload->position,
            );
        }

        $report = new IngestReport($lines);
        $batch->tally($report->created(), $report->reviewed(), $report->rejected());

        $this->entityManager->flush();

        return $report;
    }

    /**
     * The same reading, writing nothing: what the import screen shows before an administrator
     * confirms. Every refusal it announces is a refusal the real pass will pronounce, because it is
     * the same parser and the same lookup - the only thing it does not do is persist.
     *
     * The lengths and the required fields are enforced by the parser rather than by the columns,
     * which is what makes this dry run honest: a dry run that only re-reads the file discovers the
     * NOT NULL constraints at the worst possible moment, on the real pass.
     *
     * @param list<array<array-key, mixed>> $rows
     */
    public function analyse(Section $section, array $rows, bool $legacy = false): IngestReport
    {
        $lines = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            try {
                $payload = $this->parser->parse($row, $legacy);
            } catch (OfferRejectedException $exception) {
                $lines[] = new IngestLine($index, IngestOutcome::Rejected, reason: $exception->reason, field: $exception->field);

                continue;
            }

            if (isset($seen[$payload->identity()])) {
                $lines[] = new IngestLine(
                    $index,
                    IngestOutcome::Rejected,
                    $payload->source->value,
                    $payload->sourceRef,
                    $payload->position,
                    JobboardRejection::DuplicateInBatch,
                );

                continue;
            }

            $seen[$payload->identity()] = true;
            $known = null !== $this->offers->findOneByIdentity($section, $payload->source, $payload->sourceRef);

            $lines[] = new IngestLine(
                $index,
                $known ? IngestOutcome::Reviewed : IngestOutcome::Created,
                $payload->source->value,
                $payload->sourceRef,
                $payload->position,
            );
        }

        return new IngestReport($lines);
    }

    /**
     * A row that comes back. Three fields move only towards more certainty, the rest is rewritten,
     * and `premiere_vue` is not in either group because it has no setter at all.
     */
    private function review(JobboardOffer $offer, OfferPayload $payload, \DateTimeImmutable $now): void
    {
        $offer->markSeen($now);
        $this->overwrite($offer, $payload);

        // The level: an `annonce` replaces an `estime`, never the other way round. Equal confidence
        // refreshes - that is not a degradation, and refusing it would freeze a level the advert
        // has since corrected.
        $degrades = JobboardLevelSource::Estime === $payload->levelSource
            && JobboardLevelSource::Annonce === $offer->getLevelSource();

        if (!$degrades) {
            $offer->setLevel($payload->level)->setLevelSource($payload->levelSource);
        }

        // The date: an exact one always wins, an approximate one only replaces another approximate
        // one or an absent date. A pass that found no date at all erases nothing - several sites
        // republish old adverts with a fresh « il y a 2 heures », and that is precisely the value
        // that must not overwrite a date somebody read on the offer's own page.
        if (null !== $payload->publishedAt) {
            $stored = $offer->getPublishedAt();

            if (null === $stored || !$payload->publishedAtApprox || $offer->isPublishedAtApprox()) {
                $offer->setPublication($payload->publishedAt, $payload->publishedAtApprox);
            }
        }

        // Remote work: a stated value replaces anything, `non_precise` replaces nothing. The two
        // are not the same answer and must never collapse into one.
        if ($payload->remote->isStated()) {
            $offer->setRemote($payload->remote);
        }
    }

    /** The fields rewritten without a second thought, on creation as on review. */
    private function overwrite(JobboardOffer $offer, OfferPayload $payload): void
    {
        $offer
            ->setUrl($payload->url)
            ->setPosition($payload->position)
            ->setCompany($payload->company)
            ->setCategory($payload->category)
            ->setContract($payload->contract)
            ->setCountry($payload->country)
            ->setRegion($payload->region)
            ->setDepartement($payload->departement)
            ->setCity($payload->city)
            ->setBtsAccess($payload->btsAccess)
            ->setNote($payload->note)
        ;

        // The one exception in this group: an absent `brut` means "I did not send it", not "forget
        // what you had". It is the payload kept so a parsing can be replayed without collecting
        // again, and dropping it on a pass that omitted it would defeat that.
        if (null !== $payload->raw) {
            $offer->setRaw($payload->raw);
        }
    }
}
