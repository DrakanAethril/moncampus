<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\ContentShare;
use App\Enum\ContentShareSubject;
use App\Enum\Feature;
use App\Mcp\McpContentShares;
use App\Mcp\McpTool;
use App\Mcp\McpToolCall;
use App\Mcp\McpToolException;
use App\Mcp\McpToolResult;

/**
 * Outils › Partages, read: « Reçus » (what colleagues addressed to me, by name or to a group of
 * mine), « Catalogue » (what they published to the whole establishment) and « Mes partages » (one
 * row per share, with its audience and how often it was duplicated). The `shareId` of a row is what
 * `share_duplicate` and `share_revoke` take.
 */
final readonly class ShareListTool implements McpTool
{
    public function __construct(private McpContentShares $shares)
    {
    }

    public function name(): string
    {
        return 'share_list';
    }

    public function title(): string
    {
        return 'Lister les partages';
    }

    public function description(): string
    {
        return 'Liste les partages entre collègues : `box: "received"` ce que des collègues ont partagé à l\'enseignant (nommément ou à une de ses équipes), `"catalog"` ce qu\'ils ont publié pour tout l\'établissement, `"mine"` les partages de l\'enseignant lui-même (destinataires, nombre de duplications, retirés ou non). Filtres facultatifs : `type` et `query` (mots cherchés dans le titre, l\'auteur et les étiquettes, tous requis). Chaque ligne donne le `shareId` que prennent `share_duplicate` et `share_revoke`, et le `subjectId` : un quiz ou une séquence partagés se lisent avec `quiz_get` ou `sequence_get`. Les progressions partagées se gèrent dans MonCampus.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'box' => ['type' => 'string', 'enum' => ['received', 'catalog', 'mine']],
                'type' => ['type' => 'string', 'enum' => McpContentShares::TYPES],
                'query' => ['type' => 'string'],
            ],
            'required' => ['box'],
            'additionalProperties' => false,
        ];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function features(): array
    {
        return [Feature::ContentSharing];
    }

    public function call(McpToolCall $call): McpToolResult
    {
        $box = $call->arguments->string('box');
        $typeArgument = $call->arguments->string('type');
        $type = '' === $typeArgument ? null : ContentShareSubject::tryFrom($typeArgument);

        if ('' !== $typeArgument && (null === $type || ContentShareSubject::Progression === $type)) {
            throw new McpToolException('L\'argument « type » vaut « quiz », « file », « sequence » ou « seance ».');
        }

        $query = trim($call->arguments->string('query'));

        $rows = array_map(
            fn (ContentShare $share): array => $this->shares->row($share, $call->user),
            array_values(array_filter(
                $this->shares->box($box, $call->user),
                fn (ContentShare $share): bool => (null === $type || $type === $share->getSubject()) && $this->shares->matches($share, $query),
            )),
        );

        return McpToolResult::data(\sprintf('%d partage(s).', \count($rows)), ['shares' => $rows]);
    }
}
