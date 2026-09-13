<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\JobboardSource;
use App\Repository\JobboardCursorRepository;
use App\Repository\JobboardOfferRepository;
use App\Repository\JobboardSourceRepository;
use App\Service\Jobboard\JobboardSourceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Configuration > Jobboard > Sources » - the list of sites, which is a table since the day it
 * stopped being an enum.
 *
 * The screen exists because the ingestion no longer refuses what it does not know: a site met for
 * the first time is created from the URL's domain, named as the agent declared it, and lands here
 * marked « découverte ». Everything an administrator does on this screen is therefore *correction*
 * rather than authorisation - fixing a trade name, adding the domain a site moved to, writing down
 * the `source_ref` rule, and merging the duplicate that a badly-named pass left behind.
 *
 * Two things it refuses to do, and both are the same rule:
 *
 * - **it never deletes a source that carries offers**, because the offers would go with it;
 * - **it refuses a merge that would collide**, rather than picking which of two offers sharing an
 *   identity survives. `premiere_vue` cannot be rebuilt, so nothing here may quietly drop a row
 *   that holds one.
 *
 * The one gesture that is *not* a correction is the **blacklist**: a site whose offers have no
 * business on the board. It is the counterweight to an ingestion that never refuses - a site turns
 * up by itself, so there has to be a way to send it away - and it does two things at once, which is
 * why it is confirmed: the site stops being collected from, and the offers it already left are
 * **deleted**. Not closed: a closed offer means « l'annonce a quitté le site », which would be a
 * lie. Undoing the blacklist does not bring them back; the next pass does, minus the `premiere_vue`
 * nothing can rebuild.
 */
#[IsGranted('ROLE_ADMIN')]
class JobboardSourceController extends AbstractController
{
    #[Route(path: '/settings/jobboard/sources', name: 'app_settings_jobboard_sources', methods: ['GET'])]
    public function index(Request $request, JobboardSourceRepository $sources, JobboardOfferRepository $offers, JobboardCursorRepository $cursors): Response
    {
        $all = $sources->findAllOrdered();
        $editId = $request->query->getInt('edit');

        return $this->render('settings/jobboard_sources.html.twig', [
            'sources' => $all,
            'offerCounts' => $offers->countBySource(),
            'cursorCounts' => $cursors->countBySource(),
            'edited' => $this->pick($all, $editId),
        ]);
    }

    #[Route(path: '/settings/jobboard/sources', name: 'app_settings_jobboard_source_create', methods: ['POST'])]
    public function create(Request $request, JobboardSourceResolver $resolver, EntityManagerInterface $entityManager): Response
    {
        $this->assertToken($request, 'jobboard_source_save');

        $label = trim((string) $request->request->get('label'));

        if ('' === $label) {
            $this->addFlash('danger', 'jobboardSourceLabelRequiredFlashMessage');

            return $this->redirectToRoute('app_settings_jobboard_sources');
        }

        $source = $resolver->declareSite($label, self::domains($request));
        $source
            ->setRefRule(self::optional($request, 'ref_rule', 255))
            ->setLegacyPrefix(self::optional($request, 'legacy_prefix', 8))
        ;

        $entityManager->flush();
        $this->addFlash('success', 'jobboardSourceCreatedFlashMessage');

        return $this->redirectToRoute('app_settings_jobboard_sources');
    }

    #[Route(path: '/settings/jobboard/sources/{id}', name: 'app_settings_jobboard_source_update', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function update(int $id, Request $request, JobboardSourceRepository $sources, EntityManagerInterface $entityManager): Response
    {
        $this->assertToken($request, 'jobboard_source_save');
        $source = $this->findOrNotFound($sources, $id);
        $label = trim((string) $request->request->get('label'));

        if ('' === $label) {
            $this->addFlash('danger', 'jobboardSourceLabelRequiredFlashMessage');

            return $this->redirectToRoute('app_settings_jobboard_sources', ['edit' => $id]);
        }

        // The slug is never touched: it is what the agent sends, what a bookmarked filter carries
        // and what the offers were filed under. Renaming a site changes how it reads, not what it
        // is.
        $source
            ->setLabel(mb_substr($label, 0, 64))
            ->setDomains(self::domains($request))
            ->setRefRule(self::optional($request, 'ref_rule', 255))
            ->setLegacyPrefix(self::optional($request, 'legacy_prefix', 8))
        ;

        $entityManager->flush();
        $this->addFlash('success', 'jobboardSourceUpdatedFlashMessage');

        return $this->redirectToRoute('app_settings_jobboard_sources');
    }

    #[Route(path: '/settings/jobboard/sources/{id}/delete', name: 'app_settings_jobboard_source_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        JobboardSourceRepository $sources,
        JobboardOfferRepository $offers,
        JobboardCursorRepository $cursors,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->assertToken($request, 'jobboard_source_delete');
        $source = $this->findOrNotFound($sources, $id);

        // Belt as well as braces: the button is not drawn for a source that carries anything, and
        // the column is `ON DELETE RESTRICT` besides. Answering with a message beats answering with
        // a foreign-key error.
        if (0 !== ($offers->countBySource()[$id] ?? 0) || 0 !== ($cursors->countBySource()[$id] ?? 0)) {
            $this->addFlash('danger', 'jobboardSourceNotEmptyFlashMessage');

            return $this->redirectToRoute('app_settings_jobboard_sources');
        }

        $entityManager->remove($source);
        $entityManager->flush();
        $this->addFlash('success', 'jobboardSourceDeletedFlashMessage');

