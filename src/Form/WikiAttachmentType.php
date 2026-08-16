<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\UploadPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The wiki page's "Pièces jointes" upload, which was a hand-written `<input type="file" multiple>`
 * posting straight to App\Controller\Wiki\WikiContentController::addAttachments().
 *
 * It became a form type for one reason: App\Form\FilePickerType is a form widget, and the fifteenth
 * of the fifteen fields could not be the one field left carrying bytes
 * (design/validated/file-library.md, "The fifteen fields"). The CSRF token that was written by hand
 * into the markup is the form's own now.
 *
 * **The platform rule, unnarrowed**, exactly as before: the wiki is the general-purpose workspace,
 * so it restricts nothing (see App\Service\UploadPolicy's docblock).
 */
class WikiAttachmentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('files', FilePickerType::class, [
            'label' => 'wikiAttachmentsFieldLabel',
            'mapped' => false,
            'required' => false,
            'multiple' => true,
            'policy' => UploadPolicy::platform(),
            // Course material, so the library tab belongs here - it arrives with the library itself
            // (design/validated/file-library.md, lot 4).
            'library' => false,
        ]);
    }
}
