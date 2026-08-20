<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\ProxmoxHost;
use App\Repository\ProxmoxHostRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Feeds the Proxmox console's local navigation bar
 * (templates/infrastructure/_tabs.html.twig).
 *
 * That bar is not a convenience: /infrastructure appears in no menu of the application, so it is
 * the only way from one screen of the area to the next, and the rule it enforces is that every
 * feature must be reachable by clicking from the hub. Two of its entries need a host to point at -
 * there is no aggregated multi-host view by design, each host has its own screens - and a template
 * cannot go and find one on its own.
 *
 * Deliberately **not** a nav extension in the sense the other three are: nothing here contributes
 * to the application's menus, and adding a Proxmox entry to templates/layout/app.html.twig is a
 * decision that has been taken, in the negative.
 */
class InfrastructureNavigationExtension extends AbstractExtension
{
    public function __construct(private readonly ProxmoxHostRepository $hostRepository)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('infrastructure_hosts', $this->hosts(...)),
            new TwigFunction('infrastructure_default_host', $this->defaultHost(...)),
        ];
    }

    /** @return list<ProxmoxHost> */
    public function hosts(): array
    {
        return $this->hostRepository->findOrdered();
    }

    /**
     * Where « Machines » points when the current screen is not about a particular host. The first
     * declared one, or null while none exists - in which case the entry is simply not drawn, since
     * a link that goes nowhere teaches nothing.
     */
    public function defaultHost(): ?ProxmoxHost
    {
        return $this->hosts()[0] ?? null;
    }
}
