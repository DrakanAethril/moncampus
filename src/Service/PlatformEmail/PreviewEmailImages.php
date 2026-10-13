<?php

declare(strict_types=1);

namespace App\Service\PlatformEmail;

use Twig\Environment;

/**
 * Stands in for the `email` variable Symfony's mailer gives a template. There, `email.image()`
 * attaches the file and answers a `cid:` address only a mail client resolves; a browser needs the
 * picture itself, so this one answers it inline.
 */
final readonly class PreviewEmailImages
{
    public function __construct(private Environment $twig)
    {
    }

    public function image(string $name): string
    {
        $path = $this->twig->getLoader()->getSourceContext($name)->getPath();

        return \sprintf('data:%s;base64,%s', mime_content_type($path) ?: 'application/octet-stream', base64_encode((string) file_get_contents($path)));
    }
}
