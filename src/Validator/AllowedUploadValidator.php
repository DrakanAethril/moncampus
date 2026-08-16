<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\AntivirusScanner;
use App\Service\ClamAvUnavailableException;
use App\Service\InfectedUploadException;
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
    /**
     * The scanner is optional so this validator stays usable from a plain unit test with no
     * container - which is how App\Tests\Validator\AllowedUploadValidatorTest drives it. In the
     * application it is always injected, and the write paths assert independently anyway.
     */
    public function __construct(private readonly ?AntivirusScanner $antivirus = null)
    {
    }

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

        if (null !== $reason) {
            $this->context->buildViolation($reason)
                ->setParameter('{{ name }}', $this->formatValue($name))
                ->setCode($reason)
                ->addViolation();

            return;
        }

        $this->assertNotHostile($value->getPathname(), $name);
    }

    /**
     * The second question, and a different one: the policy above said what kind of file this is,
     * this says whether it is hostile (design/validated/upload-policy.md, lot 3).
     *
     * Reported as a violation rather than left to throw, so a user who picked the wrong file gets a
     * message on the form instead of an error page. The guarantee that nothing unscanned reaches
     * the bucket is not here though - it is in the three services that write to it, which assert
     * independently. This layer is the courtesy; that one is the rule.
     */
    private function assertNotHostile(string $path, string $name): void
    {
        if (null === $this->antivirus) {
            return;
        }

        try {
            $this->antivirus->assertClean($path, $name);
        } catch (InfectedUploadException $infected) {
            $this->context->buildViolation('uploadPolicyInfectedFileMessage')
                ->setParameter('{{ signature }}', $this->formatValue($infected->signature))
                ->setCode('uploadPolicyInfectedFileMessage')
                ->addViolation();
        } catch (ClamAvUnavailableException) {
            // Fail closed: refusing an upload nobody could check is the only honest answer, and
            // saying so is better than a silent acceptance the platform would later believe in.
            $this->context->buildViolation('uploadPolicyScannerUnavailableMessage')
                ->setCode('uploadPolicyScannerUnavailableMessage')
                ->addViolation();
        }
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
