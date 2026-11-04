<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\OnlineCourse\OnlineCoursePageHandles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Formatool installable as an app, « beaup-app » (design/validated/quiz-tournament.md, §13): the manifest and its
 * icons, which are static files under public/ that Caddy serves and no route touches, the head of
 * every page, and the « Installer beaup-app » screen.
 */
class WebAppInstallTest extends FunctionalTestCase
{
    public function testTheManifestDescribesBeaupAppStandaloneOverTheWholeSite(): void
    {
        $manifest = $this->manifest();

        self::assertSame('/', $manifest['id'] ?? null);
        self::assertSame('beaup-app', $manifest['name'] ?? null);
        self::assertSame('beaup-app', $manifest['short_name'] ?? null);
        self::assertSame('/', $manifest['start_url'] ?? null);
        self::assertSame('/', $manifest['scope'] ?? null);
        self::assertSame('standalone', $manifest['display'] ?? null);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', \is_string($manifest['background_color'] ?? null) ? $manifest['background_color'] : '');
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', \is_string($manifest['theme_color'] ?? null) ? $manifest['theme_color'] : '');
        // Nothing of a notification: no permission is ever asked (§1, decision 7).
        self::assertArrayNotHasKey('gcm_sender_id', $manifest);
    }

    public function testEveryIconOfTheManifestIsAPngOfTheSizeItClaims(): void
    {
        $icons = $this->manifest()['icons'] ?? null;
        self::assertIsArray($icons);

        $seen = [];
        foreach ($icons as $icon) {
            self::assertIsArray($icon);
            $src = $icon['src'] ?? null;
            $sizes = $icon['sizes'] ?? null;
            $purpose = $icon['purpose'] ?? null;
            self::assertIsString($src);
            self::assertIsString($sizes);
            self::assertIsString($purpose);
            self::assertSame('image/png', $icon['type'] ?? null);
            self::assertStringStartsWith('/beaup-app/', $src, 'an icon lives at a stable address, outside AssetMapper');

            [$width, $height] = $this->pngSize($src);
            self::assertSame($sizes, $width.'x'.$height, $src);
            $seen[] = $purpose.' '.$sizes;
        }

        // Chrome asks for 192 and 512; Android's launcher masks the maskable one.
        self::assertContains('any 192x192', $seen);
        self::assertContains('any 512x512', $seen);
        self::assertContains('maskable 512x512', $seen);
        self::assertSame([180, 180], $this->pngSize('/beaup-app/apple-touch-icon.png'));
    }

    public function testCaddyServesTheManifestUnderItsOwnType(): void
    {
        // The production image has no /etc/mime.types: without this rule the manifest would go out as
        // application/octet-stream.
        $caddyfile = (string) file_get_contents(\dirname(__DIR__, 2).'/frankenphp/Caddyfile');

        self::assertStringContainsString('@webAppManifest path /manifest.webmanifest', $caddyfile);
        self::assertStringContainsString('header @webAppManifest Content-Type "application/manifest+json"', $caddyfile);
    }

    public function testThePagesWithoutAnAccountLinkTheManifest(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        $this->assertWebAppHead($crawler);
    }

