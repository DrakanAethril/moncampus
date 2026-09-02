<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\QuizInstance;
use App\Enum\QuizMode;
use App\Enum\QuizSupervisionPolicy;
use App\Form\QuizInstanceEditType;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Validation;

/**
 * What « Modifier le quiz » lets a teacher change once the quiz is out.
 *
 * The interesting half is mode contrôle, which is editable here and only on an évaluation - the
 * mode itself having been frozen at launch. So what is pinned is the pair: the block is absent from
 * an entraînement's form rather than merely hidden, and a blank threshold falls back to the launch
 * form's own 8 seconds rather than reaching a NOT NULL column as null.
 */
// See FilePickerTypeTest: TypeTestCase builds an EventDispatcher mock of its own, and phpunit.dist.xml
// fails on the notice PHPUnit 13 raises about it.
#[AllowMockObjectsWithoutExpectations]
class QuizInstanceEditTypeTest extends TypeTestCase
{
    public function testAnEvaluationCarriesTheSupervisionBlock(): void
    {
        $form = $this->factory->create(QuizInstanceEditType::class, $this->instance(QuizMode::Evaluation), ['supervisionEditable' => true]);

        self::assertTrue($form->has('supervised'));
        self::assertTrue($form->has('supervisionPolicy'));
        self::assertTrue($form->has('supervisionExitSeconds'));
        self::assertTrue($form->has('supervisionSubmitAt'));
    }

    public function testAnEntrainementCarriesNoSupervisionFieldAtAll(): void
    {
        $form = $this->factory->create(QuizInstanceEditType::class, $this->instance(QuizMode::Entrainement));

        // Absent, not hidden: a field that cannot mean anything on an entraînement has no business
        // being submitted, and hiding one has never been a way of switching something off.
        self::assertFalse($form->has('supervised'));
        self::assertFalse($form->has('supervisionPolicy'));
    }

    public function testTheDrawIsNeverEditable(): void
    {
        $form = $this->factory->create(QuizInstanceEditType::class, $this->instance(QuizMode::Evaluation), ['supervisionEditable' => true]);

        foreach (['mode', 'questionCount', 'sameQuestionsForAll', 'questionOrderPerStudent', 'answerOrderPerStudent'] as $frozen) {
            self::assertFalse($form->has($frozen), sprintf('"%s" was resolved into questions at launch and must stay frozen.', $frozen));
        }
    }

    public function testSupervisionIsTurnedOnBySubmittingTheBlock(): void
    {
        $instance = $this->instance(QuizMode::Evaluation);
        $form = $this->factory->create(QuizInstanceEditType::class, $instance, ['supervisionEditable' => true]);

        $form->submit($this->payload([
            'supervised' => '1',
            'supervisionPolicy' => QuizSupervisionPolicy::Autosubmit->value,
            'supervisionExitSeconds' => '15',
            'supervisionSubmitAt' => '4',
        ]));

        self::assertTrue($form->isSynchronized());
        self::assertTrue($instance->isSupervised());
        self::assertSame(QuizSupervisionPolicy::Autosubmit, $instance->getSupervisionPolicy());
        self::assertSame(15, $instance->getSupervisionExitSeconds());
        self::assertSame(4, $instance->getSupervisionSubmitAt());
    }

    public function testABlankThresholdFallsBackRatherThanReachingTheColumnAsNull(): void
    {
        $instance = $this->instance(QuizMode::Evaluation);
        $form = $this->factory->create(QuizInstanceEditType::class, $instance, ['supervisionEditable' => true]);

        $form->submit($this->payload(['supervised' => '1', 'supervisionExitSeconds' => '']));

        self::assertTrue($form->isSynchronized());
        self::assertSame(8, $instance->getSupervisionExitSeconds());
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string|null>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Évaluation de fin de séquence',
            'opensAt' => '',
            'closesAt' => '',
            'secondsPerQuestion' => '30',
            'globalTimeMinutes' => '',
            'scoring' => 'note20',
            'supervisionPolicy' => QuizSupervisionPolicy::Warn->value,
            'supervisionExitSeconds' => '8',
            'supervisionSubmitAt' => '',
        ], $overrides);
    }

    /**
     * Built the way Doctrine builds one - without the constructor, which asks for a Program and a
     * User this test has no database to hang off. Only the fields the form touches matter here.
     */
    private function instance(QuizMode $mode): QuizInstance
    {
        $instance = (new \ReflectionClass(QuizInstance::class))->newInstanceWithoutConstructor();

        return $instance->setName('Quiz 1')->setMode($mode);
    }

    protected function getExtensions(): array
    {
        // The supervision fields carry Range constraints, and the "constraints" option itself is
        // defined by this extension.
        return [new ValidatorExtension(Validation::createValidator())];
    }
}
