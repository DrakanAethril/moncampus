<?php

declare(strict_types=1);

namespace App\Controller\FileLibrary;

use App\Repository\UserRepository;
use App\Service\ByteSize;
use App\Service\PostValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin's half of the quota, and the whole of what an admin may do to somebody else's library
 * (design/validated/file-library.md, "The admin quota field").
 *
 * Its own controller rather than a tenth action on App\Controller\DirectoryUserController, which is
 * already one of the fat ones - the repository's rule is to extract rather than extend when touching
 * one. It also keeps the library's code together, which is what a reader looking for "where is the
 * quota set" will expect.
 *
 * **Setting a limit needs a number, not a file manager**: there is deliberately no route here that
 * shows an admin what a teacher's library contains. That is the narrow row of the access table, and
 * this is the whole of it.
 */
#[IsGranted('ROLE_ADMIN')]
class FileLibraryQuotaController extends AbstractController
{
    #[Route(path: '/directory/users/{id}/file-library-quota', name: 'app_directory_user_file_library_quota', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function save(Request $request, int $id, UserRepository $users, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('file_library_quota', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user = $users->find($id) ?? throw $this->createNotFoundException();
        $typed = PostValue::trimmed($request, 'quota');

        // Empty clears the override back to NULL - which is not zero, it is "whatever the platform
        // currently says". That is the whole reason the column is nullable.
        if ('' === $typed) {
            $user->setFileLibraryQuotaBytes(null);
            $entityManager->flush();
            $this->addFlash('success', 'fileLibraryQuotaSavedFlashMessage');

            return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
        }

        $bytes = ByteSize::parse($typed);

        if (null === $bytes) {
            $this->addFlash('error', 'fileLibraryQuotaInvalidMessage');

            return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
        }

        // Lowering it below what the teacher already stores deletes nothing: the bar reads 118 %, in
        // red, and uploads are refused until they free space. Any other behaviour would mean deleting
        // somebody's files as a side effect of an administrative edit.
        $user->setFileLibraryQuotaBytes($bytes);
        $entityManager->flush();
        $this->addFlash('success', 'fileLibraryQuotaSavedFlashMessage');

        return $this->redirectToRoute('app_directory_users_edit', ['id' => $id]);
    }
}
