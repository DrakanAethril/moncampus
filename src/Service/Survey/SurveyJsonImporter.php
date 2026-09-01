<?php

declare(strict_types=1);

namespace App\Service\Survey;

use App\Entity\SurveyAnswer;
use App\Entity\SurveyQuestion;
use App\Entity\SurveyTemplate;
use App\Enum\SurveyQuestionType;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The « moncampus-sondage/1 » format: a whole questionnaire in one JSON document, written to be
 * produced by a language model from the prompt the assistant hands over
 * (App\Service\Survey\SurveyPromptCatalog).
 *
 * One reader and one format, where the quiz side has six behind a registry
 * (App\Service\InteractiveQuizImporterRegistry). That is not a simplification for its own sake: the
 * quiz families exist because a zone or an appariement carries a support and a geometry that nothing
 * else does, while the five survey types differ only by whether they carry proposed answers. A
 * registry here would be an abstraction over a single implementation.
 *
 * **Nothing is written by this class.** parse() produces a payload the session carries to the
 * verification screen, and appendQuestions() turns it into entities the *caller* flushes - which is
 * what lets the preview build the very same objects from the very same payload without leaving a
 * row behind.
 *
 * A bad question is reported and skipped, never fatal: one line a model wrote badly must not cost
 * the author the twelve others. Only a document that is unusable as a whole raises
 * App\Service\Survey\SurveyImportException.
 *
 * @phpstan-type SurveyImportQuestion array{
 *     type: string,
 *     label: string,
 *     help: ?string,
 *     required: bool,
 *     scale: bool,
 *     minChoices: ?int,
 *     maxChoices: ?int,
 *     answers: list<string>,
 * }
 * @phpstan-type SurveyImportPayload array{
 *     format: string,
 *     name: string,
 *     subject: ?string,
 *     description: ?string,
 *     fileName: string,
 *     questions: list<SurveyImportQuestion>,
 *     errors: list<string>,
 * }
 */
final class SurveyJsonImporter
{
    public const string FORMAT = 'moncampus-sondage/1';

    /** The tag stamped on the session payload, which the verification screen reads back. */
    public const string PAYLOAD_FORMAT = 'sondage';

    /**
     * A ceiling on what one document may carry. Far lower than the quiz's 500: a survey is answered
     * in one sitting by people who owe nobody an answer, and a hundred questions is already past
     * anything that gets finished. It is a guard against a malformed document, not a target.
     */
    public const int MAX_QUESTIONS = 100;

    /** Column length of `survey_answer.label`; a longer proposition is cut rather than refused. */
    private const int ANSWER_MAX_LENGTH = 500;

