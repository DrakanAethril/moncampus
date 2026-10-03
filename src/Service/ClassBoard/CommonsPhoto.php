<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

/**
 * One file of Wikimedia Commons, as the photograph of the day reads it - and the whole rule of what
 * may become one (fromImageInfo()).
 *
 * @phpstan-type ImageInfo array{
 *     mime?: mixed,
 *     width?: mixed,
 *     height?: mixed,
 *     thumburl?: mixed,
 *     descriptionurl?: mixed,
 *     extmetadata?: array<string, array{value?: mixed}>,
 *     commonmetadata?: list<array{name?: mixed, value?: mixed}>,
 * }
 */
final readonly class CommonsPhoto
{
    /** Narrower than 4:3 is a portrait or a square; wider than 2:1 is a panorama, a strip once cropped. */
    public const float MIN_RATIO = 4 / 3;
    public const float MAX_RATIO = 2.0;

    /** The width of a projector. Less would be stretched on it. */
    public const int MIN_WIDTH = 1920;

    /**
     * The licences a board may show: public domain, and Creative Commons BY or BY-SA - whose one
     * demand, the credit, the board prints under the photograph. Anything else (NC, ND, GFDL alone,
     * a licence of its own) is skipped, never interpreted.
     */
    /** Thumbnails come from the first since 2026, the originals from the second. */
    private const array MEDIA_HOSTS = ['thumb.wikimedia.org', 'upload.wikimedia.org'];

    private const string LICENSE_PATTERN = '/^(CC0(?: 1\.0)?|Public domain|PD[- ].*|CC BY(?:-SA)? \d\.\d(?: [a-z]{2,3})?)$/i';

    public function __construct(
        public string $title,
        public string $imageUrl,
        public string $sourceUrl,
        public string $author,
        public string $licenseName,
        public ?string $licenseUrl,
    ) {
    }

    /**
     * Null when the file must not be a board's background. A **photograph**, not a picture: a JPEG
     * that carries the camera which took it - which a painting's scan, a drawing or a render does not.
     *
     * @param ImageInfo $info `prop=imageinfo` with `iiprop=url|size|mime|extmetadata|commonmetadata`
     *                        and `iiurlwidth` set
     */
    public static function fromImageInfo(string $title, array $info): ?self
    {
        $width = \is_int($info['width'] ?? null) ? $info['width'] : 0;
        $height = \is_int($info['height'] ?? null) ? $info['height'] : 0;
        if ('image/jpeg' !== ($info['mime'] ?? null) || $width < self::MIN_WIDTH || $height <= 0) {
            return null;
        }

        $ratio = $width / $height;
        if ($ratio < self::MIN_RATIO || $ratio > self::MAX_RATIO) {
            return null;
        }

        $camera = '';
        foreach ($info['commonmetadata'] ?? [] as $entry) {
            if (\in_array($entry['name'] ?? null, ['Make', 'Model'], true) && \is_string($entry['value'] ?? null)) {
                $camera .= trim($entry['value']);
            }
        }
        if ('' === $camera) {
            return null;
        }

        $metadata = $info['extmetadata'] ?? [];
        $licenseName = self::text($metadata['LicenseShortName']['value'] ?? null);
        if (1 !== preg_match(self::LICENSE_PATTERN, $licenseName)) {
            return null;
        }

        $imageUrl = $info['thumburl'] ?? null;
        $sourceUrl = $info['descriptionurl'] ?? null;
        if (!\is_string($imageUrl) || !self::servedByWikimedia($imageUrl) || !\is_string($sourceUrl) || !str_starts_with($sourceUrl, 'https://')) {
            return null;
        }

        // A licence that asks for a credit is not shown without one.
        $author = self::text($metadata['Artist']['value'] ?? null);
        $publicDomain = str_starts_with(strtoupper($licenseName), 'CC0') || str_starts_with(strtoupper($licenseName), 'P');
        if ('' === $author && !$publicDomain) {
            return null;
        }

        $licenseUrl = $metadata['LicenseUrl']['value'] ?? null;
        $licenseUrl = \is_string($licenseUrl) && 1 === preg_match('#^https?://#', $licenseUrl) ? preg_replace('#^http://#', 'https://', $licenseUrl) : null;

        return new self($title, $imageUrl, $sourceUrl, '' === $author ? 'Auteur inconnu' : $author, $licenseName, $licenseUrl);
    }

    /**
     * The one place the download goes: Wikimedia's own media hosts, never an address the metadata
     * could point elsewhere.
     */
    private static function servedByWikimedia(string $url): bool
    {
        return 'https' === parse_url($url, \PHP_URL_SCHEME)
            && \in_array(parse_url($url, \PHP_URL_HOST), self::MEDIA_HOSTS, true);
    }

    /**
     * Commons hands its metadata over as HTML - a link around the author's name, a <bdi>, entities.
     */
    private static function text(mixed $html): string
    {
        if (!\is_string($html)) {
            return '';
        }

        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
