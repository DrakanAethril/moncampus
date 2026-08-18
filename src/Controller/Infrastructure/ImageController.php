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
 * What a host can create a machine from: its clonable templates, and the ISOs on its storages.
 *
 * The two halves cost very different things, and the screen exists partly to make that visible.
 * **Templates cost nothing**: they are the rows of `/cluster/resources` whose `template` flag is
 * set, so they come with the machine list rather than in calls of their own. ISOs are files on
 * storages, which nothing in the cluster listing knows about, so each storage advertising `iso`
 * content is asked in turn.
 *
 * There is no upload here and no deletion: this screen reads what an administrator put on the
 * hypervisor by other means.
 */
#[IsGranted('ROLE_ADMIN')]
class ImageController extends AbstractController
{
    use InfrastructureTrait;

    #[Route(path: '/infrastructure/hosts/{id}/images', name: 'app_infrastructure_images', requirements: ['id' => '\d+'])]
    public function index(
        ProxmoxHostRepository $repository,
        ProxmoxClientFactory $clientFactory,
        ProxmoxInventory $inventory,
        int $id,
    ): Response {
        $host = $this->findHostOrNotFound($repository, $id);
        $this->denyAccessUnlessGranted(ProxmoxHostVoter::VIEW, $host);

        $templates = [];
        $isos = [];
        $failure = null;

        try {
            $client = $clientFactory->operate($host);
            $nodes = $inventory->nodes($client);
            $templates = $inventory->templates($inventory->guests($client));
            $isos = $inventory->isoImages($client, $nodes);
        } catch (ProxmoxUnavailableException $exception) {
            $failure = $exception->getMessage();
        }

        return $this->render('infrastructure/images.html.twig', [
            'activeNav' => 'images',
            'host' => $host,
            'templates' => $templates,
            'isos' => $isos,
            'failure' => $failure,
            'canCreate' => $this->isGranted(ProxmoxHostVoter::PROVISION, $host),
        ]);
    }
}
