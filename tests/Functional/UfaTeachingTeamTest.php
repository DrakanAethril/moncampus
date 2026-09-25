<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\InternshipProgramInfo;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * UFA > Formations > « Équipe »: a line written through the panel is stored as free text with the
 * options ticked, edited in place, and deleted - the whole life of a line, through the screens.
 */
class UfaTeachingTeamTest extends FunctionalTestCase
{
    private EntityManagerInterface $entityManager;
    private User $admin;
    private Program $program;
    private Option $slam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'team.admin');
        $this->program = $this->createProgram([], [], $this->admin);

        $this->slam = new Option('SLAM', 'SLAM', '#000000');
        $this->slam->setCreatedBy($this->admin);
        $this->slam->addProgram($this->program);
        $this->entityManager->persist($this->slam);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
    }

    public function testALineIsCreatedEditedAndDeleted(): void
    {
        $base = '/ufa/programs/'.$this->program->getId().'/teaching-team';

        $this->client->request('GET', $base.'/new');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('ufa_teaching_team_entry_submit', [
            'ufa_teaching_team_entry[topic]' => '  Cybersécurité ',
            'ufa_teaching_team_entry[teacher]' => 'M. Dupont',
            'ufa_teaching_team_entry[optionIds]' => [(string) $this->slam->getId()],
        ]);
        self::assertResponseRedirects($base);

        $entries = $this->teachingTeam();
        self::assertCount(1, $entries);
        self::assertSame('Cybersécurité', $entries[0]['topic']);
        self::assertSame('M. Dupont', $entries[0]['teacher']);
        self::assertSame([$this->slam->getId()], $entries[0]['optionIds']);

        $id = $entries[0]['id'];
        $this->client->request('GET', $base.'/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        $form = $this->client->getCrawler()->selectButton('ufa_teaching_team_entry_submit')->form();
        $form['ufa_teaching_team_entry[teacher]'] = 'Mme Martin';
        $checkbox = $form['ufa_teaching_team_entry[optionIds]'];
        self::assertIsArray($checkbox);
        self::assertInstanceOf(ChoiceFormField::class, $checkbox[0]);
        $checkbox[0]->untick();
        $this->client->submit($form);
        self::assertResponseRedirects($base);

        $entries = $this->teachingTeam();
        self::assertSame([$id], array_column($entries, 'id'));
        self::assertSame('Mme Martin', $entries[0]['teacher']);
        // No option ticked any more: the line is for everyone.
        self::assertSame([], $entries[0]['optionIds']);

        $this->client->request('GET', $base);
        self::assertSelectorTextContains('table', 'Mme Martin');
        $this->client->submit($this->client->getCrawler()->filter('#ufa-teaching-team-delete-'.$id)->form());
        self::assertResponseRedirects($base);
        self::assertSame([], $this->teachingTeam());
    }

    public function testABlankLineIsRefused(): void
    {
        $base = '/ufa/programs/'.$this->program->getId().'/teaching-team';

        $this->client->request('GET', $base.'/new');
        $this->client->submitForm('ufa_teaching_team_entry_submit', [
            'ufa_teaching_team_entry[topic]' => ' ',
            'ufa_teaching_team_entry[teacher]' => 'M. Dupont',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->teachingTeam());
    }

    public function testAnUnknownLineIsNotFound(): void
    {
        $this->client->request('GET', '/ufa/programs/'.$this->program->getId().'/teaching-team/abc123/edit');

        self::assertResponseStatusCodeSame(404);
    }

    /** @return list<array{id: string, topic: string, teacher: string, optionIds: list<int>}> */
    private function teachingTeam(): array
    {
        $this->entityManager->clear();
        $info = $this->entityManager->getRepository(InternshipProgramInfo::class)->findOneBy(['program' => $this->program->getId()]);

        return $info?->getTeachingTeam() ?? [];
    }
}
