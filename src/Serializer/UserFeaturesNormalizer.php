<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\User;
use App\Security\FeatureAccess;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Adds `features` to what GET /api/me answers - the whole catalogue resolved for the account behind
 * the token (design/validated/feature-access.md §10.1).
 *
 * One round trip, on the request the app already makes: the mobile shell reads the object at startup
 * and again on every return to the foreground, and decides from it which tabs, tiles and cards
 * exist. Anything else would mean an extra call before the first screen.
 *
 * **The list does not replace the guard.** Every endpoint carries the same
 * App\Attribute\RequiresFeature its web counterpart does and answers 404 on its own; this only stops
 * the app from drawing a door that slams. The distinction matters because a JWT issued before a
 * switch-off stays valid (§8.7): the app must treat that 404 as an ordinary answer - hide the entry
 * and refresh this list - never as a technical error.
 *
 * A normalizer rather than a field on the entity: App\Entity\User has no business knowing about the
 * feature matrix, and the answer depends on who is reading rather than on the row.
 *
 * The `ALREADY_CALLED` flag is the standard way of handing the object back to the normalizer chain
 * without recursing into this one - without it, delegating below would re-enter here for ever.
 */
class UserFeaturesNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'USER_FEATURES_NORMALIZER_ALREADY_CALLED';

    public function __construct(private readonly FeatureAccess $featureAccess)
    {
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): \ArrayObject|array|string|int|float|bool|null
    {
        $context[self::ALREADY_CALLED] = true;
        $normalized = $this->normalizer->normalize($data, $format, $context);

        if ($data instanceof User && \is_array($normalized)) {
            $normalized['features'] = $this->featureAccess->all($data);
        }

        return $normalized;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof User && true !== ($context[self::ALREADY_CALLED] ?? false);
    }

    /** @return array<class-string|string, bool|null> */
    public function getSupportedTypes(?string $format): array
    {
        // Not cacheable: the answer depends on the reader and on two tables, not on the class alone.
        return [User::class => false];
    }
}
