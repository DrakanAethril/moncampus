<?php

declare(strict_types=1);

namespace App\Service\Wall;

use App\Entity\User;
use App\Entity\Wall;
use App\Entity\WallCard;
use App\Entity\WallList;
use App\Service\FileUploadService;
use App\Service\StagedUpload;
use App\Service\StagedUploadStore;
use App\Service\UploadIntake;
use App\Service\UploadPolicy;
use App\Validator\AllowedUpload;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The objects a collaborative wall keeps in the uploads bucket, all under `walls/`: a card's
 * picture, a card's file, a wall's background.
 *
 * Bytes never arrive here: the browser stages each file on `/uploads/stage` like every upload of
 * the platform, and what reaches this class is the signed token that answer handed back. The
 * narrowing is checked against that token - a picture is `UploadPolicy::images()`, a file is the
 * platform rule the wiki also applies - then the object is claimed into the wall's prefix.
 *
 * One key belongs to one row. A copied card gets copies of its objects (duplicate()), so forgetting
 * a card can schedule its objects for deletion without asking who else points at them.
 */
final class WallFiles
{
    private const string PREFIX = 'walls/';

    public function __construct(
        private readonly StagedUploadStore $stagedUploads,
        private readonly UploadIntake $intake,
        private readonly FileUploadService $files,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function claimImage(string $token, User $user): string
    {
        return $this->claim($this->resolve($token, $user, UploadPolicy::images()));
    }

    /** @return array{key: string, name: string, size: int} */
    public function claimFile(string $token, User $user): array
    {
        $staged = $this->resolve($token, $user, UploadPolicy::platform());

        return ['key' => $this->claim($staged), 'name' => mb_substr($staged->originalName, 0, 255), 'size' => $staged->size];
    }

    /** A second, independent object holding the same bytes - what a copied card owns. */
    public function duplicate(string $key): string
    {
        $copy = self::PREFIX.bin2hex(random_bytes(16)).$this->suffixOf($key);
        \assert('' !== $key);
        $this->files->copy($key, $copy);

        return $copy;
    }

    public function forget(?string $key): void
    {
        if (null !== $key && '' !== $key) {
            $this->files->delete($key);
        }
    }

    public function forgetCard(WallCard $card): void
    {
        $this->forget($card->getImageKey());
        $this->forget($card->getFileKey());
    }

    public function forgetList(WallList $list): void
    {
        foreach ($list->getCards() as $card) {
            $this->forgetCard($card);
        }
    }

    public function forgetWall(Wall $wall): void
    {
        $this->forget($wall->getBackgroundImageKey());
        foreach ($wall->getLists() as $list) {
            $this->forgetList($list);
        }
    }

    private function resolve(string $token, User $user, UploadPolicy $policy): StagedUpload
    {
        $staged = $this->stagedUploads->resolve($token, (int) $user->getId());
        if (null === $staged) {
            throw new WallRefusal('filePickerInvalidTokenMessage');
        }

        $violations = $this->validator->validate($staged, new AllowedUpload($policy));
        if ($violations->count() > 0) {
            throw new WallRefusal((string) $violations->get(0)->getMessage(), translated: true);
        }

        return $staged;
    }

    /** @return non-empty-string */
    private function claim(StagedUpload $staged): string
    {
        return $this->intake->store($staged, self::PREFIX, bin2hex(random_bytes(16)).'.'.UploadIntake::extension($staged));
    }

    private function suffixOf(string $key): string
    {
        $extension = pathinfo($key, \PATHINFO_EXTENSION);

        return '' === $extension ? '' : '.'.$extension;
    }
}
