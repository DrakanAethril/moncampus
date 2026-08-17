<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Repository\FileLibraryNodeRepository;
use App\Service\StagedUpload;
use App\Service\StagedUploadStore;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * Turns what App\Form\FilePickerType actually submits - a JSON list of signed tokens - into the
 * App\Service\StagedUpload objects a controller and a validator can work with.
 *
 * The field carries tokens rather than bytes because the bytes went up on their own XHR long before
 * the form was submitted (design/validated/file-library.md, "Staged uploads"), which is what makes a
 * progress bar possible and what leaves the form POST carrying no file at all.
 *
 * **The token is re-checked here, not trusted.** App\Service\StagedUploadStore verifies the HMAC and
 * compares the owner against the user submitting the form, so a token copied out of somebody else's
 * page resolves to nothing - and a token that resolves to nothing is a transformation failure, which
 * the form reports as an invalid field rather than as a 500.
 *
 * The generic parameter is `mixed` rather than the union this actually produces, and deliberately:
 * `transform()` is handed whatever the form's model data happens to be, which for an unmapped field
 * an entity may still be pre-filling is not something a docblock can promise. Narrowing it would
 * make the guard below read as dead code - the trap this repository has already recorded once, where
 * an annotation that lied surfaced as "this branch can never run" rather than as a type error.
 *
 * @implements DataTransformerInterface<mixed, string>
 */
class StagedUploadTransformer implements DataTransformerInterface
{
    /**
     * What a library entry looks like in the field's value: `lib:48` beside the signed tokens.
     *
     * A prefix rather than a second hidden field, because the two kinds are one *ordered list* to the
     * reader - the chips are in the order they were added - and two fields would have to be zipped
     * back together on every redisplay.
     */
    public const string LIBRARY_PREFIX = 'lib:';

    public function __construct(
        private readonly StagedUploadStore $store,
        private readonly Security $security,
        private readonly FileLibraryNodeRepository $libraryNodes,
        private readonly bool $multiple,
    ) {
    }

    /**
     * Model to view. It only ever runs on a form that was **redisplayed** after a validation error -
     * an upload field starts empty, since nothing on this platform pre-fills one with a file - and
     * what it hands back is exactly what came in, so the picker can paint its rows again from the
     * tokens alone.
     */
    public function transform(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        $files = \is_array($value) ? $value : [$value];
        $tokens = [];

        foreach ($files as $file) {
            if ($file instanceof StagedUpload) {
                $tokens[] = $file->token;
            }

            if ($file instanceof FileLibraryNode) {
                $tokens[] = self::LIBRARY_PREFIX.$file->getId();
            }
        }

        return [] === $tokens ? '' : json_encode($tokens, \JSON_THROW_ON_ERROR);
    }

    /** View to model. */
    public function reverseTransform(mixed $value): StagedUpload|FileLibraryNode|array|null
    {
        $empty = $this->multiple ? [] : null;

        if (!\is_string($value) || '' === trim($value)) {
            return $empty;
        }

        $tokens = json_decode($value, true);

        if (!\is_array($tokens)) {
            throw new TransformationFailedException('The file picker submitted something that is not a list of tokens.');
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new TransformationFailedException('A staged upload cannot be claimed by an anonymous request.');
        }

        $files = [];

        foreach ($tokens as $token) {
            if (!\is_string($token)) {
                throw new TransformationFailedException('A file picker token must be a string.');
            }

            // A file picked from the library rather than uploaded. **The id is re-checked here**:
            // the picker only ever offers this account's own files, but a picker is a convenience and
            // never a control - the check is what makes that true.
            if (str_starts_with($token, self::LIBRARY_PREFIX)) {
                $files[] = $this->libraryNode((int) substr($token, \strlen(self::LIBRARY_PREFIX)), $user);

                continue;
            }

            $staged = $this->store->resolve($token, (int) $user->getId());

            if (null === $staged) {
                // Forged, tampered with, or somebody else's. Indistinguishable from here on
                // purpose: telling the three apart would be telling an attacker which one they got.
                throw new TransformationFailedException('This staged upload token does not resolve.');
            }

            $files[] = $staged;
        }

        if ([] === $files) {
            return $empty;
        }

        return $this->multiple ? $files : $files[0];
    }

    private function libraryNode(int $id, User $user): FileLibraryNode
    {
        $node = $this->libraryNodes->find($id);

        if (null === $node || !$node->isFile() || $node->isDeleted() || $node->getOwner()->getId() !== $user->getId()) {
            throw new TransformationFailedException('This library file cannot be linked by this account.');
        }

        return $node;
    }
}
