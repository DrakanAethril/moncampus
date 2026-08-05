<?php

namespace App\Controller;

use App\Entity\EmailMessage;
use App\Repository\EmailMessageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * OUTIL DE DÉVELOPPEMENT - visualise les mails captés par le worker Courrier école, pour vérifier
 * de bout en bout qu'un message parti d'une boîte extérieure arrive bien jusqu'à la base, rattaché
 * au bon élève.
 *
 * Ce fichier n'est pas versionné, au même titre que les commandes de seed : il n'a rien à faire
 * ailleurs qu'en local. La garde ci-dessous est une seconde barrière, pour le cas où il serait
 * committé par inadvertance - un écran qui expose la correspondance d'élèves ne doit jamais
 * pouvoir répondre en production, même derrière une authentification.
 *
 * Les écrans réels de la boîte Courrier école relèvent de la partie 2 du handoff, encore en design.
 * Rien ici n'est une préfiguration de ce qu'ils seront.
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
