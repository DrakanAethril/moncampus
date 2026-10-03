<?php

declare(strict_types=1);

namespace App\Service\ClassBoard;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wikimedia Commons, as the pool the photograph of the day is drawn from.
 *
 * The pool is Commons' own selection: the « Featured pictures » of a few nature categories - each
 * file voted in by the community, so no quality filter has to be written here. CommonsPhoto then
 * keeps the photographs among them that a board can show. Keyless, open, read by
 * `app:class-board:photo` only, never while a screen waits.
 *
 * @phpstan-import-type ImageInfo from CommonsPhoto
 *
 * @phpstan-type CommonsAnswer array{
 *     query?: array{
 *         categorymembers?: list<array{title?: mixed}>,
 *         pages?: list<array{title?: mixed, imageinfo?: list<ImageInfo>}>,
 *     },
 *     continue?: array{cmcontinue?: mixed},
 *     error?: mixed,
 * }
 */
class CommonsNaturePhotos
{
    private const string API = 'https://commons.wikimedia.org/w/api.php';

    /**
     * Landscapes rather than subjects: flowers and plants, featured as close-ups, make poor
     * backgrounds.
     */
    public const array CATEGORIES = [
        'Category:Featured pictures of landscapes',
        'Category:Featured pictures of mountains',
        'Category:Featured pictures of forests',
        'Category:Featured pictures of lakes',
        'Category:Featured pictures of waterfalls',
        'Category:Featured pictures of coasts',
        'Category:Featured pictures of trees',
    ];

    /** A projector's width - Commons scales the thumbnail, the original can weigh 30 Mo. */
    public const int WIDTH = 1920;

    private const int MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly HttpClientInterface $commonsHttpClient,
    ) {
    }

    /**
     * Every file title of the pool.
     *
     * @return list<string>
     *
     * @throws CommonsUnavailableException
     */
    public function titles(): array
    {
        $titles = [];
        foreach (self::CATEGORIES as $category) {
            $continue = null;
            do {
                $answer = $this->query([
                    'list' => 'categorymembers',
                    'cmtitle' => $category,
                    'cmtype' => 'file',
                    'cmlimit' => '500',
                ] + (null === $continue ? [] : ['cmcontinue' => $continue]));

                foreach ($answer['query']['categorymembers'] ?? [] as $member) {
                    if (\is_string($member['title'] ?? null)) {
                        $titles[$member['title']] = true;
                    }
                }

                $next = $answer['continue']['cmcontinue'] ?? null;
                $continue = \is_string($next) ? $next : null;
            } while (null !== $continue);
        }

        return array_keys($titles);
    }

    /**
     * Those of these files a board may show, in the order given.
     *
     * @param list<string> $titles at most 50, Commons' own limit
     *
     * @return list<CommonsPhoto>
     *
     * @throws CommonsUnavailableException
     */
    public function photographs(array $titles): array
    {
        $answer = $this->query([
            'titles' => implode('|', \array_slice($titles, 0, 50)),
            'prop' => 'imageinfo',
            'iiprop' => 'url|size|mime|extmetadata|commonmetadata',
            'iiurlwidth' => (string) self::WIDTH,
            'iiextmetadatafilter' => 'LicenseShortName|LicenseUrl|Artist',
        ]);

        $byTitle = [];
        foreach ($answer['query']['pages'] ?? [] as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (\is_string($page['title'] ?? null) && null !== $info) {
                $photo = CommonsPhoto::fromImageInfo($page['title'], $info);
                if (null !== $photo) {
                    $byTitle[$page['title']] = $photo;
                }
            }
        }

        $photos = [];
        foreach ($titles as $title) {
            if (isset($byTitle[$title])) {
                $photos[] = $byTitle[$title];
            }
        }

        return $photos;
    }

    /**
     * The JPEG's bytes, at WIDTH.
     *
     * @throws CommonsUnavailableException
     */
    public function download(CommonsPhoto $photo): string
    {
        try {
            $response = $this->commonsHttpClient->request('GET', $photo->imageUrl, ['timeout' => 30]);
            $status = $response->getStatusCode();
            $type = $response->getHeaders(false)['content-type'][0] ?? '';
            $body = 200 === $status ? $response->getContent() : '';
        } catch (ExceptionInterface $exception) {
            throw new CommonsUnavailableException('Commons did not hand the photograph over: '.$exception->getMessage(), 0, $exception);
        }

        if (200 !== $status || !str_starts_with($type, 'image/jpeg') || !str_starts_with($body, "\xFF\xD8") || \strlen($body) > self::MAX_BYTES) {
            throw new CommonsUnavailableException(sprintf('Unexpected photograph from Commons (HTTP %d, %s, %d bytes).', $status, $type, \strlen($body)));
        }

        return $body;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return CommonsAnswer
     *
     * @throws CommonsUnavailableException
     */
    private function query(array $parameters): array
    {
        try {
            /** @var CommonsAnswer $answer */
            $answer = $this->commonsHttpClient->request('GET', self::API, [
                'query' => ['action' => 'query', 'format' => 'json', 'formatversion' => '2'] + $parameters,
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new CommonsUnavailableException('Commons did not answer: '.$exception->getMessage(), 0, $exception);
        }

        if (isset($answer['error'])) {
            throw new CommonsUnavailableException('Commons refused the query: '.json_encode($answer['error']));
        }

        return $answer;
    }
}
