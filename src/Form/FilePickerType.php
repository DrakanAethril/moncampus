<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\Feature;
use App\Form\DataTransformer\StagedUploadTransformer;
use App\Repository\FileLibraryNodeRepository;
use App\Security\FeatureAccess;
use App\Security\Voter\FileLibraryVoter;
use App\Service\StagedUploadStore;
use App\Service\UploadPolicy;
use App\Validator\AllowedUpload;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Constraints\All;

/**
 * The one upload field of this application (design/validated/file-library.md, "The harmonised upload
 * component"). One Symfony type, one Twig block, one Stimulus controller, one CSS block, for the
 * fifteen fields that used to be fifteen `FileType`s.
 *
 * What it changes, from the outside, is that **the field no longer carries bytes**. The picker sends
 * each file on its own XHR to `/uploads/stage` as soon as it is chosen, and the form submits a list
 * of signed tokens. Three things follow, and only the first is the one that was asked for:
 *
 * - a **progress bar**, which no form-shaped upload can have: nothing in the browser reports how
 *   much of a request *body* has gone out, and `xhr.upload.onprogress` needs a request carrying one
 *   file;
 * - the **type and the virus are checked while the teacher is still filling the form in**, instead
 *   of after they submit it;
 * - no form on the platform carries a file any more, which removes the FrankenPHP failure mode
 *   already recorded here - a POST over `post_max_size` answered with a 200 HTML page, no 413, and
 *   nothing anyone can act on.
 *
 * ## Using it
 *
 *     ->add('attachments', FilePickerType::class, [
 *         'label' => 'messageAttachmentsFieldLabel',
 *         'policy' => UploadPolicy::documents(),
 *         'multiple' => true,
 *         'required' => false,
 *     ])
 *
 * **The field declares its policy, not its constraints.** The `AllowedUpload` constraint is built
 * from `policy` here - wrapped in `All` when `multiple`, which is the detail every call site used to
 * have to remember - so a narrowing is stated once, in the option, and cannot drift from the one the
 * component advertises to the browser in its `accept` attribute and its help line.
 *
 * ## What the controller then receives
 *
 * `$form->get('x')->getData()` answers an App\Service\StagedUpload (or a list of them), not an
 * UploadedFile. Controllers go through App\Service\UploadIntake, which takes either shape - that is
 * what lets the fifteen fields migrate one at a time, a field not yet migrated working exactly as it
 * does now.
 *
 * ## The `library` option
 *
 * Whether the "Bibliothèque de fichiers" tab is offered, and it is set on the fields that carry
 * teacher-authored course material - supports, lesson-log documents, wiki and documentation
 * attachments, message attachments, signup lists, séquence resources, quiz question images. It stays
 * **false** on the student-side fields, on the import assistants (a CSV imported once is not course
 * material) and on the avatar.
 *
 * The tab is also hidden, whatever the option says, for a user who has no library: the check is the
 * Voter's `FILE_LIBRARY_LINK`, so who may link and who has a library are one answer - and for anyone
 * the `file_library` feature is switched off for.
 *
 * **That second check is the whole reason the feature is not just a 404 on `/files`.** The library is
 * a *tab inside this component*, which is on some fifteen forms across a dozen features; 404-ing its
 * routes and leaving the tab would leave every one of those forms showing a tab that fails to load
 * (design/validated/feature-access.md §8.1). Attaching a file from the disk is untouched: what goes
 * away is one way of choosing it, never the field.
 *
 * Files already attached from the library stay valid: the row carries its own storage key, copied
 * from the node.
 *
 * A file picked there is a **reference**: the row takes the node's own storage key and a foreign key
 * back to it, so the file weighs once and correcting it in the library corrects it everywhere - and
 * deleting it from the library removes it from here. That is the rule the chip's gold pill announces.
 */
class FilePickerType extends AbstractType
{
    public function __construct(
        private readonly StagedUploadStore $store,
        private readonly Security $security,
        private readonly FileLibraryNodeRepository $libraryNodes,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly FeatureAccess $featureAccess,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $multiple = true === $options['multiple'];

        $builder->addViewTransformer(new StagedUploadTransformer($this->store, $this->security, $this->libraryNodes, $multiple));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $policy = $this->policyOf($options);

        $view->vars['multiple'] = true === $options['multiple'];
        // The tab is hidden - whatever the field says - for a user who has no library at all. A tab
        // that opens on "you have no library" is a worse answer than an absent one.
        $view->vars['library'] = true === $options['library']
            && $this->featureAccess->isEnabled(Feature::FileLibrary)
            && $this->authorization->isGranted(FileLibraryVoter::LINK);
        $view->vars['max_bytes'] = $policy->maxSizeInBytes();
        $view->vars['extensions'] = $policy->extensions();
        // The `accept` attribute of the native picker: a courtesy that decides what the file dialog
        // offers, never what is accepted - App\Validator\AllowedUpload is what decides, twice (at
        // staging and at submit).
        $view->vars['accept'] = implode(',', array_map(static fn (string $extension): string => '.'.$extension, $policy->extensions()));
        $view->vars['staged_prefix'] = StagedUploadStore::PREFIX;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'policy' => null,
                'max_size' => null,
                'multiple' => false,
                'library' => false,
                'compound' => false,
                'required' => false,
                // A token that does not resolve is a forged, tampered-with or foreign one - see
                // App\Form\DataTransformer\StagedUploadTransformer. The user reads "start again",
                // because there is nothing they can usefully do about it.
                'invalid_message' => 'filePickerInvalidTokenMessage',
                'error_bubbling' => false,
            ])
            ->setAllowedTypes('policy', ['null', UploadPolicy::class])
            ->setAllowedTypes('max_size', ['null', 'string'])
            ->setAllowedTypes('multiple', 'bool')
            ->setAllowedTypes('library', 'bool')
            // Built from the policy rather than written at the call site: stating a narrowing twice
            // is how the two come to disagree, and the `All` wrapper for a multiple field is exactly
            // the half that used to be forgotten.
            ->setNormalizer('constraints', function (Options $options, mixed $constraints): array {
                $declared = \is_array($constraints) ? $constraints : [];
                $upload = new AllowedUpload($this->policyOf($options));

                return [...$declared, true === $options['multiple'] ? new All([$upload]) : $upload];
            })
        ;
    }

    public function getParent(): string
    {
        return HiddenType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'file_picker';
    }

    /** @param array<string, mixed>|Options $options */
    private function policyOf(array|Options $options): UploadPolicy
    {
        $policy = $options['policy'] instanceof UploadPolicy ? $options['policy'] : UploadPolicy::platform();
        $maxSize = $options['max_size'];

        return \is_string($maxSize) ? $policy->withMaxSize($maxSize) : $policy;
    }
}
