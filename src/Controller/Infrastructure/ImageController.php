<?php

declare(strict_types=1);

namespace App\Controller\Infrastructure;

use App\Repository\ProxmoxHostRepository;
use App\Security\Voter\ProxmoxHostVoter;
use App\Service\Proxmox\ProxmoxClientFactory;
use App\Service\Proxmox\ProxmoxInventory;
use App\Service\Proxmox\ProxmoxUnavailableException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * What a machine can be created from, across every declared host: the clonable templates, and the
 * ISOs on the storages.
 *
 * The two halves cost very different things, and the screen exists partly to make that visible.
 * **Templates cost nothing**: they are the rows of `/cluster/resources` whose `template` flag is
 * set, so they come with the machine list rather than in calls of their own. ISOs are files on
 * storages, which nothing in the cluster listing knows about, so each storage advertising `iso`
 * content is asked in turn.
 *
 * Every host at once and deliberately no host filter, unlike the machines list. What is being
 * looked for here is an image - "is there a Debian 13 template anywhere" - and a filter that hides
 * the host holding the only copy would answer no. The host is a column instead, because it decides
 * where the machine created from that image will land.
 *
 * There is no upload here and no deletion: this screen reads what an administrator put on the
 * hypervisors by other means.
 */
#[IsGranted('ROLE_ADMIN')]
class ImageController extends AbstractController
{
    use InfrastructureTrait;

    #[Route(path: '/infrastructure/images', name: 'app_infrastructure_images')]
    public function index(
        ProxmoxHostRepository $repository,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
    ): Response {
        $templates = [];
        $isos = [];
        $failures = [];

        foreach ($repository->findOrdered() as $host) {
            $this->denyAccessUnlessGranted(ProxmoxHostVoter::VIEW, $host);

            try {
                $client = $clientFactory->operate($host);
                $nodes = $inventory->nodes($client);
                $hostTemplates = $inventory->templates($inventory->guests($client));
                $hostIsos = $inventory->isoImages($client, $nodes);
            } catch (ProxmoxUnavailableException $exception) {
                // Named, and the other hosts are still listed: an image screen that goes blank
                // because one hypervisor is down hides every image that is still reachable.
                $failures[] = ['host' => $host, 'message' => $exception->getMessage()];

                continue;
            }

            $canCreate = $this->isGranted(ProxmoxHostVoter::PROVISION, $host);

            foreach ($hostTemplates as $template) {
                $templates[] = ['host' => $host, 'template' => $template, 'canCreate' => $canCreate];
            }

            foreach ($hostIsos as $iso) {
                $isos[] = ['host' => $host, 'iso' => $iso, 'canCreate' => $canCreate];
            }
        }

        return $this->render('infrastructure/images.html.twig', [
            'activeNav' => 'images',
            'templates' => $templates,
            'isos' => $isos,
            'failures' => $failures,
        ]);
    }
}