    /**
     * The words a model reaches for when it does not use the enum's own value.
     *
     * Tolerated on the way *in* only: what is stored is always the enum. « échelle » is the case
     * worth having - there is deliberately no Échelle type (an ordered scale is a Unique question
     * carrying `is_scale`), and a document that names one would otherwise lose every scale question
     * it wrote.
     *
     * @var array<string, array{0: SurveyQuestionType, 1: bool}> alias => [type, forces the scale flag]
     */
    private const array ALIASES = [
        'choix_unique' => [SurveyQuestionType::Unique, false],
        'unique_choice' => [SurveyQuestionType::Unique, false],
        'radio' => [SurveyQuestionType::Unique, false],
        'echelle' => [SurveyQuestionType::Unique, true],
        'echelle_likert' => [SurveyQuestionType::Unique, true],
        'likert' => [SurveyQuestionType::Unique, true],
        'choix_multiple' => [SurveyQuestionType::Multiple, false],
        'cases' => [SurveyQuestionType::Multiple, false],
        'checkbox' => [SurveyQuestionType::Multiple, false],
        'classement' => [SurveyQuestionType::Ordre, false],
        'ordonner' => [SurveyQuestionType::Ordre, false],
        'texte' => [SurveyQuestionType::Commentaire, false],
        'texte_libre' => [SurveyQuestionType::Commentaire, false],
        'libre' => [SurveyQuestionType::Commentaire, false],
        'ouverte' => [SurveyQuestionType::Commentaire, false],
        'section' => [SurveyQuestionType::Titre, false],
        'intertitre' => [SurveyQuestionType::Titre, false],
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @return SurveyImportPayload
     *
     * @throws SurveyImportException when the document as a whole is unusable
     */
    public function parse(string $json, string $fileName = 'import.json'): array
    {
        try {
            $document = json_decode($this->unwrap($json), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SurveyImportException('surveyImportInvalidJsonError');
        }

        if (!\is_array($document)) {
            throw new SurveyImportException('surveyImportInvalidJsonError');
        }

        if (self::FORMAT !== ($document['format'] ?? null)) {
            throw new SurveyImportException('surveyImportWrongFormatError', ['%format%' => self::FORMAT]);
        }

        $rawQuestions = \is_array($document['questions'] ?? null) ? array_values($document['questions']) : [];
        if ([] === $rawQuestions) {
            throw new SurveyImportException('surveyImportNoQuestionError');
        }
        if (\count($rawQuestions) > self::MAX_QUESTIONS) {
            throw new SurveyImportException('surveyImportTooManyQuestionsError', ['%max%' => self::MAX_QUESTIONS]);
        }

        // « survey » is what the prompt asks for; « template » is what a model that has seen the quiz
        // format writes. Both name the same three fields, so reading either costs one line here and
        // saves the author a document to hand-edit.
        $head = \is_array($document['survey'] ?? null) ? $document['survey'] : (\is_array($document['template'] ?? null) ? $document['template'] : []);

        $questions = [];
        $errors = [];
        foreach ($rawQuestions as $index => $raw) {
            try {
                $questions[] = $this->parseQuestion(\is_array($raw) ? $raw : []);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $this->translator->trans($exception->getMessage(), ['%number%' => $index + 1]);
            }
        }

        if ([] === $questions) {
            // Every single question was refused: there is nothing to verify, and a verification
            // screen listing twelve errors and no question is a dead end rather than an answer.
            throw new SurveyImportException('surveyImportNoUsableQuestionError');
        }

        return [
            'format' => self::PAYLOAD_FORMAT,
            'name' => $this->stringOf($head['name'] ?? null) ?? $this->translator->trans('surveyImportDefaultTemplateName'),
            'subject' => $this->stringOf($head['subject'] ?? null),
            'description' => $this->stringOf($head['description'] ?? null),
            'fileName' => $fileName,
            'questions' => $questions,
            'errors' => $errors,
        ];
    }

    /**
     * Turns a payload's questions into rows of the given model, appended after what it already
     * holds.
     *
     * Appending rather than replacing is what makes « ajouter à un sondage existant » a destination
     * and not a second code path: a questionnaire is built in several goes, exactly like a quiz
     * bank. The caller flushes - the verification screen persists, the preview throws the objects
     * away.
     *
     * @param array<array-key, mixed> $questions the payload's questions, back out of the session
     */
    public function appendQuestions(SurveyTemplate $template, array $questions): void
    {
        $orderIndex = 0;
        foreach ($template->getQuestions() as $existing) {
            $orderIndex = max($orderIndex, $existing->getOrderIndex() + 1);
        }

        foreach (array_values($questions) as $raw) {
            if (!\is_array($raw)) {
                continue;
            }

            $type = SurveyQuestionType::tryFrom($this->stringOf($raw['type'] ?? null) ?? '');
            if (null === $type) {
                continue;
            }

            $question = new SurveyQuestion($template);
            $question->setType($type);
            $question->setLabel($this->stringOf($raw['label'] ?? null) ?? '');
            $question->setHelpText($this->stringOf($raw['help'] ?? null));
            $question->setOrderIndex($orderIndex++);
            $question->setRequired((bool) ($raw['required'] ?? true));
            $question->setIsScale($type->supportsScale() && (bool) ($raw['scale'] ?? false));

            if ($type->supportsChoiceBounds()) {
                $question->setMinChoices($this->intOf($raw['minChoices'] ?? null));
                $question->setMaxChoices($this->intOf($raw['maxChoices'] ?? null));
            }

            if ($type->hasAnswers()) {
                $answers = \is_array($raw['answers'] ?? null) ? array_values($raw['answers']) : [];
                foreach ($answers as $position => $label) {
                    $answer = new SurveyAnswer($question);
                    $answer->setLabel(mb_substr(\is_scalar($label) ? trim((string) $label) : '', 0, self::ANSWER_MAX_LENGTH));
                    $answer->setOrderIndex($position);
                    $question->addAnswer($answer);
                }
            }

            $template->addQuestion($question);
        }
    }

    /** A worked specimen of the format, offered on the paste screen the way the quiz examples are. */
    public function exampleJson(): string
    {
        return <<<'JSON'
            {
              "format": "moncampus-sondage/1",
              "survey": {
                "name": "Satisfaction — 1er semestre",
                "subject": "BTS SIO 2",
                "description": "Cinq minutes pour dire ce qui marche et ce qui bloque. Les réponses ne sont pas notées."
              },
              "questions": [
                {"type": "titre", "label": "Les cours"},
                {
                  "type": "unique",
                  "label": "Le rythme des cours vous convient-il ?",
                  "scale": true,
                  "required": true,
                  "answers": ["Beaucoup trop lent", "Un peu lent", "Adapté", "Un peu rapide", "Beaucoup trop rapide"]
                },
                {
                  "type": "multiple",
                  "label": "Quelles matières demandent le plus de travail personnel ?",
                  "help": "Trois réponses au maximum.",
                  "maxChoices": 3,
                  "required": false,
                  "answers": ["Réseaux", "Développement", "Base de données", "Cybersécurité", "Anglais", "Économie-droit"]
                },
                {"type": "titre", "label": "L'organisation"},
                {
                  "type": "ordre",
                  "label": "Classez ces sujets du plus urgent au moins urgent.",
                  "required": true,
                  "answers": ["Le matériel des salles", "Les créneaux d'alternance", "Le suivi de stage", "Les supports de cours"]
                },
                {
                  "type": "commentaire",
                  "label": "Quelque chose à ajouter ?",
                  "required": false
                }
              ]
            }
            JSON;
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return SurveyImportQuestion
     *
     * @throws \InvalidArgumentException carrying the translation key of what this question lacks
     */
    private function parseQuestion(array $raw): array
    {
        [$type, $forcedScale] = $this->typeOf($this->stringOf($raw['type'] ?? null) ?? '');

        $label = $this->stringOf($raw['label'] ?? null)
            // « statement », « text », « question »: the same field under the name a model reached for.
            ?? $this->stringOf($raw['question'] ?? null)
            ?? throw new \InvalidArgumentException('surveyImportQuestionMissingLabelError');

        $answers = [];
        if ($type->hasAnswers()) {
            $raws = \is_array($raw['answers'] ?? null) ? array_values($raw['answers']) : [];
            foreach ($raws as $one) {
                $one = $this->stringOf($one);
                if (null !== $one) {
                    $answers[] = mb_substr($one, 0, self::ANSWER_MAX_LENGTH);
                }
            }

            // One proposition is not a choice, and none is a question nobody can answer. Refused
            // rather than repaired: what the missing propositions were is not something to guess.
            if (\count($answers) < 2) {
                throw new \InvalidArgumentException('surveyImportQuestionMissingAnswersError');
            }
        }

        $min = $type->supportsChoiceBounds() ? $this->intOf($raw['minChoices'] ?? null) : null;
        $max = $type->supportsChoiceBounds() ? $this->intOf($raw['maxChoices'] ?? null) : null;
        // A bound past the number of propositions is a bound that can never be met - the answer
        // screen would refuse every submission. Read down to what the question actually offers.
        $min = null === $min ? null : max(1, min($min, \count($answers)));
        $max = null === $max ? null : max($min ?? 1, min($max, \count($answers)));

        return [
            'type' => $type->value,
            'label' => $label,
            'help' => $this->stringOf($raw['help'] ?? null) ?? $this->stringOf($raw['helpText'] ?? null),
            // An intertitle expects nothing, so « obligatoire » has no meaning on it - the enum says
            // so (SurveyQuestionType::isAnswerable) and the payload does not contradict it.
            'required' => $type->isAnswerable() && (bool) ($raw['required'] ?? true),
            'scale' => $type->supportsScale() && ($forcedScale || (bool) ($raw['scale'] ?? $raw['isScale'] ?? false)),
            'minChoices' => $min,
            'maxChoices' => $max,
            'answers' => $answers,
        ];
    }

    /**
     * @return array{0: SurveyQuestionType, 1: bool}
     *
     * @throws \InvalidArgumentException when the word names no type this application has
     */
    private function typeOf(string $raw): array
    {
        $key = strtolower(trim($raw));
        $type = SurveyQuestionType::tryFrom($key);
        if (null !== $type) {
            return [$type, false];
        }

        return self::ALIASES[$key] ?? throw new \InvalidArgumentException('surveyImportQuestionUnknownTypeError');
    }

    /**
     * Strips what a chat window adds around the document: a ```json fence, and any prose before the
     * first brace or after the last one.
     *
     * Copying a model's answer picks up its fence far more often than not, and « ce n'est pas du
     * JSON » in front of a document the author can see is JSON is the kind of refusal that sends
     * them back into the conversation for nothing.
     */
    private function unwrap(string $json): string
    {
        $trimmed = trim($json);
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');

        return false !== $start && false !== $end && $end > $start
            ? substr($trimmed, $start, $end - $start + 1)
            : $trimmed;
    }

    private function stringOf(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return '' === $text ? null : $text;
    }

    private function intOf(mixed $value): ?int
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }
}
