<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ClamAvUnavailableException;
use App\Service\InfectedUploadException;
use App\Service\PostValue;
use App\Service\StagedUploadStore;
use App\Service\UploadPolicy;
use App\Validator\AllowedUpload;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one endpoint every upload field of this platform sends its bytes to
 * (design/validated/file-library.md, "Staged uploads"). One request, one file, no form.
 *
 * It is what makes a progress bar possible everywhere rather than on the one screen that already
 * uploads outside a form - see App\Service\StagedUploadStore for why the shape had to change at all.
 *
 * **It applies the platform rule, and only the platform rule.** A field's own narrowing
 * (UploadPolicy::documents(), ::images(), ::pdf()) is checked when the form is submitted, by
 * App\Validator\AllowedUploadValidator, against the same token this hands back - because a policy
 * named by the client is not a policy. What the client may say here is a *size* hint, and only to
 * be told sooner: it can lower the ceiling, never raise it above UploadPolicy::PLATFORM_MAX_SIZE,
 * and the field re-checks its real limit at submit time anyway.
 */
class StagedUploadController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'staged-upload';

    #[Route(path: '/uploads/stage', name: 'app_upload_stage', methods: ['POST'])]
    public function stage(
        Request $request,
        StagedUploadStore $store,
        ValidatorInterface $validator,
        TranslatorInterface $translator,
    ): JsonResponse {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token');

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, \is_string($token) ? $token : null)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->refuse($translator, 'stagedUploadMissingFileError');
        }

        /** @var User $user */
        $user = $this->getUser();

        // Everything the session is needed for has now happened - the CSRF token above and the
        // token this line reloads - and everything still to come is slow: a ClamAV scan (30s
        // timeout) then up to 200 Mo written to S3. Sessions are native files, so PHP holds an
        // EXCLUSIVE lock on sess_<id> for the whole of a request that leaves one open, and every
        // other request presenting the same cookie waits on it - measured: a session locked for 8s
        // makes /login itself answer in 7.6s instead of 0.13s.
        //
        // That is what turned a slow upload into a browser that appears frozen. The picker fires
        // one of these per file, in parallel (assets/controllers/file_picker_controller.js), so N
        // attachments serialised into N scans-plus-transfers before the tab could do anything at
        // all - including navigating away, and including reaching the login form after being
        // logged out. Closing the browser looked like the cure only because cookie_lifetime is 0:
        // it dropped the cookie, so the next request took a fresh session and no lock.
        //
        // Nothing below reads or writes the session; should something ever need to, Symfony
        // restarts it on demand (and would take the lock again, which is the point of doing this
        // as late as possible and no later).
        $this->releaseSessionLock($request);

        $policy = UploadPolicy::platform()->withMaxSize((string) $this->ceilingFor($request));
        $violations = $validator->validate($file, new AllowedUpload($policy));

        if ($violations->count() > 0) {
            // The violation message is already the answer - "ce type de fichier n'est pas accepté
            // ici", "l'analyse antivirale y a détecté une menace" - and it is what the picker shows
            // on the row that failed. Translated here rather than in the browser: the keys and their
            // placeholders belong to the server.
            $violation = $violations->get(0);

            return new JsonResponse([
                'error' => (string) $violation->getCode(),
                'message' => (string) $violation->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $staged = $store->stage($file, (int) $user->getId());
        } catch (InfectedUploadException|ClamAvUnavailableException) {
            // The constraint above scans first, so reaching this means the verdict changed between
            // two scans of the same request - vanishingly rare, and never a reason to store the file.
            return $this->refuse($translator, 'uploadPolicyScannerUnavailableMessage');
        }

        return $this->json([
            'token' => $staged->token,
            'name' => $staged->originalName,
            'size' => $staged->size,
            'mimeType' => $staged->mimeType,
        ]);
    }

    /**
     * The picker's "✕" on a row the teacher changed their mind about, before the form is submitted.
     *
     * Courtesy rather than housekeeping: App\Service\StagedUploadStore schedules every staged object
     * for removal as it writes it, so an object nobody discards goes within the day anyway. What
     * this buys is that it goes now, which is what a teacher who has just removed a 180 Mo video
     * from the picker would expect of their quota.
     */
    #[Route(path: '/uploads/stage/discard', name: 'app_upload_discard', methods: ['POST'])]
    public function discard(Request $request, StagedUploadStore $store, TranslatorInterface $translator): JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_token');

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, \is_string($token) ? $token : null)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();
        $staged = $store->resolve(PostValue::string($request, 'token'), (int) $user->getId());

        if (null === $staged) {
            // Forged, or somebody else's. Answered as a refusal rather than as a 404: from here the
            // two are the same thing, and the picker has nothing different to do about either.
            return $this->refuse($translator, 'filePickerInvalidTokenMessage');
        }

        $store->discard($staged);

        return $this->json(['discarded' => true]);
    }

    /**
     * The size this request is told about, in bytes. The hint only ever lowers the platform
     * ceiling: a field that accepts 20 M gets told at 20 M instead of at 200 M, and a client
     * claiming more than the platform allows is simply ignored.
     */
    private function ceilingFor(Request $request): int
    {
        $platform = UploadPolicy::platform()->withMaxSize(UploadPolicy::PLATFORM_MAX_SIZE)->maxSizeInBytes();
        $hint = PostValue::int($request, 'maxBytes');

        return $hint > 0 && $hint < $platform ? $hint : $platform;
    }

    /**
     * Writes the session back and closes it, so the exclusive lock PHP's native file handler holds
     * on it is released before the slow part of the request rather than after.
     *
     * Guarded on Session rather than SessionInterface only because isStarted() lives on the former;
     * saving a session that was never started would restart it, which is the opposite of the point.
     */
    private function releaseSessionLock(Request $request): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof Session && $session->isStarted()) {
            $session->save();
        }
    }

    private function refuse(TranslatorInterface $translator, string $key): JsonResponse
    {
        return new JsonResponse(['error' => $key, 'message' => $translator->trans($key)], Response::HTTP_BAD_REQUEST);
    }
}
