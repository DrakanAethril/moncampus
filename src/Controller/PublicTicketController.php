<?php

namespace App\Controller;

use App\Entity\Ticket;
use App\Entity\TicketCategory;
use App\Form\AnonymousTicketType;
use App\Repository\TicketCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

// Routed under /login/* so it falls under the existing '{ path: ^/login, roles: PUBLIC_ACCESS }'
// access_control rule (see config/packages/security.yaml) - no security.yaml change needed, and
// it keeps the one unauthenticated write endpoint in this app clearly grouped with the other
// logged-out page instead of opening a new public path prefix.
class PublicTicketController extends AbstractController
{
    #[Route(path: '/login/help', name: 'app_login_help')]
    public function accountHelp(
        Request $request,
        EntityManagerInterface $entityManager,
        TicketCategoryRepository $categoryRepository,
        #[Target('anonymous_ticket')] RateLimiterFactoryInterface $limiter,
    ): Response {
        $category = $categoryRepository->findOneByName(TicketCategory::ACCOUNT_ACCESS_NAME)
            ?? throw new \RuntimeException('The "'.TicketCategory::ACCOUNT_ACCESS_NAME.'" ticket category is missing - a handler needs to create it once under Ticket Queue > Categories.');

        // Subject is set to a placeholder before validation (Ticket::$subject carries
        // Assert\NotBlank in the Default group) and refined below once the reporter's name is
        // known - setting it only after isValid() succeeds would make the form permanently
        // invalid, same trap as LaptopController's lentBy fix.
        $ticket = (new Ticket())->setCategory($category)->setSubject('Account access issue');

        $form = $this->createForm(AnonymousTicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Honeypot: a bot filled in a field real users never see (hidden off-screen via CSS -
            // see account_help.html.twig). Pretend success without creating anything, so it
            // doesn't learn to leave the field alone next time.
            if ('' !== (string) $form->get('website')->getData()) {
                return $this->redirectToRoute('app_login_help_thanks');
            }

            if (!$limiter->create($request->getClientIp())->consume(1)->isAccepted()) {
                return $this->render('security/account_help.html.twig', [
                    'form' => $form,
                    'rateLimited' => true,
                ]);
            }

            $ticket->setSubject(sprintf('Account access issue reported by %s', $ticket->getReporterName()));

            $entityManager->persist($ticket);
            $entityManager->flush();

            return $this->redirectToRoute('app_login_help_thanks');
        }

        return $this->render('security/account_help.html.twig', [
            'form' => $form,
            'rateLimited' => false,
        ]);
    }

    #[Route(path: '/login/help/thanks', name: 'app_login_help_thanks')]
    public function accountHelpThanks(): Response
    {
        return $this->render('security/account_help_thanks.html.twig');
    }
}
