<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ObjectStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Proves, from the server, that the runtime can actually write, read **and remove** objects in the
 * uploads bucket (design/validated/object-deletion.md, "app:uploads:check, the probe").
 *
 * A diagnostic, not a cron, built on the model of `app:antivirus:check` - which exists for exactly
 * this shape of problem: a configuration that fails without announcing itself. The question it
 * answers is factual and cannot be settled by a design document: **does this IAM user hold
 * `s3:DeleteObject`?**
 *
 * The survey behind the design argued it must: nineteen call sites delete without a `try`/`catch`,
 * Flysystem turns a 403 into `UnableToDeleteFile` and the raw client throws, so a missing permission
 * would be answering 500 to teachers rather than silently orphaning objects. That argument is sound
 * and it is still an argument. This measures.
 *
 * **It matters more now than it did before deferred deletion, not less.** Since App\Service\ObjectStore
 * took over, a user deletion no longer touches S3 at all - it writes a row - so a missing permission
 * has stopped being loud. It would surface only in the nightly purge, on a server nobody is watching,
 * as bytes that never leave. This command is what makes it visible on demand.
 *
 * The three write paths are probed **each in its own prefix**, because a bucket policy discriminates
 * on prefixes, not on PHP classes - a permission granted to one and not another is exactly the
 * failure a single probe would hide:
 *
 *     diagnostics/…             App\Service\FileUploadService  (Flysystem)
 *     audio-recordings/…        App\Service\AudioUploadService (raw client)
 *     video-resources/…         App\Service\VideoUploadService (raw client)
 *
 * Everything goes through App\Service\ObjectStore - the removal because it is the one method allowed
 * to take bytes out (and the one the purge calls, so what is proved here is what runs there), and
 * the write because keeping the S3 client inside that single class is what
 * App\Tests\Service\BucketWritePathsTest exists to enforce.
 *
 * Exit code 0 only when all three prefixes accept a write, a read, a delete and answer "gone"
 * afterwards; anything else names the operation and the key it failed on.
 *
 * In dev this runs against MinIO, which allows everything: a green run here says the code is right,
 * not that production's IAM is. Only a run on the production host answers that.
 */
#[AsCommand(
    name: 'app:uploads:check',
    description: 'Vérifie que le stockage des fichiers accepte bien écriture, lecture et suppression, sur les trois préfixes.',
)]
class CheckUploadsCommand extends Command
{
    public function __construct(
        private readonly ObjectStore $objectStore,
        private readonly string $awsS3Bucket,
        private readonly string $awsS3Prefix,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Contrôle du stockage des fichiers');
        $io->writeln(\sprintf('Bucket <info>%s</info>, préfixe d’environnement <info>%s</info>.', $this->awsS3Bucket, '' === $this->awsS3Prefix ? '(aucun)' : $this->awsS3Prefix));

        $token = bin2hex(random_bytes(8));
        $failures = 0;

        // One probe per prefix - see the class docblock for why the prefix, and not the service, is
        // what a bucket policy discriminates on.
        foreach ([
            'Fichiers (FileUploadService)' => \sprintf('diagnostics/check-%s.txt', $token),
            'Audio (AudioUploadService)' => \sprintf('audio-recordings/diagnostics/check-%s.txt', $token),
            'Vidéo (VideoUploadService)' => \sprintf('video-resources/diagnostics/check-%s.txt', $token),
        ] as $label => $key) {
            $failures += $this->probe($io, $label, $key);
        }

        if ($failures > 0) {
            $io->error(\sprintf('%d chemin(s) sur 3 ne fonctionnent pas complètement.', $failures));
            $io->writeln([
                'Une suppression refusée ne se voit plus à l’écran depuis que la suppression est différée :',
                'elle ne se manifesterait que la nuit, dans app:uploads:purge, sous la forme d’octets',
                'qui ne partent jamais. À regarder : la politique IAM de l’utilisateur AWS_ACCESS_KEY_ID,',
                'et notamment s3:DeleteObject sur le préfixe nommé ci-dessus.',
            ]);

            return Command::FAILURE;
        }

        $io->success('Écriture, lecture et suppression fonctionnent sur les trois préfixes.');

        return Command::SUCCESS;
    }

    /**
     * Write, read back, remove, read again. The fourth step is the one worth having: a delete that
     * answers 200 and leaves the object there is exactly what a bucket with a deny-by-omission
     * policy and a proxy in front of it can look like.
     *
     * @return int 0 when the whole cycle worked, 1 otherwise
     */
    private function probe(SymfonyStyle $io, string $label, string $key): int
    {
        $storageKey = $this->objectStore->storageKeyFor($key);
        $body = \sprintf('MonCampus uploads check, %s.', (new \DateTimeImmutable())->format('c'));

        // Not written through each service's own store(): those take an UploadedFile and apply
        // their own acceptance rules - an audio codec allowlist, MP4 only - which would refuse a
        // synthetic probe file for reasons that have nothing to do with the bucket. What is under
        // test is the bucket's answer on each prefix, and the key is what decides that.
        try {
            $this->objectStore->writeProbe($storageKey, $body);
        } catch (\Throwable $failure) {
            $io->writeln(\sprintf('%s : <error>PutObject a échoué</error> sur %s — %s', $label, $storageKey, $failure->getMessage()));

            return 1;
        }

        if (!$this->objectStore->exists($storageKey)) {
            $io->writeln(\sprintf('%s : <error>GetObject ne retrouve pas</error> %s après écriture.', $label, $storageKey));

            return 1;
        }

        try {
            $this->objectStore->remove($storageKey);
        } catch (\Throwable $failure) {
            $io->writeln(\sprintf('%s : <error>DeleteObject a échoué</error> sur %s — %s', $label, $storageKey, $failure->getMessage()));

            return 1;
        }

        if ($this->objectStore->exists($storageKey)) {
            $io->writeln(\sprintf('%s : <error>DeleteObject a répondu sans supprimer</error> — %s est toujours là.', $label, $storageKey));

            return 1;
        }

        $io->writeln(\sprintf('%s : écriture, lecture, suppression ✔ (%s)', $label, $storageKey));

        return 0;
    }
}
