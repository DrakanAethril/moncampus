<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Program;
use App\Entity\Progression;
use App\Entity\ProgressionSeance;
use App\Entity\ProgressionSequence;
use App\Entity\SeanceInstance;
use App\Entity\SequenceInstance;
use App\Entity\SequenceTemplate;
use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Entity\User;
use App\Enum\ProgressionTrameAction;
use App\Service\ProgressionTrameImporter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The four constraints of the progression trame, **named before the click**
 * (design/validated/content-sharing-between-teachers.md, "The progression trame").
 *
 * None of them is an error, and that is the whole point: each is a rule of the model made visible on
 * the confirmation screen rather than discovered after it. The fourth - a recipient with no matière
 * free - belongs to the screen and is answered there; the three that belong to the analysis are
 * pinned here, along with the fact that a skipped séquence leaves no half-written progression behind.
 *
 * Everything is read back from the database after the fixture is flushed: Doctrine does not push a
 * freshly persisted child into its parent's inverse collection, so a trame built in this unit of work
 * would answer "no séquence" to the importer. A controller always holds one loaded from the database,
 * and this is what that looks like.
 */
class ProgressionTrameImportTest extends FunctionalTestCase
{
    public function testEachSequenceIsNamedWithWhatWillHappenToIt(): void
    {
        [$sourceId, $programId] = $this->trameFixture('trame');

        $analysis = static::getContainer()->get(ProgressionTrameImporter::class)->analyse(
            $this->find(Progression::class, $sourceId),
            $this->find(Program::class, $programId),
        );

        self::assertSame(
            [ProgressionTrameAction::Copied, ProgressionTrameAction::Detached, ProgressionTrameAction::Skipped],
            array_column($analysis['lines'], 'action'),
        );
        // The skipped one is named, not dropped: three lines shown, two taken up.
        self::assertCount(3, $analysis['lines']);
        self::assertSame(2, $analysis['keptCount']);
    }

    /** The import writes what the analysis promised - and leaves out exactly what it named. */
    public function testTheSkippedSequenceIsLeftOutAndTheRestIsWritten(): void
    {
        [$sourceId, , $recipientTopicId, $recipientId] = $this->trameFixture('trame2');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $progression = static::getContainer()->get(ProgressionTrameImporter::class)->import(
            $this->find(Progression::class, $sourceId),
            $this->find(User::class, $recipientId),
            $this->find(Topic::class, $recipientTopicId),
            null,
        );

        self::assertSame($recipientTopicId, $progression->getTopic()?->getId());
        self::assertSame($recipientId, $progression->getTeacher()?->getId());

        $written = $entityManager->getRepository(ProgressionSequence::class)->findBy(['progression' => $progression], ['position' => 'ASC']);
        self::assertCount(2, $written, 'the séquence another progression already teaches is left out');

        // The trame travelled: the séance rows carry the author's titles and durations, and **no
        // date at all** - a date is a property of a timetable, and a colleague's is not yours.
        $seances = $entityManager->getRepository(ProgressionSeance::class)->findBy(['progressionSequence' => $written[0]], ['position' => 'ASC']);
        self::assertSame(['Découverte', 'Mise en pratique'], array_map(static fn (ProgressionSeance $s): string => $s->getTitle(), $seances));
        self::assertSame([55, 110], array_map(static fn (ProgressionSeance $s): ?int => $s->getPlannedMinutes(), $seances));
        self::assertNull($written[0]->getForcedStartDate());
    }

