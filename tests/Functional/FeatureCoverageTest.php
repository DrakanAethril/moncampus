<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Attribute\RequiresFeature;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Walks the router and refuses a route that neither carries App\Attribute\RequiresFeature nor
 * appears in the list of deliberate exemptions below.
 *
 * **This is the only thing that makes the coverage of ~950 routes verifiable at all**
 * (design/validated/feature-access.md §7.1). Without it, "the agenda is switched off" means the menu
 * entry is gone and /agenda/events/new still answers - a feature only the curious reach, which is
 * the first of the twelve things this design forbids. Nobody audits nine hundred routes by hand
 * twice; this does it on every push.
 *
 * A new route therefore fails here until somebody decides which feature it belongs to. That is the
 * intended friction: the decision is cheap when the route is being written and expensive a year
 * later.
 *
 * **An exemption is a decision too**, which is why each one below is grouped under the reason it was
 * granted rather than listed flat. There are exactly six reasons, and a route that fits none of them
 * needs an attribute, not a seventh.
 */
class FeatureCoverageTest extends KernelTestCase
{
    /**
     * The screens the catalogue deliberately leaves out (§1, "Hors périmètre, volontairement"), plus
     * the ones that would lock somebody out if they could be switched off.
     *
     * @return array<string, list<string>> reason => route name prefixes and exact names
     */
    private function exemptions(): array
    {
        return [
            // §1: putting these in the catalogue would add a lock-out risk for nothing. The profile,
            // the theme, the language, the login, the changelog and the technical description are
            // not features an establishment "runs" - they are how somebody uses their own account.
            'outside the catalogue on purpose' => [
                'app_about',
                'app_changelog',
                'app_technical',
                'app_profile',
                'app_locale_switch',
                'app_logout',
                'app_password_renewal',
                'app_home',
                'app_resources',
            ],
            // Reached without being logged in, so there is no account to resolve a feature against:
            // the resolver would answer the catalogue's defaults for an anonymous visitor and could
            // only ever shut the door on somebody trying to get in.
            'public, no account yet' => [
                // The sign-in screen, the magic link, and « Je n'arrive pas à me connecter » -
                // App\Controller\PublicTicketController, which is the support ticket somebody opens
                // precisely because they cannot get in.
                'app_login',
                'api_login',
                'api_magic_login',
                // The three PublicContactEmailController screens, reached from a link in an e-mail
                // and therefore possibly by somebody who is not signed in.
                'app_profile_contact_email_confirm',
            ],
            // The mobile app's own account plumbing. Same reason as the row above, one step later:
            // an account that cannot read its profile cannot be told what it may see.
            'the account plumbing the mobile app needs to start at all' => [
                'api_profile_',
            ],
            // e-CO runners have **no account at all** - they authenticate by join token, checked by
            // hand in EcoRunnerApiController. The resolver would see an anonymous visitor, fall back
            // on the catalogue (`eco` is off by default) and 404 every runner in a race.
            'e-CO runners hold no account' => [
                'api_eco_runner_',
            ],
            // The one upload component of the application (App\Form\FilePickerType) is on some
            // fifteen forms across a dozen features. Its staging endpoint belongs to none of them,
            // and switching it off would take every one of those forms with it. What `file_library`
            // removes is the Bibliothèque **tab**, in the type itself - not the ability to attach a
            // file (§8.1).
            'the shared upload component, which belongs to no single feature' => [
                'app_upload_stage',
                'app_upload_discard',
            ],
            // §8.8, and the reason the "admin has everything" rule is not negotiable: no setting may
            // close the screen the settings are made on. This covers Paramètres itself - the
            // structure, the groups, a formation's own membership - which is not a feature but the
            // place features are configured from.
            'administering the platform, including this system itself' => [
                'app_settings_',
                'app_directory_users_features',
                'app_program_settings',
                'app_program_settings_referent',
                'app_program_settings_student',
                'app_program_settings_teacher',
                'app_program_students',
                'app_program_teachers',
                // The shared access-condition editor, opened from several features' screens
                // (course space, sequences, quiz). Each of those doors is guarded on its own; the
                // sub-editor behind them belongs to no one of them.
                'app_access_condition',
                // Dev-machine only: App\Controller\DevMailInboxController stands in for Mailpit and
                // is not something the establishment runs.
                'dev_mail_inbox',
            ],
        ];
    }

    public function testEveryRouteBelongsToAFeatureOrIsExemptOnPurpose(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);

        $unmarked = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');

            // Symfony's own routes (`_profiler`, `_wdt`) and API Platform's are not this
            // application's screens.
            if (!\is_string($controller) || !str_starts_with($controller, 'App\\Controller\\')) {
                continue;
            }

            if ($this->isExempt($name)) {
                continue;
            }

            if (null === $this->featureAttributeFor($controller)) {
                $unmarked[] = sprintf('%s (%s)', $name, $route->getPath());
            }
        }

        sort($unmarked);

        $this->assertSame([], $unmarked, sprintf(
            "%d route(s) belong to no feature and are not exempt.\n\n".
            "Put #[RequiresFeature(Feature::…)] on the controller (or on the action, which wins over\n".
            "its class), or add the route to this test's exemptions() with the reason it is one.\n\n%s",
            \count($unmarked),
            implode("\n", $unmarked),
        ));
    }

    /**
     * The mirror of the rule above: an exemption that no longer matches any route is an exemption
     * nobody removed, and it silently widens with every route named after it.
     */
    public function testEveryExemptionStillMatchesARoute(): void
    {
        self::bootKernel();
        $router = static::getContainer()->get(RouterInterface::class);
        $names = array_keys($router->getRouteCollection()->all());

        $stale = [];
        foreach ($this->exemptions() as $reason => $prefixes) {
            foreach ($prefixes as $prefix) {
                $matches = array_filter($names, static fn (string $name): bool => str_starts_with($name, $prefix));

                if ([] === $matches) {
                    $stale[] = sprintf('%s (%s)', $prefix, $reason);
                }
            }
        }

        $this->assertSame([], $stale, "Exemption(s) matching no route any more:\n".implode("\n", $stale));
    }

    private function isExempt(string $name): bool
    {
        foreach ($this->exemptions() as $prefixes) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The attribute the subscriber would find: the action's if it declares one, otherwise its
     * class's.
     */
    private function featureAttributeFor(string $controller): ?RequiresFeature
    {
        [$class, $method] = array_pad(explode('::', $controller, 2), 2, '__invoke');

        if (!class_exists($class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);

        if ($reflection->hasMethod($method)) {
            $onMethod = $reflection->getMethod($method)->getAttributes(RequiresFeature::class);

            if ([] !== $onMethod) {
                return $onMethod[0]->newInstance();
            }
        }

        $onClass = $reflection->getAttributes(RequiresFeature::class);

        return [] === $onClass ? null : $onClass[0]->newInstance();
    }
}
