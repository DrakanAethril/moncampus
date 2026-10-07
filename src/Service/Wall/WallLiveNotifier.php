<?php

declare(strict_types=1);

namespace App\Service\Wall;

use App\Entity\Wall;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells the browsers that have a wall open that it has moved.
 *
 * The message carries the wall's new revision and nothing of the wall: what each person may read
 * differs - a card awaiting validation, a card they may or may not change - so every browser asks
 * for its own reading again (App\Controller\Wall\WallController::state()) rather than being handed
 * somebody else's.
 *
 * Published on a private topic and read through the bundle's cookie mechanism, like the word cloud
 * and the live quiz. **It never fails what it reports on**: the change is committed by the time
 * this runs, and a wall that missed a message catches up at its next action or its next load.
 */
class WallLiveNotifier
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function topic(Wall $wall): string
    {
        return \sprintf('/walls/%d', (int) $wall->getId());
    }

    public function publish(Wall $wall, bool $deleted = false): void
    {
        try {
            $this->hub->publish(new Update(
                $this->topic($wall),
                json_encode(['revision' => $wall->getRevision(), 'deleted' => $deleted], \JSON_THROW_ON_ERROR),
                true,
            ));
        } catch (\Throwable $exception) {
            $this->logger->warning('The browsers watching a wall could not be told of a change.', [
                'wall' => $wall->getId(),
                'exception' => $exception,
            ]);
        }
    }
}
