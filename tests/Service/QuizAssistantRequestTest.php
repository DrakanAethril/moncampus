<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\QuizAssistantRequest;
use PHPUnit\Framework\TestCase;

/**
 * What the teacher types at step 1 when the questions come from a subject rather than from a course.
 *
 * Designed on primitives: the object never sees a Request, a session or a form, so the rules below
 * are the whole specification of « Ma demande » and can be read without booting anything.
 */
class QuizAssistantRequestTest extends TestCase
{
    public function testEverythingBlankIsAnEmptyRequest(): void
    {
        $request = QuizAssistantRequest::fromArray([]);

        self::assertTrue($request->isEmpty());
        self::assertSame('', $request->subjectMatter);
        self::assertNull($request->questionCount);
    }

    public function testFieldsAreTrimmed(): void
    {
        $request = QuizAssistantRequest::fromArray([
            'subjectMatter' => '  Réseaux  ',
            'notions' => "VLAN, trunk\n",
            'audience' => ' BTS SIO 2e année ',
            'extra' => '  Éviter les questions de cours par cœur. ',
        ]);

        self::assertSame('Réseaux', $request->subjectMatter);
        self::assertSame('VLAN, trunk', $request->notions);
        self::assertSame('BTS SIO 2e année', $request->audience);
        self::assertSame('Éviter les questions de cours par cœur.', $request->extra);
        self::assertFalse($request->isEmpty());
    }

    // A count is a number of questions to write, so zero and negatives are not "a small quiz" - they
    // are the field left alone. Null is what the prompt reads as "keep the bracketed example".
    public function testAnUnusableCountReadsAsAbsent(): void
    {
        foreach (['', '0', '-4', 'douze', 'abc'] as $raw) {
            self::assertNull(QuizAssistantRequest::fromArray(['questionCount' => $raw])->questionCount, sprintf('count %s', var_export($raw, true)));
        }
    }

    public function testACountIsReadAsAnInteger(): void
    {
        self::assertSame(15, QuizAssistantRequest::fromArray(['questionCount' => '15'])->questionCount);
        self::assertSame(15, QuizAssistantRequest::fromArray(['questionCount' => 15])->questionCount);
    }

    // The cap exists because the count travels into a prompt: a teacher who types 100000 is asking a
    // model for something it will not do, and the screen should not carry the request that far.
    public function testACountIsCappedRatherThanRefused(): void
    {
        self::assertSame(QuizAssistantRequest::MAX_QUESTION_COUNT, QuizAssistantRequest::fromArray(['questionCount' => '9999'])->questionCount);
    }

    // A count alone is a real request: it is the one field that every step-1 path may carry, course
    // included, so it must not be swallowed by "nothing was typed".
    public function testACountAloneIsNotEmpty(): void
    {
        self::assertFalse(QuizAssistantRequest::fromArray(['questionCount' => '15'])->isEmpty());
    }

    public function testItSurvivesARoundTripThroughTheSession(): void
    {
        $request = QuizAssistantRequest::fromArray([
            'subjectMatter' => 'Réseaux',
            'notions' => 'VLAN, trunk, 802.1Q',
            'audience' => 'BTS SIO 2e année, SISR',
            'questionCount' => '15',
            'extra' => 'Deux questions de calcul de masque.',
        ]);

        self::assertEquals($request, QuizAssistantRequest::fromArray($request->toArray()));
    }

    // Session content is anything: a resumed assistant must not fatal on a value nobody typed.
    public function testItIgnoresValuesThatAreNotScalars(): void
    {
        $request = QuizAssistantRequest::fromArray([
            'subjectMatter' => ['Réseaux'],
            'notions' => null,
            'questionCount' => ['15'],
        ]);

        self::assertTrue($request->isEmpty());
    }
}
