<?php

declare(strict_types=1);

namespace App\Service\Rncp;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds and downloads the day's RNCP export from data.gouv.fr.
 *
 * **No URL is written down here.** The dataset's resources change name every day
 * (`export-fiches-rncp-v4-1-2026-09-29.zip`), so the dataset is asked through data.gouv.fr's own API
 * for its resources and the newest v4.1 RNCP export is picked by its title. The file is kept in
 * `var/rncp/` for the day: a second import the same day reads the copy, and older copies are
 * removed when a newer one lands.
 *
 * Open data under Licence Ouverte 2.0 - the référentiel created from it cites the export's date.
 *
 * Called only by `app:rncp:fetch`, never while a web request waits: 74 Mo takes time.
 */
class RncpExportLocator
{
    public const string DATASET = 'repertoire-national-des-certifications-professionnelles-et-repertoire-specifique';

    private const string EXPORT_PATTERN = '/^export-fiches-rncp-v4-1-(\d{4}-\d{2}-\d{2})\.zip$/';

    public function __construct(
        private readonly HttpClientInterface $rncpHttpClient,
        #[Autowire('%kernel.project_dir%/var/rncp')]
        private readonly string $cacheDir,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return array{path: string, name: string, date: \DateTimeImmutable}
     *
     * @throws RncpReadException
     */
    public function fetchLatest(): array
    {
        $resource = $this->latestResource();
        $path = $this->cacheDir.'/'.$resource['name'];

        if (is_file($path) && filesize($path) > 0) {
            return ['path' => $path, 'name' => $resource['name'], 'date' => $resource['date']];
        }

        $this->filesystem->mkdir($this->cacheDir);
        $partial = $path.'.part';

        try {
            $response = $this->rncpHttpClient->request('GET', $resource['url'], ['timeout' => 60, 'max_duration' => 900]);
            $handle = fopen($partial, 'w');

            if (false === $handle) {
                throw new RncpReadException('Impossible d’écrire la copie locale de l’export.');
            }

            try {
                foreach ($this->rncpHttpClient->stream($response) as $chunk) {
                    fwrite($handle, $chunk->getContent());
                }
            } finally {
                fclose($handle);
            }
        } catch (ExceptionInterface $exception) {
            $this->filesystem->remove($partial);

            throw new RncpReadException('Le téléchargement de l’export de France compétences a échoué : '.$exception->getMessage(), 0, $exception);
        }

        $this->filesystem->rename($partial, $path, true);
        $this->removeOlderCopies($resource['name']);

        return ['path' => $path, 'name' => $resource['name'], 'date' => $resource['date']];
    }

    /**
     * @return array{name: string, url: string, date: \DateTimeImmutable}
     *
     * @throws RncpReadException
     */
    private function latestResource(): array
    {
        try {
            $data = $this->rncpHttpClient->request('GET', 'https://www.data.gouv.fr/api/1/datasets/'.self::DATASET.'/', ['timeout' => 20])->toArray();
        } catch (ExceptionInterface $exception) {
            throw new RncpReadException('data.gouv.fr ne répond pas : '.$exception->getMessage(), 0, $exception);
        }

        $best = null;
        $resources = $data['resources'] ?? [];

        foreach (\is_array($resources) ? $resources : [] as $resource) {
            if (!\is_array($resource)) {
                continue;
            }

            $title = \is_string($resource['title'] ?? null) ? $resource['title'] : '';
            $url = \is_string($resource['url'] ?? null) ? $resource['url'] : '';

            if ('' === $url || 1 !== preg_match(self::EXPORT_PATTERN, $title, $match)) {
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);

            if (false !== $date && (null === $best || $date > $best['date'])) {
                $best = ['name' => $title, 'url' => $url, 'date' => $date];
            }
        }

        if (null === $best) {
            throw new RncpReadException('Aucun export « export-fiches-rncp-v4-1 » n’est publié sur data.gouv.fr aujourd’hui.');
        }

        return $best;
    }

    private function removeOlderCopies(string $keep): void
    {
        foreach (glob($this->cacheDir.'/export-fiches-rncp-*.zip') ?: [] as $file) {
            if (basename($file) !== $keep) {
                $this->filesystem->remove($file);
            }
        }
    }
}
