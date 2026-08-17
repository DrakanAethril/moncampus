<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\AudioRecording;
use App\Entity\FileLibraryNode;
use App\Entity\LessonSession;
use App\Entity\Option;
use App\Entity\Program;
use App\Entity\Topic;
use App\Entity\VideoResource;
use App\Enum\AssignmentAudienceType;
use App\Enum\LessonLogSection;

/**
 * The entry context of the « Nouveau travail » wizard (design_handoff_creation_travail 2a).
 *
 * The wizard is a reusable component, opened from several places in the platform: the Travaux page
 * (generic context, class still to choose), a séance of the cahier de texte (class and options
 * pre-filled), tomorrow another screen. What changes from one entry point to the next lives entirely
 * here, and the attachment - séance, part of the séance, matière - is inferred from it without ever
 * being asked of the teacher.
 *
 * $mode does not say what the wizard does but where it is displayed: full page, modal or side panel.
 * The template uses it to pick its wrapper; the form is the same in all three cases.
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
        public readonly ?VideoResource $videoResource = null,
        public readonly ?FileLibraryNode $libraryNode = null,
    ) {
    }

    /** Generic entry point: no class known, step 1 has it chosen. */
    public static function generic(string $returnUrl, string $mode = self::MODE_PAGE): self
    {
        return new self(null, [], AssignmentAudienceType::Program, null, null, null, $returnUrl, $mode);
    }

    /**
     * From a class, with no séance: the class is known, the rest is chosen. Also serves as the base
     * for any « I already know which class we are talking about » entry point.
     */
    public static function forProgram(Program $program, string $returnUrl, string $mode = self::MODE_PAGE, ?Topic $topic = null): self
    {
        return new self($program, [], AssignmentAudienceType::Program, null, null, $topic, $returnUrl, $mode);
    }

    /**
     * From a séance of the cahier de texte: the séance's class and options pre-filled, « by option »
     * targeting when the séance carries any, and the séance/part/matière attachment set outright.
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
     * From a video resource, the exact counterpart of forAudioRecording() above: the class and the
     * targeted options come from the video, and the nature is no longer a choice - it is a watching.
     */
    public static function forVideoResource(VideoResource $resource, string $returnUrl, string $mode = self::MODE_PAGE): self
    {
        $options = $resource->getOptions()->toArray();

        return new self(
            $resource->getProgram(),
            array_values($options),
            [] === $options ? AssignmentAudienceType::Program : AssignmentAudienceType::Option,
            null,
            null,
            null,
            $returnUrl,
            $mode,
            null,
            $resource,
        );
    }

    /**
     * From a file of the teacher's library (design/validated/file-library.md, "Create a work from a
     * file"): the wizard opens with the file **already attached**, and the class is still to choose -
     * a library is personal and belongs to no class, which is the one thing this entry point cannot
     * infer.
     *
     * The nature follows the file rather than being asked: a video is a *watching*, an audio file a
     * *listening*, anything else a *to submit*. That is how the two media natures keep the only entry
     * points they have ever had, now that the Vidéos tool has lost its front door.
     */
    public static function forLibraryNode(FileLibraryNode $node, string $returnUrl, string $mode = self::MODE_PAGE): self
    {
        return new self(
            null,
            [],
            AssignmentAudienceType::Program,
            null,
            null,
            null,
            $returnUrl,
            $mode,
            null,
            null,
            $node,
        );
    }

    /**
     * The context of an assignment already given, to reopen the wizard on it: its entry point is the
     * one it came from, as frozen at its creation.
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
            $assignment->getVideoResource(),
        );
    }

    public function isEmbedded(): bool
    {
        return self::MODE_PAGE !== $this->mode;
    }
}
