<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameTemplateRepository;
use App\Tests\Functional\SeriousGame\SeriousGameTestTrait;

/**
 * The serious-game tools of the Claude connector (design/validated/serious-games.md §9): an
 * administrator reads a template, proposes a draft - refused whole at its first format error,
 * benched at once - and validates it; a teacher gets nothing.
 */
class ClaudeConnectorSeriousGameToolsTest extends FunctionalTestCase
{
    use ClaudeConnectorTestTrait;
    use SeriousGameTestTrait;

    public function testAnAdministratorRewritesATemplateWithClaude(): void
    {
        $this->installObservatoire(units: ['taux', 'frac']);
        $admin = $this->createUser(['ROLE_USER', 'ROLE_ADMIN'], 'claude.admin');
        $token = $this->accessTokenFor($admin);

        $list = $this->callTool($token, 'serious_game_template_list');
        self::assertFalse($list['isError'], $list['text']);
        self::assertStringContainsString('taux', $list['text']);

        $read = $this->callTool($token, 'serious_game_template_get', ['unit' => 'taux']);
        self::assertFalse($read['isError'], $read['text']);
        self::assertIsArray($read['data']['definition']);
        self::assertIsArray($read['data']['samples']);
        self::assertCount(5, $read['data']['samples']);

        $broken = $read['data']['definition'];
        $broken['answer'] = ['type' => 'qcm', 'value' => 't'];
        $refused = $this->callTool($token, 'serious_game_template_propose', ['unit' => 'taux', 'definition' => $broken]);
        self::assertTrue($refused['isError']);
        self::assertStringContainsString('answer.type', $refused['text']);

        $definition = $read['data']['definition'];
        $definition['label'] = 'Taux d’évolution (v2)';
        $proposed = $this->callTool($token, 'serious_game_template_propose', ['unit' => 'taux', 'definition' => json_encode($definition), 'note' => 'Libellé']);
        self::assertFalse($proposed['isError'], $proposed['text']);
        self::assertSame(2, $proposed['data']['version']);
        self::assertSame('draft', $proposed['data']['status']);
        self::assertCount(10, (array) $proposed['data']['samples']);

        $validated = $this->callTool($token, 'serious_game_template_validate', ['unit' => 'taux', 'version' => 2]);
        self::assertFalse($validated['isError'], $validated['text']);
        $game = static::getContainer()->get(SeriousGameRepository::class)->findOneBySlug('observatoire') ?? self::fail('game');
        self::assertSame(2, static::getContainer()->get(SeriousGameTemplateRepository::class)->findCurrent($game, 'taux')?->getVersion());

        $guide = $this->callTool($token, 'format_guide', ['format' => 'gabarit_serious_game']);
        self::assertStringContainsString('moncampus-gabarit/1', $guide['text']);
        self::assertStringContainsString('nonZero(a, b)', $guide['text']);
    }

    public function testATeacherCannotTouchTheTemplates(): void
    {
        $this->installObservatoire(units: ['taux']);
        $token = $this->accessTokenFor($this->createUser(['ROLE_USER', 'ROLE_TEACHER'], 'claude.teacher'));

        $refused = $this->callTool($token, 'serious_game_template_list');
        self::assertTrue($refused['isError']);
        self::assertStringContainsString('administrateur', $refused['text']);
    }
}
