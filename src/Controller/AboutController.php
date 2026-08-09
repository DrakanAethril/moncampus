<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "À propos" - what this app is, who holds its copyright, and above all where its source code is.
 *
 * That last part is a licence obligation, not a courtesy: MonCampus is AGPL-3.0-or-later, and
 * section 13 of that licence requires an operator to offer the Corresponding Source to every user
 * who interacts with the app over a network. This screen is how that offer is made, which is why it
 * is reachable from the user menu on every authenticated page rather than buried in a footer.
 *
 * The URLs come from the app.about parameter (config/packages/app_about.yaml) so a fork can point
 * them at its own repository without touching this code - see that file's header.
 */
class AboutController extends AbstractController
{
    /**
     * @param array{
     *     source_url: string,
     *     license: string,
     *     license_url: string,
     *     mobile_apps: list<array{name: string, source_url: string, license: string}>
     * } $about
     */
    public function __construct(
        #[Autowire(param: 'app.about')]
        private readonly array $about,
    ) {
    }

    #[Route(path: '/about', name: 'app_about', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        return $this->render('about/index.html.twig', [
            'about' => $this->about,
        ]);
    }
}
