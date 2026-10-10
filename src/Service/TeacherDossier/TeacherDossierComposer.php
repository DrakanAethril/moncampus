<?php

declare(strict_types=1);

namespace App\Service\TeacherDossier;

use App\Entity\TeacherDossier;
use App\Entity\TeacherDossierDocument;
use App\Entity\TeacherDossierGroup;
use App\Entity\User;

/**
 * Copies a composition - the groups, the pieces and their rules - from a model to a dossier, from a
 * dossier to a model, or from a model to another.
 *
 * **What is copied is what somebody wrote, never what happened**: no dépôt, no review, no state.
 * And it is a copy, not a link: a dossier made from a model keeps the model's name for the record
 * (App\Entity\TeacherDossier::$sourceTemplate) and nothing else of it, so correcting the model next year
 * changes none of the dossiers already handed out, and each of them can be adjusted for its own
 * intervenant.
 */
class TeacherDossierComposer
{
    /**
     * One dossier per intervenant, each a brouillon.
     *
     * @param list<User> $teachers
     *
     * @return list<TeacherDossier>
     */
    public function fromTemplate(TeacherDossier $template, array $teachers, User $author, string $title, ?\DateTimeImmutable $dueOn): array
    {
        $dossiers = [];

        foreach ($teachers as $teacher) {
            $dossier = TeacherDossier::forTeacher($author, $teacher, $title);
            $dossier->setDescription($template->getDescription());
            $dossier->setSourceTemplate($template);
            $this->copyComposition($template, $dossier, $dueOn);

            $dossiers[] = $dossier;
        }

        return $dossiers;
    }

    /** « Enregistrer comme modèle », and « Dupliquer » on a model: the composition, with no date. */
    public function toTemplate(TeacherDossier $source, User $author, string $title): TeacherDossier
    {
        $template = TeacherDossier::asTemplate($author, $title);
        $template->setDescription($source->getDescription());
        $this->copyComposition($source, $template, null);

        return $template;
    }

    private function copyComposition(TeacherDossier $source, TeacherDossier $target, ?\DateTimeImmutable $dueOn): void
    {
        // Keyed on the object rather than on its id: a group not flushed yet has none, and null
        // keys would file every piece under the same group.
        /** @var array<int, TeacherDossierGroup> $groups */
        $groups = [];

        foreach ($source->getGroups() as $group) {
            $groups[spl_object_id($group)] = new TeacherDossierGroup($target, $group->getTitle(), $group->getPosition());
        }

        foreach ($source->getDocuments() as $document) {
            $sourceGroup = $document->getGroup();

            (new TeacherDossierDocument($target))
                ->setName($document->getName())
                ->setGroup(null === $sourceGroup ? null : ($groups[spl_object_id($sourceGroup)] ?? null))
                ->setRequired($document->isRequired())
                ->setDepositType($document->getDepositType())
                ->setValidationProfile($document->getValidationProfile())
                ->setDueOn($dueOn)
                ->setPosition($document->getPosition())
            ;
        }
    }
}
