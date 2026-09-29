<?php

declare(strict_types=1);

namespace App\Service\Rncp;

use App\Entity\Option;
use App\Entity\Referential;
use App\Entity\ReferentialBlock;
use App\Entity\ReferentialCompetency;
use App\Entity\RncpImport;
use App\Entity\User;
use App\Enum\ReferentialBlockRole;
use App\Enum\ReferentialSynthesisModel;
use App\Enum\RncpImportState;
use App\Repository\ReferentialRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a fiche read from France compétences into a référentiel - **France compétences proposes, the
 * administrator decides** (R14).
 *
 * `propose()` pre-fills the preview: the administrative part, and for the BTS SIO the roles the
 * circulaire gives each block (bloc 1 → E5 synthesis, the option's bloc 2 → E6 fiches, the
 * cybersecurity blocks → none) and a guess of which establishment option « Option A » / « Option B »
 * is. `apply()` takes what the administrator confirmed and nothing else.
 *
 * **A fiche is a new version** (R1): applying never edits an existing référentiel. If one with the
 * same code already holds the fiche this one replaces, it is linked as `replaces`.
 *
 * @phpstan-import-type RncpFiche from RncpFicheReader
 *
 * @phpstan-type BlockChoice array{role: ReferentialBlockRole, options: list<Option>}
 */
class ReferentialRncpImporter
{
    /**
     * The BTS SIO bloc 1's column heads, abbreviated as the class view draws them
     * (portfolio-screens.html, screen 6). A proposal like the rest: editable on the référentiel.
     */
    private const array SIO_SHORT_LABELS = [
        'gerer le patrimoine informatique' => 'Patrim.',
        'repondre aux incidents et aux demandes d assistance et d evolution' => 'Incid.',
        'developper la presence en ligne de l organisation' => 'En ligne',
        'travailler en mode projet' => 'Projet',
        'mettre a disposition des utilisateurs un service informatique' => 'Mise à dispo.',
        'organiser son developpement professionnel' => 'Dév. pro.',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReferentialRepository $referentials,
    ) {
    }

    /** The BTS SIO is recognised by its title, whatever its RNCP number becomes after a renovation. */
    public static function isBtsSio(string $title): bool
    {
        return ReferentialLabelMatcher::contains($title, 'Services informatiques aux organisations');
    }

    /**
     * The option letter a block names - « (Option A, « … ») » - or null for the common core.
     */
    public static function optionLetterOf(string $blockLabel): ?string
    {
        return 1 === preg_match('/\bOption\s+([A-Z])\b/u', $blockLabel, $match) ? $match[1] : null;
    }

