<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileLibraryNode;
use App\Entity\SharedDocument;
use App\Entity\User;
use App\Enum\FileLibraryNodeType;
use App\Service\JsonRequestPayload;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A video shared to a class is watched on the platform and never handed over: « Visionner » instead
 * of the « Télécharger » / « Ouvrir » pair, a player screen instead of the file's address, and no
 * route that answers a download for it - the button being absent is not the rule, the route is.
 */
class StudentSharedVideoTest extends FunctionalTestCase
{
    public function testTheListOffersToWatchAVideoAndNothingElse(): void
    {
        [$student, $share] = $this->sharedVideo();

        $this->client->loginUser($student);
        $this->client->request('GET', '/my/shared-documents');

        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('Visionner', $content);
        self::assertStringNotContainsString(sprintf('/my/shared-documents/%d/download', $share->getId()), $content);
    }

    public function testOpeningAVideoIsAPlayerOfThePlatform(): void
    {
        [$student, $share] = $this->sharedVideo();

        $this->client->loginUser($student);
        $this->client->request('GET', sprintf('/my/shared-documents/%d/open', $share->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-controller="shared-video"', (string) $this->client->getResponse()->getContent());
    }

    public function testTheDownloadRouteRefusesAVideo(): void
    {
        [$student, $share] = $this->sharedVideo();

        $this->client->loginUser($student);
        $this->client->request('GET', sprintf('/my/shared-documents/%d/download', $share->getId()));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testThePlayerIsHandedAnAddressThatPlaysInline(): void
    {
        [$student, $share] = $this->sharedVideo();

        $this->client->loginUser($student);
        $this->client->request('GET', sprintf('/my/shared-documents/%d/playback', $share->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $url = JsonRequestPayload::fromJson((string) $this->client->getResponse()->getContent())->string('url');
        self::assertStringContainsString('response-content-disposition=inline', $url);
        self::assertStringContainsString('X-Amz-Expires=7200', $url);
    }

    /** Anything but a video has « Ouvrir » for that: the playback route is not a second door. */
    public function testThePlaybackRouteSignsNothingButAVideo(): void
    {
        [$student, $share] = $this->sharedVideo('support.pdf');

        $this->client->loginUser($student);
        $this->client->request('GET', sprintf('/my/shared-documents/%d/playback', $share->getId()));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** @return array{User, SharedDocument} */
    private function sharedVideo(string $name = 'capsule.mp4'): array
    {
        $student = $this->createUser(['ROLE_USER', 'ROLE_STUDENT', 'ROLE_CAMPUS'], 'shared.student');
        $teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'shared.teacher');
        $program = $this->createProgram([$student], [$teacher]);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $node = new FileLibraryNode($teacher, FileLibraryNodeType::File, $name);
        $node->setStorageKey('file-library/'.$name)->setSizeBytes(1024);
        $node->setCreatedBy($teacher);
        $entityManager->persist($node);

        $share = new SharedDocument($node, $teacher, $program);
        $entityManager->persist($share);
        $entityManager->flush();

        return [$student, $share];
    }
}
