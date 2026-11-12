<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\WordCloud;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where a public cloud is answered (/cloud/{code}), written the three ways the screens need it: the
 * whole address, the short one read aloud under the QR code (« formatool.example/cloud »), and the
 * QR code itself. The pilot screen, the projected QR screen and the Claude connector read it here.
 */
final readonly class WordCloudPublicAddress
{
    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    /** The absolute address, or null when the cloud is not public. */
    public function url(WordCloud $cloud): ?string
    {
        $code = $cloud->getPublicCode();
        if (!$cloud->isPublic() || null === $code) {
            return null;
        }

        return $this->urls->generate('app_word_cloud_public', ['code' => $code], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** « host/cloud », what is typed before the code when the QR code cannot be scanned. */
    public function joinLabel(): string
    {
        $join = $this->urls->generate('app_word_cloud_public_join', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return (string) preg_replace('#^https?://#', '', $join);
    }

    /** The QR code as inline SVG, without the XML prolog: it is not a document of its own. */
    public function qrSvg(WordCloud $cloud, int $size = 320): ?string
    {
        $url = $this->url($cloud);
        if (null === $url) {
            return null;
        }

        $svg = (new Builder(writer: new SvgWriter(), data: $url, size: $size, margin: 0))->build()->getString();

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
    }
}