    /**
     * A progression of three séquences, one per branch the model can take - and **two classes**,
     * because that is what the feature is for: the author planned their year for one, the recipient
     * takes the shape of it for theirs.
     *
     * 1. an ordinary one - its template is still there and the recipient's class has no instance of
     *    it;
     * 2. one whose template has been deleted since - it can only be copied instance to instance;
     * 3. one whose template the recipient's class **already** carries, in another progression -
     *    skipped, and named. A SequenceInstance is planned once for the whole class.
     *
     * @return array{int, int, int, int} the source progression, the recipient's class, their free
     *                                   matière, and themselves
     */
    private function trameFixture(string $prefix): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $author = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], $prefix.'.author');
        $recipient = $this->createUser(['ROLE_USER', 'ROLE_TEACHER'], $prefix.'.recipient');
        $authorProgram = $this->createProgram([], [$author], $author);
        $recipientProgram = $this->createProgram([], [$recipient], $recipient);

        $progression = new Progression($this->topic($authorProgram, $author, 'Réseaux (auteur)'), $author);
        $entityManager->persist($progression);

        $this->sequenceLine($progression, $author, $authorProgram, 'Le modèle OSI', withTemplate: true);
        $this->sequenceLine($progression, $author, $authorProgram, 'Commutation', withTemplate: false);
        $planned = $this->sequenceLine($progression, $author, $authorProgram, 'Routage statique', withTemplate: true);

        // The third case, on the recipient's own class: the very same template is already
        // instantiated there and carried by another progression of that class.
        $alreadyThere = new SequenceInstance($recipientProgram, $recipient);
        $alreadyThere->setTitre('Routage statique');
        $alreadyThere->setSourceTemplate($planned);
        $entityManager->persist($alreadyThere);

        $other = new Progression($this->topic($recipientProgram, $recipient, 'Routage (autre matière)'), $recipient);
        $entityManager->persist($other);
        $entityManager->persist(new ProgressionSequence($other, $alreadyThere));

        $recipientTopic = $this->topic($recipientProgram, $recipient, 'Réseaux (destinataire)');

        $entityManager->flush();

        $ids = [(int) $progression->getId(), (int) $recipientProgram->getId(), (int) $recipientTopic->getId(), (int) $recipient->getId()];
        $entityManager->clear();

        return $ids;
    }

    /** @return SequenceTemplate|null the template the line was built on, when it has one */
    private function sequenceLine(Progression $progression, User $author, Program $program, string $title, bool $withTemplate): ?SequenceTemplate
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $instance = new SequenceInstance($program, $author);
        $instance->setTitre($title);
        $template = null;

        if ($withTemplate) {
            $template = new SequenceTemplate($author);
            $template->setTitre($title);
            $entityManager->persist($template);
            $instance->setSourceTemplate($template);
        }

        $entityManager->persist($instance);

        foreach ([['Découverte', '55.00'], ['Mise en pratique', '110.00']] as $position => [$seanceTitle, $duree]) {
            $seanceInstance = new SeanceInstance($program, $author);
            $seanceInstance->setSequenceInstance($instance);
            $seanceInstance->setOrdre($position);
            $seanceInstance->setTitre($seanceTitle);
            $seanceInstance->setDuree($duree);
            $entityManager->persist($seanceInstance);
        }

        $sequence = new ProgressionSequence($progression, $instance);
        $sequence->setPosition($progression->getSequences()->count());
        $entityManager->persist($sequence);

        foreach ([['Découverte', 55], ['Mise en pratique', 110]] as $position => [$seanceTitle, $minutes]) {
            $seance = new ProgressionSeance($sequence, $seanceTitle);
            $seance->setPosition($position);
            $seance->setPlannedMinutes($minutes);
            $entityManager->persist($seance);
        }

        return $template;
    }

    private function topic(Program $program, User $teacher, string $name): Topic
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $group = new TopicGroup('Bloc de test '.$name, $program);
        $group->setCreatedBy($teacher);
        $entityManager->persist($group);

        $topic = new Topic($name, $program, $group);
        $topic->setTeacher($teacher);
        $topic->setCreatedBy($teacher);
        $entityManager->persist($topic);

        return $topic;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, int $id): object
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository($class)->find($id)
            ?? self::fail(\sprintf('The fixture %s disappeared.', $class));
    }
}
