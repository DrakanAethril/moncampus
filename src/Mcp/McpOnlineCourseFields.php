<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\OnlineCourse;
use App\Repository\OnlineCourseRepository;
use App\Service\OnlineCourse\OnlineCourseWriter;

/**
 * The card of a course as course_create and course_update take it: every field named is written,
 * **a field not named is left as it is** - an update that emptied what it was not told about would
 * erase a teacher's summary because Claude was asked to fix a typo in the title.
 *
 * Refuses rather than truncates: a summary cut at 300 characters in the middle of a sentence is
 * worse than one Claude rewrites shorter.
 */
final readonly class McpOnlineCourseFields
{
    public function __construct(
        private OnlineCourseWriter $writer,
        private OnlineCourseRepository $courses,
    ) {
    }

    /**
     * @return list<string> the fields written
     *
     * @throws McpToolException
     */
    public function apply(OnlineCourse $course, McpToolCall $call): array
    {
        $given = $call->arguments->toArray();
        $written = [];

        if (\array_key_exists('title', $given)) {
            $title = trim($call->arguments->string('title'));
            if ('' === $title || mb_strlen($title) > 200) {
                throw new McpToolException('Le titre (« title ») est obligatoire et compte 200 caractères au plus.');
            }
            $course->setTitle($title);
            $written[] = 'title';
        }

        if (\array_key_exists('slug', $given)) {
            $slug = $this->writer->normalizeSlug($call->arguments->string('slug'));
            if ($slug !== $course->getSlug()) {
                if ($course->isSlugFrozen()) {
                    throw new McpToolException('L\'adresse (« slug ») de ce cours est figée depuis sa première publication : un lien déjà donné doit continuer de marcher.');
                }
                if ('' === $slug || null !== $this->courses->findOneByOwnerAndSlug($course->getOwner(), $slug)) {
                    throw new McpToolException(\sprintf('L\'adresse « %s » est vide ou déjà prise par un autre cours de l\'enseignant.', $slug));
                }
                $course->setSlug($slug);
            }
            $written[] = 'slug';
        }

        if (\array_key_exists('summary', $given)) {
            $summary = trim($call->arguments->string('summary'));
            if (mb_strlen($summary) > 300) {
                throw new McpToolException(\sprintf('Le résumé (« summary ») compte 300 caractères au plus ; celui-ci en compte %d. Raccourcis-le.', mb_strlen($summary)));
            }
            $course->setSummary($summary);
            $written[] = 'summary';
        }

        if (\array_key_exists('description', $given)) {
            $this->writer->describeMarkdown($course, $call->arguments->string('description'));
            $written[] = 'description';
        }

        if (\array_key_exists('estimatedMinutes', $given)) {
            $minutes = $call->arguments->int('estimatedMinutes');
            if (null !== $minutes && ($minutes < 1 || $minutes > 6000)) {
                throw new McpToolException('La durée (« estimatedMinutes ») est un nombre de minutes entre 1 et 6000.');
            }
            $course->setEstimatedMinutes($minutes);
            $written[] = 'estimatedMinutes';
        }

        if (\array_key_exists('tags', $given)) {
            $this->writer->tag($course, $call->arguments->strings('tags'));
            $written[] = 'tags';
        }

        $course->touch();

        return $written;
    }

    /**
     * The JSON Schema of the card's fields, shared by the two tools.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return [
            'title' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Titre du cours.'],
            'slug' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Fin de l\'adresse publique (lettres minuscules, chiffres, tirets). Facultatif : tirée du titre sinon. Figée à la première publication.'],
            'summary' => ['type' => 'string', 'maxLength' => 300, 'description' => 'Une ou deux phrases : ce que montre la carte du cours sur la page publique.'],
            'description' => ['type' => 'string', 'description' => 'Présentation du cours, en Markdown (prérequis, objectifs, plan).'],
            'estimatedMinutes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6000, 'description' => 'Durée estimée, en minutes.'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 100], 'maxItems' => 12, 'description' => 'Les tags du cours (remplacent les précédents). Réutilise ceux de course_tag_list.'],
        ];
    }
}