        return $this->redirectToRoute('app_settings_jobboard_sources');
    }

    /**
     * Out. The site stays in the table - its domains are what resolves the offers that will keep
     * arriving - and everything it had filed goes.
     *
     * The offers are counted before they are deleted so the flash can say how many: « blacklisté »
     * on its own would leave an administrator wondering whether the board emptied because of this
     * click or because of something else.
     */
    #[Route(path: '/settings/jobboard/sources/{id}/blacklist', name: 'app_settings_jobboard_source_blacklist', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function blacklist(
        int $id,
        Request $request,
        JobboardSourceRepository $sources,
        JobboardOfferRepository $offers,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        TranslatorInterface $translator,
    ): Response {
        $this->assertToken($request, 'jobboard_source_blacklist');
        $source = $this->findOrNotFound($sources, $id);

        // The flag first, the erasure second: a bulk DELETE goes straight to the database and is not
        // part of the flush, so doing it the other way round would erase the offers of a source the
        // flush might still refuse to mark.
        $source->blacklist($clock->now());
        $entityManager->flush();

        $deleted = $offers->deleteBySource($source);

        // Translated here rather than handed to the layout as a key: the figure is the point of
        // the message, and the layout's `|trans` takes no parameters.
        $this->addFlash('success', $translator->trans('jobboardSourceBlacklistedFlashMessage', [
            '%label%' => $source->getLabel(),
            '%count%' => $deleted,
        ]));

        return $this->redirectToRoute('app_settings_jobboard_sources');
    }

    /**
     * Back in. Only the flag moves: the offers deleted on the way out are not restored, and saying
     * so on the confirmation is the honest half of this pair.
     */
    #[Route(path: '/settings/jobboard/sources/{id}/allow', name: 'app_settings_jobboard_source_allow', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function allow(
        int $id,
        Request $request,
        JobboardSourceRepository $sources,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $this->assertToken($request, 'jobboard_source_blacklist');
        $source = $this->findOrNotFound($sources, $id);

        $source->allow();
        $entityManager->flush();

        $this->addFlash('success', $translator->trans('jobboardSourceAllowedFlashMessage', ['%label%' => $source->getLabel()]));

        return $this->redirectToRoute('app_settings_jobboard_sources');
    }

    /**
     * Two rows for one site - what a pass that declared « HelloWork FR » before the domain was
     * known leaves behind. The offers move, the losing row goes, and its domains are kept on the
     * survivor so the URLs that resolved yesterday still resolve tomorrow.
     */
    #[Route(path: '/settings/jobboard/sources/merge', name: 'app_settings_jobboard_source_merge', methods: ['POST'])]
    public function merge(
        Request $request,
        JobboardSourceRepository $sources,
        JobboardOfferRepository $offers,
        JobboardCursorRepository $cursors,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->assertToken($request, 'jobboard_source_merge');

        $from = $sources->find($request->request->getInt('from'));
        $into = $sources->find($request->request->getInt('into'));

        if (!$from instanceof JobboardSource || !$into instanceof JobboardSource || $from === $into) {
            $this->addFlash('danger', 'jobboardSourceMergeInvalidFlashMessage');

            return $this->redirectToRoute('app_settings_jobboard_sources');
        }

        $collisions = $offers->countIdentityCollisions($from, $into);

        if (0 !== $collisions) {
            $this->addFlash('danger', 'jobboardSourceMergeCollisionFlashMessage');

            return $this->redirectToRoute('app_settings_jobboard_sources');
        }

        // A cursor is a bookmark, not a record: where both sites hold one for the same search, the
        // destination's is kept and the other dropped. Nothing is lost that a pass cannot rewrite.
        foreach ($cursors->findForSource($from) as $cursor) {
            $existing = $cursors->findOneByScope($cursor->getTrack(), $into, $cursor->getSearch());

            if (null === $existing) {
                $cursor->moveToSource($into);
            } else {
                $entityManager->remove($cursor);
            }
        }

        $entityManager->flush();

        $offers->moveToSource($from, $into);

        foreach ($from->getDomains() as $domain) {
            $into->addDomain($domain);
        }

        $entityManager->remove($from);
        $entityManager->flush();

        $this->addFlash('success', 'jobboardSourceMergedFlashMessage');

        return $this->redirectToRoute('app_settings_jobboard_sources');
    }

    /**
     * The domains, typed one per line or separated by commas. Only split here - what a domain
     * *is* (no scheme, no path, no `www.`) is the entity's business, so the form and the ingestion
     * cannot end up disagreeing about it.
     *
     * @return list<string>
     */
    private static function domains(Request $request): array
    {
        $parts = preg_split('/[\s,;]+/', (string) $request->request->get('domains')) ?: [];

        return array_values(array_filter($parts));
    }

    private static function optional(Request $request, string $field, int $maxLength): ?string
    {
        $value = trim((string) $request->request->get($field));

        return '' === $value ? null : mb_substr($value, 0, $maxLength);
    }

    /** @param list<JobboardSource> $sources */
    private function pick(array $sources, int $id): ?JobboardSource
    {
        foreach ($sources as $source) {
            if ($source->getId() === $id) {
                return $source;
            }
        }

        return null;
    }

    private function findOrNotFound(JobboardSourceRepository $sources, int $id): JobboardSource
    {
        $source = $sources->find($id);

        if (!$source instanceof JobboardSource) {
            throw $this->createNotFoundException();
        }

        return $source;
    }

    private function assertToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
