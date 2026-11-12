<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\Topic;
use App\Entity\TopicGroup;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;
use App\Service\SchoolYearImport\SchoolYearImportRules;

/**
 * « Matières et groupes de matières »: groups and topics recognised by their name (case and spaces
 * ignored); a topic already there keeps its volumes. What is created carries its volumes,
 * coefficient, description and the group's options the formation has - and, with « Titulaires »,
 * the holders who are members of the target team (R6). A topic that had holders and arrives with
 * none is counted.
 */
final readonly class TopicCopier implements ProgramCopier
{
    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        if (!$run->has(SchoolYearImportBlock::Topics)) {
            return;
        }

        $target = $pair->target;
        $write = $run->writable($pair);
        $holders = $run->has(SchoolYearImportBlock::Holders);

        $groups = [];
        foreach ($run->data->rowsOf(TopicGroup::class, $target) as $group) {
            if (null === $group->getInactiveDate()) {
                $groups[SchoolYearImportRules::key($group->getName())] ??= $group;
            }
        }
        $existingTopics = [];
        foreach ($run->data->rowsOf(Topic::class, $target) as $topic) {
            if (null === $topic->getInactiveDate()) {
                $existingTopics[SchoolYearImportRules::key($topic->getName())] = true;
            }
        }

        /** @var array<int, TopicGroup|null> $groupMap source group id => target group (null in a dry run for a new one) */
        $groupMap = [];
        $ensureGroup = function (TopicGroup $source) use ($run, $pair, $write, $holders, &$groups, &$groupMap): ?TopicGroup {
            $sourceId = (int) $source->getId();
            if (\array_key_exists($sourceId, $groupMap)) {
                return $groupMap[$sourceId];
            }
            $key = SchoolYearImportRules::key($source->getName());
            if (\array_key_exists($key, $groups)) {
                return $groupMap[$sourceId] = $groups[$key];
            }

            $run->report->add($pair, 'topicGroups');
            $copy = null;
            if (null !== $write) {
                $copy = new TopicGroup($source->getName(), $write);
                foreach ($source->getOptions() as $option) {
                    if ($pair->hasOption($option)) {
                        $copy->addOption($option);
                    }
                }
                if ($holders && $pair->inTeam($source->getTeacher())) {
                    $copy->setTeacher($source->getTeacher());
                }
                $run->persist($copy);
            }

            return $groupMap[$sourceId] = $groups[$key] = $copy;
        };

        foreach ($run->data->rowsOf(TopicGroup::class, $pair->source, ['options']) as $group) {
            if (null === $group->getInactiveDate()) {
                $ensureGroup($group);
            }
        }

        foreach ($run->data->rowsOf(Topic::class, $pair->source, ['teachers']) as $topic) {
            $key = SchoolYearImportRules::key($topic->getName());
            if (null !== $topic->getInactiveDate() || isset($existingTopics[$key])) {
                continue;
            }
            $existingTopics[$key] = true;
            $sourceGroup = $topic->getTopicGroup();
            $group = null !== $sourceGroup ? $ensureGroup($sourceGroup) : null;
            $run->report->add($pair, 'topics');

            $kept = $holders ? array_values(array_filter($topic->getTeachers()->toArray(), static fn ($teacher): bool => $pair->inTeam($teacher))) : [];
            if ($topic->hasTeachers() && [] === $kept) {
                $run->report->withoutHolder($pair);
            }
            if (null === $write) {
                continue;
            }

            $copy = new Topic($topic->getName(), $write, $group);
            $copy->setTargetCmHours($topic->getTargetCmHours());
            $copy->setTargetTdHours($topic->getTargetTdHours());
            $copy->setTargetTpHours($topic->getTargetTpHours());
            $copy->setCoefficient($topic->getCoefficient());
            $copy->setDescription($topic->getDescription());
            foreach ($kept as $teacher) {
                $copy->addTeacher($teacher);
            }
            $run->persist($copy);
        }
    }
}