    public function testThePublicLayoutLinksTheManifestToo(): void
    {
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'install.teacher');
        static::getContainer()->get(OnlineCoursePageHandles::class)->create($teacher, 'install-page', 'Cours de test');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->loginUser($teacher);
        $crawler = $this->client->request('GET', '/courses/install-page');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.cm-pub'), 'the page is drawn by layout/public.html.twig');
        $this->assertWebAppHead($crawler);
    }

    public function testEveryRoleReachesTheInstallScreenFromItsMenu(): void
    {
        foreach ([
            ['ROLE_USER', 'ROLE_STUDENT'],
            ['ROLE_USER', 'ROLE_TEACHER'],
            ['ROLE_USER', 'ROLE_STAFF'],
            ['ROLE_USER', 'ROLE_ADMIN'],
        ] as $i => $roles) {
            $this->client->loginUser($this->createUser($roles, 'install.user'.$i));
            $crawler = $this->client->request('GET', '/install');

            self::assertResponseIsSuccessful(implode('/', $roles));
            $this->assertWebAppHead($crawler);
            self::assertCount(1, $crawler->filter('.cm-user__menu a[href="/install"]'));
            // Accueil › Installer beaup-app.
            self::assertSame(['Accueil', 'Installer beaup-app'], $crawler->filter('.cm-breadcrumb a')->each(static fn (Crawler $a): string => trim($a->text())));
            self::assertCount(1, $crawler->filter('[data-controller="web-app-install"] [data-web-app-install-target="button"]'));
            self::assertCount(3, $crawler->filter('[data-web-app-install-target="iosCard"] .cm-app-card__step'));
        }
    }

    public function testTheGpsProbeStaysInstallableAsItself(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_ECO'], 'install.eco'));
        $crawler = $this->client->request('GET', '/eco/pwa-probe');

        self::assertResponseIsSuccessful();
        // One manifest per page: two and the browser takes the first.
        self::assertSame(['/eco-pwa-probe/manifest.json'], $crawler->filter('link[rel="manifest"]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
    }

    public function testTheRetiredCampusWebAppSendsEveryOldAddressHome(): void
    {
        foreach (['/campus-app', '/campus-app/', '/campus-app/index.html', '/campus-app/main.dart.js', '/campus-app/?login=abc.def', '/campus-app/icons/Icon-192.png'] as $path) {
            $this->client->getCookieJar()->clear();
            $this->client->request('GET', $path);

            self::assertResponseStatusCodeSame(301, $path);
            self::assertResponseRedirects('/', 301, $path);
        }

        // Signed in too: an old icon opens on the site's home.
        $this->client->loginUser($this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'install.campus'));
        $this->client->request('GET', '/campus-app/');
        self::assertResponseRedirects('/', 301);
    }

    public function testTheOldServiceWorkerAddressServesTheKillSwitch(): void
    {
        // The one file left of the build: Caddy serves it as a file (it exists), so it never
        // reaches the redirect above - which a service-worker update would refuse.
        self::assertSame(['campus_sw.js'], array_values(array_diff((array) scandir($this->publicPath('/campus-app')), ['.', '..'])));
        $worker = (string) file_get_contents($this->publicPath('/campus-app/campus_sw.js'));

        self::assertStringContainsString('self.skipWaiting()', $worker);
        self::assertStringContainsString('self.clients.claim()', $worker);
        self::assertStringContainsString("name.startsWith('campus-app-')", $worker, 'only the old app\'s caches: e-CO keeps its own in the same origin');
        self::assertStringContainsString('caches.delete(name)', $worker);
        self::assertStringContainsString('self.registration.unregister()', $worker);
        self::assertStringContainsString("client.navigate('/')", $worker);
        self::assertStringNotContainsString("addEventListener('fetch'", $worker);

        // Opening the old icon is a redirect out of the worker's scope, which Chrome does not follow
        // with an update check: every page of the site clears the old worker itself.
        $root = \dirname(__DIR__, 2);
        self::assertStringContainsString("import './web_app/retired_campus_app.js';", (string) file_get_contents($root.'/assets/app.js'));
        $cleanup = (string) file_get_contents($root.'/assets/web_app/retired_campus_app.js');
        self::assertStringContainsString("'/campus-app/' === new URL(registration.scope).pathname", $cleanup);
        self::assertStringContainsString("name.startsWith('campus-app-')", $cleanup);
        self::assertStringNotContainsString('serviceWorker.register', $cleanup, 'the site registers no worker of its own');

        $caddyfile = (string) file_get_contents($root.'/frankenphp/Caddyfile');
        self::assertStringContainsString('@campusAppKillSwitch path /campus-app/campus_sw.js', $caddyfile);
        self::assertStringNotContainsString('rewrite /campus-app/', $caddyfile);
        // e-CO stays the other installable app, untouched.
        self::assertStringContainsString('rewrite /eco-app/ /eco-app/index.html', $caddyfile);
        self::assertFileExists($this->publicPath('/eco-app/manifest.json'));
    }

    public function testTheMobileAppResourcesPageOffersBeaupAppInsteadOfTheWebBuild(): void
    {
        $this->client->loginUser($this->createUser(['ROLE_USER', 'ROLE_STUDENT'], 'install.resources'));
        $crawler = $this->client->request('GET', '/resources/mobile-app');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.cm-app-cards a[href="/install"]'));
        self::assertStringNotContainsString('/campus-app', (string) $this->client->getResponse()->getContent());
        // The APK and the IPA stay offered.
        self::assertCount(2, $crawler->filter('.cm-app-cards .cm-app-card__download'));
    }

    private function assertWebAppHead(Crawler $crawler): void
    {
        self::assertSame(['/manifest.webmanifest'], $crawler->filter('link[rel="manifest"]')->each(static fn (Crawler $link): string => (string) $link->attr('href')));
        self::assertSame('beaup-app', $crawler->filter('meta[name="apple-mobile-web-app-title"]')->attr('content'));
        self::assertSame('yes', $crawler->filter('meta[name="apple-mobile-web-app-capable"]')->attr('content'));
        self::assertCount(1, $crawler->filter('meta[name="theme-color"]'));
        self::assertSame('/beaup-app/apple-touch-icon.png', $crawler->filter('link[rel="apple-touch-icon"]')->attr('href'));
        self::assertStringContainsString('viewport-fit=cover', (string) $crawler->filter('meta[name="viewport"]')->attr('content'));
    }

    /**
     * @return array<mixed>
     */
    private function manifest(): array
    {
        $manifest = json_decode((string) file_get_contents($this->publicPath('/manifest.webmanifest')), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        return $manifest;
    }

    /**
     * @return array{int, int}
     */
    private function pngSize(string $src): array
    {
        $info = getimagesize($this->publicPath($src));
        self::assertIsArray($info, $src);
        self::assertSame(\IMAGETYPE_PNG, $info[2], $src);

        return [$info[0], $info[1]];
    }

    private function publicPath(string $src): string
    {
        $path = \dirname(__DIR__, 2).'/public'.$src;
        self::assertFileExists($path);

        return $path;
    }
}
