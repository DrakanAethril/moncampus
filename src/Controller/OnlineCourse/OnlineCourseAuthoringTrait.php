<?php

declare(strict_types=1);

namespace App\Controller\OnlineCourse;

use App\Entity\OnlineCourse;
use App\Entity\User;
use App\Repository\OnlineCourseRepository;
use App\Security\Voter\OnlineCourseVoter;
use Symfony\Component\HttpFoundation\Request;

/**
 * The helpers the author-side controllers of « Cours en ligne » share. A course somebody else owns
 * answers 404, never 403: it does not exist for them (design/validated/cours-en-ligne.md, §3).
 */
trait OnlineCourseAuthoringTrait
{
    private function findCourse(int $id, OnlineCourseRepository $courses, string $attribute = OnlineCourseVoter::EDIT): OnlineCourse
    {
        $course = $courses->find($id);
        if (null === $course || !$this->isGranted($attribute, $course)) {
            throw $this->createNotFoundException();
        }

        return $course;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
