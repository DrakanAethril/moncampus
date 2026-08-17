<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\FileLibraryNode;
use App\Entity\User;
use App\Enum\AssignmentNature;
use App\Enum\FileLibraryNodeType;
use App\Service\FileLibraryWorkFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * What « Créer un travail » makes of a file, which is the whole of what the entry point decides
 * before the teacher touches anything (design/validated/file-library.md, "Create a work from a
 * file").
 *
 * The mapping is worth pinning because it is how the two media natures keep the only entry points
 * they have ever had, now that the Vidéos tool has lost its front door: a video is a *watching* and
 * an audio file a *listening*, without either being asked of the teacher.
 */
class FileLibraryWorkFactoryTest extends TestCase
{
    public function testAVideoIsAWatchingAndAnAudioFileAListening(): void
    {
        $factory = $this->factory();

        foreach (['seance.mp4', 'capsule.webm', 'demo.mov'] as $name) {
            self::assertSame(AssignmentNature::Watching, $factory->natureFor($this->file($name)), $name);
            self::assertTrue($factory->isVideo($this->file($name)));
        }

        foreach (['consigne.mp3', 'dictee.m4a', 'extrait.ogg', 'note.wav', 'voix.opus', 'master.flac'] as $name) {
            self::assertSame(AssignmentNature::Listening, $factory->natureFor($this->file($name)), $name);
            self::assertTrue($factory->isAudio($this->file($name)));
        }
    }

    public function testAnythingElseIsAToSubmit(): void
    {
        $factory = $this->factory();

        foreach (['sujet-tp.pdf', 'captures.zip', 'notes.txt', 'sans-extension'] as $name) {
            self::assertSame(AssignmentNature::ToSubmit, $factory->natureFor($this->file($name)), $name);
        }
    }

    public function testTheTitleIsTheNameWithoutItsExtension(): void
    {
        $factory = $this->factory();

        // What the teacher would have typed anyway - and the extension in a title reads as a filename
        // rather than as a piece of work.
        self::assertSame('sujet-tp-vlan', $factory->titleFor($this->file('sujet-tp-vlan.pdf')));
        self::assertSame('cours.adressage', $factory->titleFor($this->file('cours.adressage.pdf')));
        self::assertSame('sans-extension', $factory->titleFor($this->file('sans-extension')));
    }

    private function factory(): FileLibraryWorkFactory
    {
        return new FileLibraryWorkFactory($this->createStub(EntityManagerInterface::class));
    }

    private function file(string $name): FileLibraryNode
    {
        return new FileLibraryNode(new User('work.tester'), FileLibraryNodeType::File, $name);
    }
}
