<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AntivirusScanner;
use PHPUnit\Framework\TestCase;

/**
 * The test that makes "every upload is scanned" true a year from now, rather than true on the day
 * it was written.
 *
 * There is no single choke point into the uploads bucket in this application, and the survey behind
 * design/validated/upload-policy.md under-counted them: it names two services, and there are three.
 * App\Service\FileUploadService writes through the Flysystem operator; App\Service\AudioUploadService
 * and App\Service\VideoUploadService each take the raw S3Client and apply the prefix by hand. A
 * fourth one opening quietly is exactly how an unscanned path appears - so this asserts that the set
 * of classes holding a way into the bucket is *exactly* the known list, and that each of them takes
 * an App\Service\AntivirusScanner.
 *
 * When this fails, the answer is not to add the new class to the list. It is to make it call the
 * scanner, and then add it.
 */
class BucketWritePathsTest extends TestCase
{
    /**
     * Every class allowed to hold a way into the uploads bucket, and nothing else.
     *
     * @var list<class-string>
     */
    private const array KNOWN_WRITE_PATHS = [
        \App\Service\FileUploadService::class,
        \App\Service\AudioUploadService::class,
        \App\Service\VideoUploadService::class,
    ];

    public function testOnlyTheKnownClassesTakeAWayIntoTheBucket(): void
    {
        self::assertSame(
            self::KNOWN_WRITE_PATHS,
            $this->classesTakingBucketAccess(),
            'A class gained an S3Client or a FilesystemOperator for the uploads bucket. '
            .'Make it call App\Service\AntivirusScanner::assertClean() before it writes, then add it to KNOWN_WRITE_PATHS.',
        );
    }

    public function testEveryKnownWritePathTakesTheScanner(): void
    {
        foreach (self::KNOWN_WRITE_PATHS as $class) {
            self::assertContains(
                AntivirusScanner::class,
                $this->constructorTypesOf($class),
                sprintf('%s writes to the bucket without taking the antivirus scanner.', $class),
            );
        }
    }

    /**
     * Read off the constructors rather than off the container: this has to hold in a plain unit
     * test, with no kernel booted, and a class that takes the S3 client is a class that can write
     * whether or not the container happens to wire it today.
     *
     * **Courrier école is deliberately not in scope.** It holds an S3Client too, but a different
     * one, on a separate AWS account and bucket (AWS_MAIL_*, SES/S3/SQS) - and the container tells
     * the two apart by the parameter *name*, `$mailS3Client` being bound to the named
     * `mail.s3_client` service in config/services.yaml while the plain type autowires the uploads
     * client. So the same signal is used here. Those objects store received mail, not uploads, and
     * the mail path has its own scanning story (App\Enum\EmailScanVerdict).
     *
     * @return list<class-string>
     */
    private function classesTakingBucketAccess(): array
    {
        $found = [];

        foreach ($this->sourceFiles(\dirname(__DIR__, 2).'/src') as $file) {
            $class = $this->classOf($file);

            if (null === $class || !class_exists($class)) {
                continue;
            }

            if ([] !== $this->bucketParametersOf($class)) {
                $found[] = $class;
            }
        }

        sort($found);
        $known = self::KNOWN_WRITE_PATHS;
        sort($known);

        // Compared in the declared order so the failure message reads like the list above.
        return $found === $known ? self::KNOWN_WRITE_PATHS : $found;
    }

    /**
     * The constructor parameters that are a way into the *uploads* bucket.
     *
     * @return list<string>
     */
    private function bucketParametersOf(string $class): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        if (null === $constructor) {
            return [];
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            if (\League\Flysystem\FilesystemOperator::class === $type->getName()) {
                $parameters[] = $parameter->getName();

                continue;
            }

            if (\Aws\S3\S3Client::class === $type->getName() && 'mailS3Client' !== $parameter->getName()) {
                $parameters[] = $parameter->getName();
            }
        }

        return $parameters;
    }

    /** @return list<string> */
    private function constructorTypesOf(string $class): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        if (null === $constructor) {
            return [];
        }

        $types = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $types[] = $type->getName();
            }
        }

        return $types;
    }

    /** @return list<string> */
    private function sourceFiles(string $directory): array
    {
        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function classOf(string $file): ?string
    {
        $source = file_get_contents($file);

        if (false === $source
            || !preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
            || !preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $name)) {
            return null;
        }

        return trim($namespace[1]).'\\'.$name[1];
    }
}
