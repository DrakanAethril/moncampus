<?php

declare(strict_types=1);

namespace App\Tests\Functional\SeriousGame;

use App\Entity\SeriousGameTemplate;
use App\Entity\User;
use App\Enum\SeriousGameTemplateStatus;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameTemplateRepository;
use App\SeriousGame\SeriousGameTemplates;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Paramètres › Serious games (design/validated/serious-games.md §8, §9): an administrator publishes
 * a game, sets its answer kinds, reads a template version and makes a green draft the current one.
 */
class SeriousGameAdministrationTest extends FunctionalTestCase
{
    use SeriousGameTestTrait;

    public function testTheAdministratorReadsAndRunsTheGames(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'sg.admin');
        $game = $this->installObservatoire(units: ['taux', 'frac']);

        $this->assertScreens($admin, [
            '/settings/serious-games' => 200,
            '/settings/serious-games/observatoire' => 200,
            '/settings/serious-games/observatoire/templates/taux/1' => 200,
            '/settings/serious-games/observatoire/templates/taux/9' => 404,
            '/settings/serious-games/nope' => 404,
            '/serious-games' => 200,
        ]);
        $this->client->request('GET', '/settings/serious-games/observatoire/templates/taux/1');
        self::assertStringContainsString('passe de', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/settings/serious-games');
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/publish"]')->form());
        $games = static::getContainer()->get(SeriousGameRepository::class);
        self::assertFalse($games->find($game->getId())?->isPublished());

        $this->client->request('POST', '/settings/serious-games/observatoire/answer-kinds', ['_token' => $this->csrfToken('serious-game-kinds-observatoire'), 'kinds' => ['choice' => '1']]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(['choice'], $games->find($game->getId())?->getAnswerKinds());
    }

    public function testAGreenDraftBecomesTheCurrentVersion(): void
    {
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'sg.admin');
        $game = $this->installObservatoire(units: ['taux']);
        $templates = static::getContainer()->get(SeriousGameTemplateRepository::class);
        $shipped = $templates->findCurrent($game, 'taux') ?? self::fail('no taux');

        $document = $shipped->getDefinition();
        $document['label'] = 'Taux d’évolution (reformulé)';
        $draft = static::getContainer()->get(SeriousGameTemplates::class)->propose($game, 'taux', $document, static::getContainer()->get(EntityManagerInterface::class)->getReference(User::class, $admin->getId()), 'Libellé reformulé');
        self::assertSame(2, $draft->getVersion());
        self::assertTrue($draft->isBenchGreen());
        $stillCurrent = $templates->findCurrent($game, 'taux');
        self::assertSame($shipped->getId(), $stillCurrent->getId(), 'a draft serves nobody');

        $this->client->loginUser($admin);
        $this->client->request('GET', '/settings/serious-games/observatoire/templates/taux/2');
        self::assertResponseIsSuccessful();
        $this->client->submit($this->client->getCrawler()->filter('form[action$="/validate"]')->form());

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $game = static::getContainer()->get(SeriousGameRepository::class)->find($game->getId()) ?? self::fail('game');
        $current = $templates->findCurrent($game, 'taux');
        self::assertInstanceOf(SeriousGameTemplate::class, $current);
        self::assertSame(2, $current->getVersion());
        self::assertSame(SeriousGameTemplateStatus::Superseded, $templates->find($shipped->getId())?->getStatus());
    }

    public function testNobodyElseAdministers(): void
    {
        $staff = $this->createUser(['ROLE_USER', 'ROLE_STAFF'], 'sg.staff');
        $this->installObservatoire(units: ['taux']);

        $this->assertScreens($staff, ['/settings/serious-games' => 404, '/settings/serious-games/observatoire' => 404, '/settings/serious-games/observatoire/templates/taux/1' => 404]);
    }
}
