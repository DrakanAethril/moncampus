<?php

declare(strict_types=1);

namespace App\Service\PlatformEmail;

use Twig\Environment;

/**
 * Renders a catalogue entry through the template its sender uses, so the screen cannot show a
 * text the mail does not carry. Nothing is sent and no Email object is built.
 *
 * The subject is the template's own `subject` block: every sender sets the same words on the
 * envelope, and reading them here keeps the preview free of each sender's code.
 */
final readonly class PlatformEmailPreviewer
{
    /** A preview is read, not used: its buttons lead nowhere, their addresses carrying placeholders. */
    private const string INERT_LINKS = '<style>a { pointer-events: none; cursor: default; }</style>';

    public function __construct(private Environment $twig)
    {
    }

    public function preview(PlatformEmail $email): PlatformEmailPreview
    {
        $context = $email->context + ['email' => new PreviewEmailImages($this->twig)];
        $template = $this->twig->load($email->template);

        // A block is rendered escaped for HTML; a subject is plain text.
        $subject = html_entity_decode(trim($template->renderBlock('subject', $context)), \ENT_QUOTES | \ENT_HTML5);

        return new PlatformEmailPreview(
            $subject,
            str_replace('</head>', self::INERT_LINKS.'</head>', $template->render($context)),
        );
    }
}
