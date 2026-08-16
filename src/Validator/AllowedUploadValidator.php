<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\HttpFoundation\File\File as HttpFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Applies an App\Service\UploadPolicy to one submitted file.
 *
 * Two things it does that a plain Assert\File cannot:
 *
 * - it reads the **name**, which Assert\File never looks at - so a file whose bytes sniff as
 *   text/plain can no longer be stored as `notes.bat`;
 * - it pairs the sniffed type with the extension that claims it, instead of accepting any type
 *   from a flat list - so a shell script named `photo.png` is refused even though `text/x-shellscript`
 *   would be legitimate for `.sh` inside an archive.
 *
 * The type is read from the file's own content (`getMimeType()`), never from `getClientMimeType()`,
 * which only repeats what the sender chose to write.
 */
class AllowedUploadValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AllowedUpload) {
            throw new UnexpectedTypeException($constraint, AllowedUpload::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof HttpFile) {
            throw new UnexpectedTypeException($value, HttpFile::class);
        }

        // Size, upload errors and "is this even a file" stay Symfony's job - see the constraint's
        // docblock for why this delegates rather than re-implements.
        $this->context->getValidator()->inContext($this->context)->validate(
            $value,
            new FileConstraint(maxSize: $constraint->policy->maxSize(), groups: $constraint->groups),
        );

        // A truncated upload has nothing worth sniffing: reporting "unsupported type" on top of the
        // size violation Symfony just raised would only send the user looking for a converter.
        if ($value instanceof UploadedFile && !$value->isValid()) {
            return;
        }

        $name = $value instanceof UploadedFile ? $value->getClientOriginalName() : $value->getFilename();
        $reason = $constraint->policy->refusalReason($name, $this->sniff($value));

        if (null === $reason) {
            return;
        }

        $this->context->buildViolation($reason)
            ->setParameter('{{ name }}', $this->formatValue($name))
            ->setCode($reason)
            ->addViolation();
    }

    /**
     * fileinfo can fail on an unreadable temp file - and a policy hands that case to the extension
     * rules alone rather than refusing outright, so null is a real answer here, not a swallowed
     * error.
     */
    private function sniff(HttpFile $file): ?string
    {
        try {
            return $file->getMimeType();
        } catch (\Throwable) {
            return null;
        }
    }
}
