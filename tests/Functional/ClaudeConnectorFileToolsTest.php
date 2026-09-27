<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FileLibraryNode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The file tools of the Claude connector: a handout written by Claude lands in the library through
 * the upload gates, can be hung on a séance, and is read back - which is what « start from my
 * course » rests on.
 */
class ClaudeConnectorFileToolsTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;

    public function testAHandoutIsCreatedAttachedAndReadBack(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));

        $created = $this->callTool($token, 'file_create', [
            'title' => 'Les VLAN : fiche élève',
            'content' => "## Définition\n\nUn **VLAN** découpe un réseau.\n\n| ID | Nom |\n|---|---|\n| 10 | Admin |",
        ]);
        self::assertFalse($created['isError'], $created['text']);
        self::assertSame('Les VLAN - fiche élève.pdf', $created['data']['name']);

        $file = $this->entityManager()->find(FileLibraryNode::class, $created['data']['fileId']);
        self::assertInstanceOf(FileLibraryNode::class, $file);
        self::assertSame('application/pdf', $file->getMimeType());

        $read = $this->callTool($token, 'file_read', ['fileId' => $created['data']['fileId']]);
        self::assertFalse($read['isError'], $read['text']);
        self::assertStringContainsString('Définition', $read['text']);
        self::assertStringContainsString('Admin', $read['text']);

        $unlinked = $this->callTool($token, 'file_list', ['linked' => 'unlinked']);
        self::assertSame([$created['data']['fileId']], array_column((array) $unlinked['data']['files'], 'id'));

        $sequence = $this->callTool($token, 'sequence_create', ['document' => json_decode((string) file_get_contents(__DIR__.'/../Fixtures/sequence-ansible.json'), true)]);
        $seances = $sequence['data']['seances'];
        self::assertIsArray($seances);
        $seance = $seances[0];
        self::assertIsArray($seance);

        $linked = $this->callTool($token, 'file_link', ['fileId' => $created['data']['fileId'], 'seanceId' => $seance['id']]);
        self::assertFalse($linked['isError'], $linked['text']);

        $list = $this->callTool($token, 'file_list', ['linked' => 'linked']);
        $files = $list['data']['files'];
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $first = $files[0];
        self::assertIsArray($first);
        self::assertSame([['kind' => 'seance', 'id' => $seance['id'], 'title' => $seance['title']]], $first['linkedTo'] ?? null);

        // The same file, reached from the séquence side, as a resource of its séance.
        $viaResource = $this->callTool($token, 'file_read', ['resourceId' => $linked['data']['resourceId']]);
        self::assertStringContainsString('Définition', $viaResource['text']);
    }

    public function testTheMarkdownFormatKeepsTheSource(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));

        $created = $this->callTool($token, 'file_create', ['title' => 'Trunk', 'content' => 'Le trunk transporte plusieurs VLAN.', 'format' => 'md']);
        $read = $this->callTool($token, 'file_read', ['fileId' => $created['data']['fileId']]);

        self::assertStringContainsString("# Trunk\n\nLe trunk transporte plusieurs VLAN.", $read['text']);
    }

    public function testAnUploadGoesThroughTheSameTypeGateAsTheScreen(): void
    {
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));

        $csv = $this->callTool($token, 'file_upload', ['name' => 'plan.csv', 'base64' => base64_encode("vlan;nom\n10;admin\n")]);
        self::assertFalse($csv['isError'], $csv['text']);

        $exe = $this->callTool($token, 'file_upload', ['name' => 'outil.exe', 'base64' => base64_encode("MZ\x90\x00binary")]);
        self::assertTrue($exe['isError']);
    }

    public function testSomebodyElsesFileIsNotFound(): void
    {
        $owner = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.owner');
        $theirs = $this->callTool($this->accessTokenFor($owner), 'file_create', ['title' => 'Privé', 'content' => 'Secret', 'format' => 'md']);

        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'prof.claude'));
        $read = $this->callTool($token, 'file_read', ['fileId' => $theirs['data']['fileId']]);

        self::assertTrue($read['isError']);
        self::assertStringContainsString('introuvable', $read['text']);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
