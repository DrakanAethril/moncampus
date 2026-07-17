<?php

namespace App\Controller;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Ressources" menu - direct APK/IPA download pages for the companion mobile apps, distributed
 * outside the Play Store/App Store (see design/design_campus_manager/README.md "Ressources —
 * pages de téléchargement des apps", tour 11, écrans 11a/11b). The listing pages themselves sit
 * behind login (11b additionally behind ROLE_ECO), but the APK/IPA/manifest files they link to
 * are plain public URLs meant to be shared by QR code or e-mail - see the PUBLIC_ACCESS entry
 * for ^/downloads in config/packages/security.yaml.
 *
 * Version/size/date come from the app.resources parameter (config/packages/app_resources.yaml)
 * rather than the filesystem - see that file's header comment.
 */
class ResourcesController extends AbstractController
{
    private const string ECO_ACCESS_EXPRESSION = 'is_granted("ROLE_ECO") or is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")';

    #[Route(path: '/ressources/application-mobile', name: 'app_resources_mobile')]
    #[IsGranted('ROLE_USER')]
    public function mobileApp(Request $request): Response
    {
        return $this->render('resources/application_mobile.html.twig', [
            'resourceApp' => $this->appViewData('moncampus', $request),
        ]);
    }

    #[Route(path: '/ressources/application-e-co', name: 'app_resources_eco')]
    #[IsGranted(new Expression(self::ECO_ACCESS_EXPRESSION))]
    public function ecoApp(Request $request): Response
    {
        return $this->render('resources/application_eco.html.twig', [
            'resourceApp' => $this->appViewData('eco', $request),
        ]);
    }

    // Dynamically generated (not a static file like the APK/IPA themselves) because it must embed
    // the deployed bundle id/version and an absolute URL to the IPA - see App\Controller\
    // ResourcesController::appViewData() for where the itms-services:// link pointing here is built.
    #[Route(path: '/downloads/{app}.plist', name: 'app_resources_manifest', requirements: ['app' => 'moncampus|eco'])]
    public function manifest(string $app, Request $request): Response
    {
        $config = $this->getParameter('app.resources')[$app];

        $content = $this->renderView('resources/_manifest.plist.twig', [
            'bundleId' => $config['ios_bundle_id'],
            'version' => $config['version'],
            'title' => $config['name'],
            'ipaUrl' => $request->getSchemeAndHttpHost().'/downloads/'.$app.'.ipa',
        ]);

        return new Response($content, Response::HTTP_OK, ['Content-Type' => 'application/xml']);
    }

    private function appViewData(string $app, Request $request): array
    {
        /** @var array{name: string, version: string, updated_at: string, android_size: string, ios_size: string} $config */
        $config = $this->getParameter('app.resources')[$app];

        $androidUrl = $request->getSchemeAndHttpHost().'/downloads/'.$app.'.apk';
        $manifestUrl = $this->generateUrl('app_resources_manifest', ['app' => $app], UrlGeneratorInterface::ABSOLUTE_URL);
        $iosUrl = 'itms-services://?action=download-manifest&url='.rawurlencode($manifestUrl);

        return [
            'name' => $config['name'],
            'version' => $config['version'],
            'updatedAt' => $config['updated_at'],
            'android' => ['url' => $androidUrl, 'size' => $config['android_size'], 'qr' => $this->qrSvg($androidUrl)],
            'ios' => ['url' => $iosUrl, 'size' => $config['ios_size'], 'qr' => $this->qrSvg($iosUrl)],
        ];
    }

    private function qrSvg(string $data): string
    {
        return (new Builder(writer: new SvgWriter(), data: $data, size: 84, margin: 0))->build()->getString();
    }
}
