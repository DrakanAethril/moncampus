<?php

declare(strict_types=1);

namespace App\Attribute;

use App\Enum\Feature;

/**
 * "This controller - or this action - belongs to that feature; if it is off, it does not exist.".
 *
 * Read by App\EventSubscriber\FeatureAccessSubscriber on `kernel.controller`, which answers a
 * **404**, never a 403 (design/validated/feature-access.md §7.1). That is the reflex
 * App\Controller\ProgramFeatureGuardTrait already had for the four Program booleans, generalised:
 * an extinguished screen does not exist, it is not forbidden.
 *
 * Declarative on purpose. Forty hand-written `if`s across 127 controllers cannot be verified;
 * an attribute can, and tests/Functional/FeatureCoverageTest.php is what verifies it - every route
 * either carries one, inherits one from its class, or is named in that test's list of deliberate
 * exemptions.
 *
 *     #[RequiresFeature(Feature::Agenda)]
 *     class AgendaController extends AbstractController { ... }
 *
 * On a class it covers every action; on an action it wins over the class's, which is how a screen
 * that belongs to a narrower feature than its neighbours states it (the self-assessment inside the
 * gradebook, a video inside a course space).
 *
 * Several features may be named. They are read as an **AND**: every one of them has to be on. The
 * usual case for that is a screen that is genuinely the meeting of two - a quiz launched inside a
 * course space - and it stays rare, because a screen that needs three features is usually a screen
 * that has not been placed yet.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class RequiresFeature
{
    /** @var list<Feature> */
    public readonly array $features;

    public function __construct(Feature ...$features)
    {
        $this->features = array_values($features);
    }
}
