<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\DeploymentNoticeBoard;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

// Renders the « une mise à jour est en cours » strip into the shell. A Twig function rather than a
// value handed over by every controller, for the reason every other global banner here is one: it
// belongs to the page frame and no controller owns it - 127 of them would have to remember.
//
// It returns markup rather than the notice, so that the template the shell prints and the one the
// status endpoint hands back to the poll are literally the same file (templates/_deployment_notice
// .html.twig). Empty string when nothing is going on, which is nearly always.
//
// No memo of its own: App\Service\DeploymentNoticeBoard already holds the answer for the request,
// and holds it behind a ResetInterface - the shape a worker-mode singleton has to have here.
class DeploymentNoticeExtension extends AbstractExtension
{
    public function __construct(private readonly DeploymentNoticeBoard $board)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('deployment_notice_html', $this->html(...), ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }

    public function html(Environment $twig): string
    {
        $notice = $this->board->current();

        return null === $notice ? '' : $twig->render('_deployment_notice.html.twig', ['notice' => $notice]);
    }
}
