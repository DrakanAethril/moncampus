<?php

namespace App\Controller;

use App\Entity\EmailAttachment;
use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Security\StructureAccessChecker;
use Aws\S3\S3Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves a school mail attachment (design_handoff_stage_alternance, screens 3b and 2a).
 *
 * Streamed through the application rather than handed out as a presigned S3 URL, deliberately: a
 * presigned link keeps working for whoever it is forwarded to, and these are files a company sent to
 * one named student. Going through here means every download is a request we authorise.
 *
 * Whoever may read the mail may read its attachments - the student it belongs to, and the staff who
 * can already see their sheet (screen 2a). No one else, including other students.
 */
#[IsGranted('ROLE_USER')]
class SchoolMailAttachmentController extends AbstractController
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly StructureAccessChecker $accessChecker,
        private readonly S3Client $mailS3Client,
        private readonly string $mailBucket,
    ) {
    }

    #[Route(path: '/school-mail/attachments/{id}', name: 'app_school_mail_attachment', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function download(EmailAttachment $attachment): Response
    {
        $owner = $attachment->getEmailMessage()?->getStudent();

        if (null === $owner) {
            throw $this->createNotFoundException();
        }

        $this->denyUnlessAllowed($owner);

        try {
            $object = $this->mailS3Client->getObject([
                'Bucket' => $this->mailBucket,
                'Key' => $attachment->getS3Key(),
            ]);
        } catch (\Throwable) {
            // The row exists but the object does not: a 404 says the truth better than a 500 would.
            throw $this->createNotFoundException();
        }

        $response = new StreamedResponse(static function () use ($object): void {
            echo (string) $object['Body'];
        });

        $response->headers->set('Content-Type', $attachment->getContentType() ?: 'application/octet-stream');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $attachment->getFilename(),
            // A transliterated fallback for clients that cannot read the UTF-8 form.
            preg_replace('/[^A-Za-z0-9._-]+/', '-', $attachment->getFilename()) ?: 'piece-jointe',
        ));

        return $response;
    }

    private function denyUnlessAllowed(User $owner): void
    {
        $viewer = $this->getUser();

        if ($viewer instanceof User && $viewer->getId() === $owner->getId()) {
            return;
        }

        if ($this->accessChecker->isStaff()) {
            return;
        }

        if ($this->isGranted('ROLE_TEACHER')) {
            foreach ($this->programRepository->findAllActiveForStudent($owner) as $program) {
                if ($this->accessChecker->isProgramVisible($program)) {
                    return;
                }
            }
        }

        throw $this->createAccessDeniedException();
    }
}
