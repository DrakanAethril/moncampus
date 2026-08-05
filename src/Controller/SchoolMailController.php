<?php

namespace App\Controller;

use App\Entity\EmailMessage;
use App\Entity\JobApplication;
use App\Entity\User;
use App\Enum\EmailDirection;
use App\Repository\EmailAliasRepository;
use App\Repository\EmailMessageRepository;
use App\Repository\JobApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Courrier école » — la boîte externe de l'élève (design_handoff_stage_alternance, écran 3b).
 *
 * Boîte à trois volets, comme la Messagerie interne dont elle réutilise l'habillage. Les deux ne
 * communiquent jamais (principe n°8 du handoff) : ici on ne voit que des mails SES échangés avec
 * des adresses extérieures, là-bas que des messages internes. D'où le bandeau d'identité permanent,
 * qui rappelle depuis quelle adresse l'élève écrit.
 *
 * Aucun classement des réponses (principe n°1) : la pastille « Réponse reçue » dit qu'un message
 * est arrivé, jamais ce qu'il contient. Le seul état de lecture suivi est celui de l'élève dans sa
 * propre boîte - rien n'est su de ce que l'entreprise fait des envois.
 */
#[IsGranted('ROLE_STUDENT')]
class SchoolMailController extends AbstractController
{
    public function __construct(
        private readonly EmailMessageRepository $messageRepository,
        private readonly JobApplicationRepository $applicationRepository,
        private readonly EmailAliasRepository $aliasRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(MAIL_STUDENT_DOMAIN)%')]
        private readonly string $studentMailDomain,
    ) {
    }

    #[Route(path: '/school-mail', name: 'app_school_mail', methods: ['GET'])]
    public function inbox(Request $request): Response
    {
        return $this->renderFolder($request, EmailDirection::Inbound);
    }

    #[Route(path: '/school-mail/sent', name: 'app_school_mail_sent', methods: ['GET'])]
    public function sent(Request $request): Response
    {
        return $this->renderFolder($request, EmailDirection::Outbound);
    }

    /**
     * L'ouverture d'un mail. Le dossier affiché suit le sens du message plutôt qu'un paramètre :
     * arriver sur un envoi depuis « Voir les mails » doit ouvrir les Envoyés, pas la réception.
     */
    #[Route(path: '/school-mail/mail/{id}', name: 'app_school_mail_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, EmailMessage $message): Response
    {
        /** @var User $student */
        $student = $this->getUser();

        if ($message->getStudent()?->getId() !== $student->getId()) {
            throw $this->createAccessDeniedException();
        }

        if ($message->isUnread()) {
            $message->setReadAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return $this->renderFolder($request, $message->getDirection(), $message);
    }

    private function renderFolder(Request $request, EmailDirection $direction, ?EmailMessage $selected = null): Response
    {
        /** @var User $student */
        $student = $this->getUser();

        $search = trim((string) $request->query->get('q', ''));
        $application = $this->resolveApplication($request, $student);

        $messages = $this->messageRepository->findFolderForStudent($student, $direction, $application, '' === $search ? null : $search);

        return $this->render('school_mail/index.html.twig', [
            'folder' => EmailDirection::Inbound === $direction ? 'inbox' : 'sent',
            'messages' => $messages,
            'selected' => $selected,
            'search' => $search,
            'application' => $application,
            'counts' => [
                'unread' => $this->messageRepository->countUnreadForStudent($student),
                'inbox' => $this->messageRepository->countFolderForStudent($student, EmailDirection::Inbound),
                'sent' => $this->messageRepository->countFolderForStudent($student, EmailDirection::Outbound),
            ],
            'contexts' => $this->contexts($student),
            // Le bandeau d'identité permanent de la créa : « Vous écrivez en tant que … ».
            'mailbox' => $this->mailbox($student),
        ]);
    }

    private function resolveApplication(Request $request, User $student): ?JobApplication
    {
        $id = $request->query->getInt('application');

        if ($id <= 0) {
            return null;
        }

        $application = $this->applicationRepository->find($id);

        // Un filtre sur la démarche d'un autre élève ne vaut pas mieux qu'un filtre inconnu.
        if (null === $application || $application->getStudent()?->getId() !== $student->getId()) {
            return null;
        }

        return $application;
    }

    /**
     * Le bloc « Contexte : candidatures » : les démarches de l'élève et leur volume de mails, dans
     * l'ordre de la dernière activité, comme la liste de l'écran 2b.
     *
     * @return list<array{application: JobApplication, mailCount: int, accent: int}>
     */
    private function contexts(User $student): array
    {
        $counts = $this->messageRepository->countByApplicationForStudent($student);
        $contexts = [];

        foreach ($this->applicationRepository->findForStudent($student) as $application) {
            // Une démarche sans un seul mail n'est pas un contexte de lecture : elle n'a rien à
            // filtrer. Elle reste visible sur l'écran 2b, qui liste les démarches, pas les mails.
            if (0 === ($counts[$application->getId()] ?? 0)) {
                continue;
            }

            $contexts[] = [
                'application' => $application,
                'mailCount' => $counts[$application->getId()] ?? 0,
                'accent' => $application->getEnterprise()->getId() % 5,
            ];
        }

        usort($contexts, static fn (array $left, array $right): int => ($right['application']->getLastActivityAt() <=> $left['application']->getLastActivityAt()));

        return $contexts;
    }

    private function mailbox(User $student): ?string
    {
        foreach ($this->aliasRepository->findAllForUser($student) as $alias) {
            if ($alias->isActive()) {
                return $alias->toAddress($this->studentMailDomain);
            }
        }

        return null;
    }
}
