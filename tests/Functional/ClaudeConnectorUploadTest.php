<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileLibraryNode;
use App\Entity\McpUploadSlot;
use App\Entity\OAuthClient;
use App\Entity\OAuthGrant;
use App\Entity\OAuthToken;
use App\Entity\OnlineCourse;
use App\Entity\User;
use App\Enum\OAuthTokenKind;
use App\OAuth\OAuthSecret;
use App\Service\FileLibraryNodeManager;
use App\Service\FileLibraryWriter;
use App\Service\OnlineCourse\OnlineCourseWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The connector's two ways for a file to reach the platform without base64 or fetching
 * (file_upload_url and its PUT address), and the course picture it sets by `imageFileId`.
 *
 * Each case is pinned on what curl prints back to the model - a status and a JSON body - since that
 * is all Claude reads of a send.
 */
class ClaudeConnectorUploadTest extends FunctionalTestCase
{
    /** A 1×1 PNG: the smallest file fileinfo reads as image/png. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $teacher;
    private OAuthGrant $grant;
    private string $accessToken;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->teacher = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.upload');

        // The connection is made directly: the OAuth dance has its own test
        // (ClaudeConnectorOAuthFlowTest), and this one is about what a connection can send.
        $client = new OAuthClient('client-upload', 'Claude', ['https://claude.ai/api/mcp/auth_callback'], null);
        $this->grant = new OAuthGrant($this->teacher, $client, 'library', new \DateTimeImmutable());
        $secret = OAuthSecret::mint(OAuthTokenKind::Access->prefix());
        $token = new OAuthToken($this->grant, OAuthTokenKind::Access, $secret->selector, $secret->verifierHash, new \DateTimeImmutable(), new \DateTimeImmutable('+1 hour'));
        $this->em->persist($client);
        $this->em->persist($this->grant);
        $this->em->persist($token);
        $this->em->flush();
        $this->accessToken = $secret->secret;
    }

    public function testAnAddressTakesOneFileIntoTheFolderItWasMadeFor(): void
    {
        $folder = static::getContainer()->get(FileLibraryNodeManager::class)->createFolder($this->teacher, null, 'Supports');
        $this->em->flush();
        $bytes = "Polycopié du cours de réseau.\n";

        $result = $this->tool('file_upload_url', ['name' => 'polycopie.txt', 'size' => \strlen($bytes), 'folderId' => $folder->getId(), 'sha256' => hash('sha256', $bytes)]);
        $uploadUrl = $result['uploadUrl'] ?? null;
        self::assertIsString($uploadUrl);
        self::assertStringStartsWith('http://localhost/mcp/uploads/mcup_', $uploadUrl);
        self::assertSame(\strlen($bytes), $result['maxBytes'] ?? null);
        self::assertIsString($result['expiresAt'] ?? null);
        self::assertGreaterThan(new \DateTimeImmutable('+14 minutes'), new \DateTimeImmutable($result['expiresAt']));

        $answer = $this->put($uploadUrl, $bytes);
        $this->assertResponseStatusCodeSame(201);
        self::assertSame('polycopie.txt', $answer['name'] ?? null);
        self::assertSame(\strlen($bytes), $answer['size'] ?? null);
        self::assertSame(hash('sha256', $bytes), $answer['sha256'] ?? null);

        $file = $this->em->find(FileLibraryNode::class, $answer['fileId'] ?? 0);
        self::assertInstanceOf(FileLibraryNode::class, $file);
        self::assertSame($this->teacher->getId(), $file->getOwner()->getId());
        self::assertSame($folder->getId(), $file->getParent()?->getId());

        // Once, and only once.
        $again = $this->put($uploadUrl, $bytes);
        $this->assertResponseStatusCodeSame(404);
        self::assertStringContainsString('file_upload_url', $this->error($again));
    }

    public function testADigestThatDoesNotMatchIsRefusedAndSpendsTheAddress(): void
    {
        $bytes = "Contenu attendu.\n";
        $uploadUrl = $this->uploadUrl('notes.txt', \strlen($bytes), hash('sha256', 'autre chose'));

        $answer = $this->put($uploadUrl, $bytes);
        $this->assertResponseStatusCodeSame(400);
        self::assertStringContainsString('SHA-256', $this->error($answer));
        self::assertSame([], $this->libraryFiles());

        $this->put($uploadUrl, $bytes);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testABodyOfAnotherSizeIsRefused(): void
    {
        $shorter = $this->put($this->uploadUrl('notes.txt', 100), str_repeat('a', 60));
        $this->assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Reçu 60 octets pour 100', $this->error($shorter));

        $this->put($this->uploadUrl('notes.txt', 10), str_repeat('a', 60));
        $this->assertResponseStatusCodeSame(400);
        self::assertSame([], $this->libraryFiles());
    }

    public function testTheContentIsCheckedAsAnyUploadIs(): void
    {
        // A name the policy accepts, a content that is not what it says.
        $bytes = "%PDF-1.4\n%%EOF\n";
        $answer = $this->put($this->uploadUrl('notes.txt', \strlen($bytes)), $bytes);

        $this->assertResponseStatusCodeSame(400);
        self::assertIsString($answer['error'] ?? null);
        self::assertSame([], $this->libraryFiles());
    }

    public function testWhatCanBeRefusedOnTheNameAndSizeIsRefusedBeforeAnyByte(): void
    {
        self::assertStringContainsString('jamais accepté', $this->toolError('file_upload_url', ['name' => 'installeur.exe', 'size' => 10]));
        self::assertStringContainsString('plafond', $this->toolError('file_upload_url', ['name' => 'notes.txt', 'size' => 50 * 1024 * 1024]));
        self::assertStringContainsString('sha256', $this->toolError('file_upload_url', ['name' => 'notes.txt', 'size' => 10, 'sha256' => 'abc']));
        self::assertSame(0, $this->em->getRepository(McpUploadSlot::class)->count([]));
    }

    public function testAnAddressDiesWithItsConnection(): void
    {
        $uploadUrl = $this->uploadUrl('notes.txt', 3);
        $this->grant->revoke(new \DateTimeImmutable());
        $this->em->flush();

        $this->put($uploadUrl, 'abc');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testAnAddressDiesWithTimeAndCannotBeGuessed(): void
    {
        $uploadUrl = $this->uploadUrl('notes.txt', 3);
        $slot = $this->em->getRepository(McpUploadSlot::class)->findOneBy([]);
        self::assertInstanceOf(McpUploadSlot::class, $slot);
        $this->em->createQuery('UPDATE App\Entity\McpUploadSlot s SET s.expiresAt = :past')->setParameter('past', new \DateTimeImmutable('-1 minute'))->execute();
        $this->em->refresh($slot);

        $this->put($uploadUrl, 'abc');
        $this->assertResponseStatusCodeSame(404);

        $this->put('http://localhost/mcp/uploads/mcup_0000000000000000_'.str_repeat('0', 64), 'abc');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testACoursePictureIsALibraryImageCopiedIntoTheCourse(): void
    {
        $writer = static::getContainer()->get(FileLibraryWriter::class);
        $image = $writer->write($this->teacher, null, 'vignette.png', (string) base64_decode(self::PNG, true));
        $text = $writer->write($this->teacher, null, 'notes.txt', "pas une image\n");
        $this->em->flush();

        $created = $this->tool('course_create', ['title' => 'Les réseaux locaux', 'imageFileId' => $image->getId()]);
        $course = $this->em->find(OnlineCourse::class, $created['courseId'] ?? 0);
        self::assertInstanceOf(OnlineCourse::class, $course);
        $first = $course->getImageKey();
        self::assertIsString($first);
        self::assertStringStartsWith('online-courses/'.$course->getId().'/'.$course->getStorageToken().'/image/', $first);
        self::assertNotSame($image->getStorageKey(), $first);
        self::assertIsString($created['imageUrl'] ?? null);

        $courseId = (int) $course->getId();
        $this->tool('course_update', ['courseId' => $courseId, 'imageFileId' => $image->getId()]);
        $course = $this->em->find(OnlineCourse::class, $courseId);
        self::assertInstanceOf(OnlineCourse::class, $course);
        self::assertNotSame($first, $course->getImageKey(), 'a new picture gets a new address, so no cache serves the old one');

        self::assertStringContainsString('vignette', $this->toolError('course_update', ['courseId' => $courseId, 'imageFileId' => $text->getId()]));

        // A refused picture leaves no course behind.
        $this->toolError('course_create', ['title' => 'Jamais créé', 'imageFileId' => $text->getId()]);
        self::assertNull($this->em->getRepository(OnlineCourse::class)->findOneBy(['title' => 'Jamais créé']));

        // Deleting the course hands its picture to the deferred purge with the rest of its folder.
        $course = $this->em->find(OnlineCourse::class, $courseId);
        self::assertInstanceOf(OnlineCourse::class, $course);
        $last = (string) $course->getImageKey();
        static::getContainer()->get(OnlineCourseWriter::class)->delete($course);
        self::assertEquals(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM deleted_object WHERE storage_key LIKE ?', ['%'.$last]));
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed> the tool's structured content
     */
    private function tool(string $name, array $arguments): array
    {
        $result = $this->call($name, $arguments);
        self::assertFalse($result['isError'] ?? null, \sprintf('%s: %s', $name, json_encode($result)));
        $content = $result['structuredContent'] ?? null;
        self::assertIsArray($content);

        return $content;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function toolError(string $name, array $arguments): string
    {
        $result = $this->call($name, $arguments);
        self::assertTrue($result['isError'] ?? null, \sprintf('%s should have been refused', $name));

        return (string) json_encode($result['content'] ?? null, \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<array-key, mixed>
     */
    private function call(string $name, array $arguments): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->accessToken], content: (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => (object) $arguments],
        ]));
        $this->assertResponseIsSuccessful();
        $result = $this->json()['result'] ?? null;
        self::assertIsArray($result);

        return $result;
    }

    private function uploadUrl(string $name, int $size, ?string $sha256 = null): string
    {
        $url = $this->tool('file_upload_url', array_filter(['name' => $name, 'size' => $size, 'sha256' => $sha256]))['uploadUrl'] ?? null;
        self::assertIsString($url);

        return $url;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function put(string $url, string $bytes): array
    {
        $this->client->getCookieJar()->clear();
        static::getContainer()->get('security.token_storage')->setToken(null);
        $this->client->request('PUT', $url, server: ['CONTENT_TYPE' => 'application/octet-stream'], content: $bytes);
        self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));

        return $this->json();
    }

    /**
     * @param array<array-key, mixed> $answer
     */
    private function error(array $answer): string
    {
        $error = $answer['error'] ?? null;
        self::assertIsString($error);

        return $error;
    }

    /** @return list<FileLibraryNode> */
    private function libraryFiles(): array
    {
        return array_values(array_filter(
            $this->em->getRepository(FileLibraryNode::class)->findBy(['owner' => $this->teacher]),
            static fn (FileLibraryNode $node): bool => $node->isFile(),
        ));
    }

    /** @return array<array-key, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
