<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Attribute\RequiresFeature;
use App\Security\FeatureAccess;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The guard of the feature system: reads App\Attribute\RequiresFeature off the action being run and
 * answers 404 when the feature is off (design/validated/feature-access.md §7.1).
 *
 * **404, never 403.** An extinguished screen does not exist; it is not forbidden. A 403 would tell
 * the reader there is something there they are not allowed to see, which is the opposite of what
 * the establishment decided - and it is already the reflex of
 * App\Controller\ProgramFeatureGuardTrait for the four Program booleans this generalises.
 *
 * **The menu is never the guard.** Hiding a link on a route that still answers is not a disabled
 * feature, it is a feature only the curious reach. That is why this sits on the request path rather
 * than in the templates, and why the Twig function next to it is a courtesy, not a control.
 *
 * It listens on `kernel.controller_arguments` rather than `kernel.controller` so it runs after
 * Symfony has resolved the attributes of the action - the framework caches them on the request,
 * which saves reflecting on every request of every screen.
 *
 * An action's own attribute wins over its class's, entirely: a screen that names a narrower feature
 * than its neighbours means "this one, and not the other". Several features on one attribute are an
 * OR - the screen exists as long as one of its audiences still has theirs.
 */
class FeatureAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly FeatureAccess $featureAccess)
    {
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $attributes = $event->getAttributes(RequiresFeature::class);

        if ([] === $attributes) {
            return;
        }

        // Symfony hands the class's attributes first and the action's after (ControllerEvent::
        // getAttributes()), and an attribute is not repeatable, so the last entry is the action's
        // whenever it declares one - which is how "the action wins over its class" is written.
        $attribute = $attributes[array_key_last($attributes)];

        foreach ($attribute->features as $feature) {
            if ($this->featureAccess->isEnabled($feature)) {
                return;
            }
        }

        throw new NotFoundHttpException(sprintf('Feature "%s" is switched off for this account.', implode('", "', array_map(static fn ($feature): string => $feature->value, $attribute->features))));
    }

    /** @return array<string, string> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => 'onControllerArguments'];
    }
}
