<?php

declare(strict_types=1);

namespace App\Validator;

use App\Service\UploadPolicy;
use Symfony\Component\Validator\Constraint;

/**
 * The one constraint every upload field of this application carries, holding the
 * App\Service\UploadPolicy that field narrows the platform rule to.
 *
 *     new AllowedUpload(UploadPolicy::documents())
 *     new AllowedUpload(UploadPolicy::pdf())
 *     new AllowedUpload()                            // the platform rule, unnarrowed - the wiki
 *
 * It replaces the twelve-MIME list that had been copy-pasted into five form types and an API
 * controller - the same shape as the eight DataTables lines copied twelve times, which is how
 * `search[value]` came to be untyped in all of them.
 *
 * **Size is validated here too**, from the policy's own maxSize, by delegating to Symfony's own
 * File constraint rather than re-implementing it: that keeps its handling of a truncated upload
 * (UPLOAD_ERR_INI_SIZE, which arrives as an invalid file rather than an oversized one) and its
 * message placeholders, while leaving one constraint at the call site instead of two that can
 * drift apart.
 *
 * For a `multiple => true` field the submitted value is an array of uploads, so this still goes
 * inside an `All([...])` - a constraint aimed at the array itself answers "this value should be of
 * type string" on every save.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class AllowedUpload extends Constraint
{
    public UploadPolicy $policy;

    /** @param string[]|null $groups */
    public function __construct(?UploadPolicy $policy = null, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);

        // No argument means the platform rule unnarrowed, which is exactly the wiki's case: it is
        // the general-purpose workspace, so it restricts nothing.
        $this->policy = $policy ?? UploadPolicy::platform();
    }
}
