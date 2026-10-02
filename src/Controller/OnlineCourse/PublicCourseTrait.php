<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Entity\OnlineCoursePage;
use App\Entity\User;
use App\Service\OnlineCourse\OnlineCoursePageHandles;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the public screens of « Cours en ligne » share: finding a teacher's page by the address in
 * the URL, and sending an address the page has left on to its current one.
 */
trait PublicCourseTrait
{
    /**
     * The page this address leads to, or the redirect to where it now lives.
     *
     * A page changes address whenever its teacher wants, during diffusion included
     * (design/validated/cours-en-ligne.md, §5): a link handed out under the old one must keep
     * working, so the old address answers a permanent redirect to the same screen under the new
     * one - route parameters and query string kept.
     */
    private function pageOrRedirect(string $handle, Request $request, OnlineCoursePageHandles $handles): OnlineCoursePage|RedirectResponse
    {
        $page = $handles->resolve($handle);
        if (null === $page) {
            throw $this->createNotFoundException();
        }

        if ($page->getHandle() !== $handle) {
            $route = $request->attributes->getString('_route');
            /** @var array<string, mixed> $parameters */
            $parameters = $request->attributes->get('_route_params', []);

            return $this->redirectToRoute($route, [...$parameters, ...$request->query->all(), 'handle' => $page->getHandle()], Response::HTTP_MOVED_PERMANENTLY);
        }

        return $page;
    }

    private function viewer(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
