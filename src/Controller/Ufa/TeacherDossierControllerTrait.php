<?php

declare(strict_types=1);

namespace App\Controller\Ufa;

use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierDocument;
use App\Entity\User;
use App\Security\Voter\TeacherDossierVoter;
use App\Service\PostValue;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the controllers of « Dossiers intervenants » share: finding a dossier the person may act on,
 * and the three small gestures every write repeats.
 *
 * Whatever the Voter refuses answers **404, never 403**: a dossier one may not read is a dossier
 * that does not exist - its mere existence says an intervenant was asked for their papers.
 *
 * The using class declares `$dossiers` (App\Repository\TeacherDossierRepository) and
 * `$entityManager`.
 */
trait TeacherDossierControllerTrait
{
    private const string ADMINISTRATION_EXPRESSION = 'is_granted("ROLE_ADMIN") or is_granted("ROLE_STAFF") or is_granted("ROLE_STAFF-LEAD")';
    private const string TOKEN = 'teacher_dossier';

    private function dossierOr404(int $id, string $attribute = TeacherDossierVoter::MANAGE): TeacherDossier
    {
        $dossier = $this->dossiers->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isGranted($attribute, $dossier)) {
            throw $this->createNotFoundException();
        }

        return $dossier;
    }

    private function documentOr404(TeacherDossier $dossier, int $documentId): TeacherDossierDocument
    {
        foreach ($dossier->getDocuments() as $document) {
            if ($document->getId() === $documentId) {
                return $document;
            }
        }

        throw $this->createNotFoundException();
    }

    private function assertToken(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, PostValue::string($request, '_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function stamp(TeacherDossier $dossier): void
    {
        $dossier->setLastUpdatedBy($this->currentUser());
        $dossier->setLastUpdatedDate(new \DateTimeImmutable());
    }

    private function date(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