    /**
     * What the preview starts from.
     *
     * @param array<string, mixed> $payload a fiche as RncpFicheReader returns it
     * @param list<Option>         $options the establishment's options, to guess A/B from
     *
     * @return array{code: string, label: string, version: string, synthesisModel: ReferentialSynthesisModel, letters: array<string, ?int>, blocks: list<array{role: ReferentialBlockRole, letter: ?string}>}
     */
    public function propose(array $payload, array $options): array
    {
        $title = \is_string($payload['intitule'] ?? null) ? $payload['intitule'] : '';
        $numero = \is_string($payload['numero'] ?? null) ? $payload['numero'] : '';
        $sio = self::isBtsSio($title);

        $blocks = [];
        $letters = [];

        foreach (self::blocksOf($payload) as $index => $block) {
            $letter = self::optionLetterOf($block['libelle']);

            if (null !== $letter) {
                $letters[$letter] = null;
            }

            $role = ReferentialBlockRole::None;
            if ($sio) {
                if (0 === $index) {
                    $role = ReferentialBlockRole::Synthesis;
                } elseif (ReferentialLabelMatcher::contains($block['libelle'], 'Administration des systèmes et des réseaux')
                    || ReferentialLabelMatcher::contains($block['libelle'], 'Conception et développement d’applications')) {
                    $role = ReferentialBlockRole::Showcase;
                }
            }

            $blocks[] = ['role' => $role, 'letter' => $letter];
        }

        // The circulaire's own vocabulary: option A is SISR, option B is SLAM. Only a guess to
        // pre-select the lists - the administrator confirms every one.
        if ($sio) {
            foreach ($options as $option) {
                $short = mb_strtoupper($option->getShortName());
                if (\array_key_exists('A', $letters) && str_contains($short, 'SISR')) {
                    $letters['A'] = $option->getId();
                }
                if (\array_key_exists('B', $letters) && str_contains($short, 'SLAM')) {
                    $letters['B'] = $option->getId();
                }
            }
        }

        return [
            'code' => $sio ? 'bts-sio' : self::slug($title),
            'label' => $sio ? 'BTS SIO' : mb_substr($title, 0, 120),
            'version' => $numero,
            'synthesisModel' => $sio ? ReferentialSynthesisModel::SioE5 : ReferentialSynthesisModel::Generic,
            'letters' => $letters,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param list<BlockChoice> $blockChoices one per block of the fiche, in its order
     *
     * @throws \DomainException when the import is not ready, or a block has no competency to import
     */
    public function apply(RncpImport $import, User $by, string $code, string $label, string $version, ReferentialSynthesisModel $model, array $blockChoices): Referential
    {
        if (RncpImportState::Ready !== $import->getState()) {
            throw new \DomainException('Cette récupération n’est pas prête à être appliquée.');
        }

        $payload = $import->getPayload();
        $blocks = self::blocksOf($payload);

        if (\count($blockChoices) !== \count($blocks)) {
            throw new \DomainException('Chaque bloc de la fiche doit recevoir un rôle.');
        }

        $referential = new Referential($code, $label, $version);
        $referential->setCreatedBy($by);
        $referential->setSynthesisModel($model);
        $referential->setRncpCode(\is_string($payload['numero'] ?? null) ? $payload['numero'] : $import->getRncpCode());
        $referential->setRncpTitle(\is_string($payload['intitule'] ?? null) ? $payload['intitule'] : null);
        $referential->setLevel(\is_string($payload['niveau'] ?? null) && '' !== $payload['niveau'] ? $payload['niveau'] : null);
        $certifiers = \is_array($payload['certificateurs'] ?? null) ? array_filter($payload['certificateurs'], 'is_string') : [];
        $referential->setCertifier([] === $certifiers ? null : implode(', ', $certifiers));
        $referential->setRegisteredFrom(self::date($payload['dateEffet'] ?? null));
        $referential->setRegisteredUntil(self::date($payload['dateFinEnregistrement'] ?? null));
        $referential->setRncpSourceDate($import->getSourceDate());

        $previous = \is_array($payload['anciennes'] ?? null) ? array_values(array_filter($payload['anciennes'], 'is_string')) : [];
        if ([] !== $previous) {
            $referential->setReplacesRncpCode($previous[0]);
            $referential->setReplaces($this->referentials->findOneBy(['rncpCode' => $previous[0]], ['id' => 'DESC']));
        }

        foreach ($blocks as $index => $block) {
            $choice = $blockChoices[$index];
            $entity = new ReferentialBlock($referential, 'B'.($index + 1), $block['libelle'], $index);
            $entity->setRncpCode('' === $block['code'] ? null : $block['code']);
            $entity->setRole($choice['role']);

            foreach ($choice['options'] as $option) {
                $entity->addOption($option);
            }

            foreach ($block['competences'] as $rank => $competency) {
                $competencyEntity = new ReferentialCompetency($entity, 'B'.($index + 1).'.'.($rank + 1), $competency['label'], $competency['skills'], $rank);
                if (ReferentialSynthesisModel::SioE5 === $model) {
                    $competencyEntity->setShortLabel(self::SIO_SHORT_LABELS[ReferentialLabelMatcher::normalize($competency['label'])] ?? null);
                }
            }
        }

        $this->entityManager->persist($referential);
        $import->apply($referential);
        $this->entityManager->flush();

        return $referential;
    }

    /**
     * The blocks of a stored payload, typed back - the payload went through a JSON column.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array{code: string, libelle: string, competences: list<array{label: string, skills: list<string>}>, texte: string}>
     */
    public static function blocksOf(array $payload): array
    {
        $blocks = [];

        foreach (\is_array($payload['blocs'] ?? null) ? $payload['blocs'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }

            $competencies = [];
            foreach (\is_array($block['competences'] ?? null) ? $block['competences'] : [] as $competency) {
                if (!\is_array($competency) || !\is_string($competency['label'] ?? null)) {
                    continue;
                }

                $skills = \is_array($competency['skills'] ?? null) ? array_values(array_filter($competency['skills'], 'is_string')) : [];
                $competencies[] = ['label' => $competency['label'], 'skills' => $skills];
            }

            $blocks[] = [
                'code' => \is_string($block['code'] ?? null) ? $block['code'] : '',
                'libelle' => \is_string($block['libelle'] ?? null) ? $block['libelle'] : '',
                'competences' => $competencies,
                'texte' => \is_string($block['texte'] ?? null) ? $block['texte'] : '',
            ];
        }

        return $blocks;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    private static function slug(string $title): string
    {
        $slug = str_replace(' ', '-', ReferentialLabelMatcher::normalize($title));

        return '' === $slug ? 'referentiel' : mb_substr($slug, 0, 60);
    }
}
