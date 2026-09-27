<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\User;
use App\Security\FeatureAccess;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The tools of the connector, filtered by what the user's features allow.
 *
 * A tool whose feature is off for the user is **not listed at all**, rather than listed and then
 * refused: the model plans with the list it is given, and a tool it cannot use is a plan that fails
 * halfway. Calling one anyway answers « unknown tool », like a screen of an extinguished feature
 * answers 404.
 */
final readonly class McpToolRegistry
{
    /** @var list<McpTool> */
    private array $tools;

    /**
     * @param iterable<McpTool> $tools
     */
    public function __construct(
        #[AutowireIterator(McpTool::TAG)] iterable $tools,
        private FeatureAccess $featureAccess,
    ) {
        $list = [];
        foreach ($tools as $tool) {
            $list[] = $tool;
        }
        usort($list, static fn (McpTool $a, McpTool $b): int => strcmp($a->name(), $b->name()));
        $this->tools = $list;
    }

    /** @return list<McpTool> */
    public function availableTo(User $user): array
    {
        return array_values(array_filter($this->tools, fn (McpTool $tool): bool => $this->isAvailableTo($tool, $user)));
    }

    public function find(string $name, User $user): ?McpTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $this->isAvailableTo($tool, $user) ? $tool : null;
            }
        }

        return null;
    }

    private function isAvailableTo(McpTool $tool, User $user): bool
    {
        foreach ($tool->features() as $feature) {
            if (!$this->featureAccess->isEnabled($feature, $user)) {
                return false;
            }
        }

        return true;
    }
}
