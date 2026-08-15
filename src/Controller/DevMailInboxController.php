<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\EmailMessage;
use App\Repository\EmailMessageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * DEVELOPMENT TOOL - views the mails caught by the Courrier école worker, to check end to end that a
 * message sent from an outside mailbox does reach the database, attached to the right student.
 *
 * This file is not versioned, on the same grounds as the seed commands: it has no business anywhere
 * but locally. The guard below is a second barrier, in case it were committed by accident - a screen
 * that exposes students' correspondence must never be able to answer in production, even behind an
 * authentication.
 *
 * The real screens of the Courrier école mailbox belong to part 2 of the handoff, still in design.
 * Nothing here prefigures what they will be.
 */
class DevMailInboxController extends AbstractController
{
    public function __construct(
        private readonly EmailMessageRepository $messageRepository,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(MAIL_STUDENT_DOMAIN)%')]
        private readonly string $studentMailDomain,
    ) {
    }

    #[Route('/dev/mails', name: 'dev_mail_inbox', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyUnlessDev();

        return $this->render('dev/mail_inbox/index.html.twig', [
            'messages' => $this->messageRepository->findBy([], ['createdAt' => 'DESC'], 100),
            'domain' => $this->studentMailDomain,
        ]);
    }

    #[Route('/dev/mails/{id}', name: 'dev_mail_inbox_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(EmailMessage $message): Response
    {
        $this->denyUnlessDev();

        return $this->render('dev/mail_inbox/show.html.twig', [
            'message' => $message,
            'domain' => $this->studentMailDomain,
        ]);
    }

    private function denyUnlessDev(): void
    {
        if ('dev' !== $this->environment) {
            throw $this->createNotFoundException();
        }
    }
}
