<?php

namespace App\Controller;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Deliberately not linked from any nav template - reached directly by URL to verify the mailer
// configuration (Mailpit in dev, AWS SES in production - see config/packages/mailer.yaml and
// docs/production.md) actually works end-to-end, including in production where there's no other
// email-sending feature yet to trigger organically.
#[IsGranted('ROLE_ADMIN')]
class SystemTestMailController extends AbstractController
{
    private const string RECIPIENT = 'tech@beaupeyrat.com';

    // Sends via the transport directly (not MailerInterface) - this app has no Messenger worker
    // consuming the "async" transport that Symfony\Component\Mailer\Messenger\SendEmailMessage is
    // routed to (config/packages/messenger.yaml), so going through MailerInterface would just
    // queue the message forever instead of actually testing anything. TransportInterface::send()
    // still dispatches the real (non-queued) MessageEvent, so the default From header
    // (config/packages/mailer.yaml) and the TemplatedEmail's Twig rendering both still apply.
    #[Route(path: '/system/test-mail', name: 'app_system_test_mail')]
    public function __invoke(
        TransportInterface $transport,
        #[Autowire(param: 'kernel.environment')] string $environment,
        #[Autowire(env: 'AWS_SES_ACCESS_KEY_ID')] string $sesAccessKeyId,
        #[Autowire(env: 'AWS_SES_SECRET_ACCESS_KEY')] string $sesSecretAccessKey,
        #[Autowire(env: 'AWS_SES_REGION')] string $sesRegion,
    ): Response {
        // TEMPORARY: dumps the exact credentials/DSN this container resolved, to debug the SES
        // InvalidSignatureException / "no key activity" issue - remove once that's resolved.
        $debug = \sprintf(
            "AWS_SES_ACCESS_KEY_ID=%s\nAWS_SES_SECRET_ACCESS_KEY=%s\nAWS_SES_REGION=%s\nDSN=ses+api://%s:%s@default?region=%s\n\n",
            $sesAccessKeyId,
            $sesSecretAccessKey,
            $sesRegion,
            rawurlencode($sesAccessKeyId),
            rawurlencode($sesSecretAccessKey),
            $sesRegion,
        );

        $email = (new TemplatedEmail())
            ->to(new Address(self::RECIPIENT))
            ->subject("Test d'envoi d'email - Institution Beaupeyrat")
            ->htmlTemplate('emails/test.html.twig')
            ->context([
                'environment' => $environment,
                'sentAt' => new \DateTimeImmutable(),
            ]);

        try {
            $transport->send($email);
        } catch (TransportExceptionInterface $exception) {
            return new Response($debug.'Failed to send test email: '.$exception->getMessage(), 500);
        }

        return new Response($debug.\sprintf('Test email sent to %s.', self::RECIPIENT));
    }
}
