<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\AudioRecording;
use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\Topic;
use App\Enum\AssignmentAudienceType;
use App\Enum\LessonLogSection;

/**
 * Le contexte d'entrée de l'assistant « Nouveau travail » (design_handoff_creation_travail 2a).
 *
 * L'assistant est un composant réutilisable, ouvert depuis plusieurs endroits de la plateforme :
 * la page Travaux (contexte générique, classe à choisir), une séance du cahier de texte (classe et
 * options préremplies), demain un autre écran. Ce qui change d'un point d'entrée à l'autre tient
 * entièrement ici, et le rattachement - séance, temps de la séance, matière - en est déduit sans
 * jamais être demandé à l'enseignant.
 *
 * $mode ne dit pas ce que fait l'assistant mais où il s'affiche : pleine page, modale ou panneau
 * latéral. Le gabarit s'en sert pour choisir son enveloppe, le formulaire est le même dans les
 * trois cas.
 */
final class AssignmentWizardContext
{
    public const MODE_PAGE = 'page';
    public const MODE_MODAL = 'modal';
    public const MODE_PANEL = 'panel';

    /** @param list<Option> $options */
    private function __construct(
        public readonly ?Program $program,
        public readonly array $options,
        public readonly AssignmentAudienceType $audienceType,
        public readonly ?LessonSession $lessonSession,
        public readonly ?LessonLogSection $lessonLogSection,
        public readonly ?Topic $topic,
        public readonly string $returnUrl,
        public readonly string $mode,
        public readonly ?AudioRecording $audioRecording = null,
    ) {
    }

    /** Point d'entrée générique : aucune classe connue, l'étape 1 la fait choisir. */
    public static function generic(string $returnUrl, string $mode = self::MODE_PAGE): self
    {
        return new self(null, [], AssignmentAudienceType::Program, null, null, null, $returnUrl, $mode);
    }

    /**
     * Depuis une classe, sans séance : la classe est connue, le reste se choisit. Sert aussi de
     * base à tout point d'entrée « je sais déjà de quelle classe on parle ».
     */
    public static function forProgram(Program $program, string $returnUrl, string $mode = self::MODE_PAGE, ?Topic $topic = null): self
    {
        return new self($program, [], AssignmentAudienceType::Program, null, null, $topic, $returnUrl, $mode);
    }

    /**
     * Depuis une séance du cahier de texte : classe et options de la séance préremplies, ciblage
     * « par option » quand la séance en porte, et rattachement séance/temps/matière posé d'office.
     */
    public static function forLessonSession(LessonSession $session, LessonLogSection $section, string $returnUrl, string $mode = self::MODE_PAGE): self
    {
        $options = $session->getOptions()->toArray();

        return new self(
            $session->getProgram(),
            array_values($options),
            [] === $options ? AssignmentAudienceType::Program : AssignmentAudienceType::Option,
            $session,
            $section,
            $session->getTopic(),
            $returnUrl,
            $mode,
        );
    }

    /**
     * From an audio recording (design_handoff_enregistrements_audio, step 2's "Créer un travail à
     * faire" button): the recording's class and targeted options are already known, and the nature
     * is no longer a choice - it is a listening, there is nothing else to make of it.
     */
    public static function forAudioRecording(AudioRecording $recording, string $returnUrl, string $mode = self::MODE_PAGE): self
    {
        $options = $recording->getOptions()->toArray();

        return new self(
            $recording->getProgram(),
            array_values($options),
            [] === $options ? AssignmentAudienceType::Program : AssignmentAudienceType::Option,
            null,
            null,
            null,
            $returnUrl,
            $mode,
            $recording,
        );
    }

    /**
     * Le contexte d'un travail déjà donné, pour rouvrir l'assistant dessus : son point d'entrée est
     * celui d'où il vient, tel qu'il a été figé à sa création.
     */
    public static function forAssignment(Assignment $assignment, string $returnUrl, string $mode = self::MODE_PAGE): self
    {
        return new self(
            $assignment->getProgram(),
            $assignment->getOptions()->toArray(),
            $assignment->getAudienceType() ?? AssignmentAudienceType::Program,
            $assignment->getLessonSession(),
            $assignment->getLessonLogSection(),
            $assignment->getTopic(),
            $returnUrl,
            $mode,
            $assignment->getAudioRecording(),
        );
    }

    public function isEmbedded(): bool
    {
        return self::MODE_PAGE !== $this->mode;
    }
}
