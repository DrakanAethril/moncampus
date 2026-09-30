<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresFeature;
use App\Enum\Feature;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Test PWA » - a measurement page, not a feature: it answers whether a browser keeps reading the
 * GPS the way the e-CO app does (one fix every 5 s) once the screen is locked or the page hidden,
 * which is the one thing that decides whether e-CO could run as a PWA.
 *
 * Nothing reaches the server. Every fix stays in the phone's localStorage and is exported by hand
 * (JSON / GPX), so the page needs no entity, no endpoint and no retention rule - and can be
 * deleted as a whole once the question is answered. The install manifest is a static file
 * (public/eco-pwa-probe/manifest.json): a manifest is fetched without cookies, so a route behind
 * the login would answer it with a redirect.
 */
#[IsGranted(new Expression('is_granted("ROLE_ECO") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")'))]
#[RequiresFeature(Feature::Eco)]
class EcoPwaProbeController extends AbstractController
{
    #[Route(path: '/eco/pwa-probe', name: 'app_eco_pwa_probe', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('eco/pwa_probe.html.twig');
    }
}
