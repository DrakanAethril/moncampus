<?php

declare(strict_types=1);

namespace App\Tests\Service\WordCloud;

use App\Enum\WordCloudRefusal;
use App\Enum\WordCloudWordLength;
use App\Service\WordCloud\WordCloudSubmissionContext;
use App\Service\WordCloud\WordCloudSubmissionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Whether one word is taken, and when it is not, why.
 *
 * These are the rules a student's browser cannot be trusted with: the period, the quota and the
 * duplicate are all decided here, on the server, whatever the form on the other side chose to show.
 */
class WordCloudSubmissionPolicyTest extends TestCase
{
    public function testAnOrdinaryWordIsTaken(): void
    {
        self::assertNull($this->policy()->refusalFor('pare-feu', 'pare feu', $this->context()));
    }

    public function testAClosedCloudRefusesEverything(): void
    {
        self::assertSame(
            WordCloudRefusal::Closed,
            $this->policy()->refusalFor('pare-feu', 'pare feu', $this->context(open: false)),
        );
    }

    public function testABlankSubmissionIsRefused(): void
    {
        self::assertSame(WordCloudRefusal::Empty, $this->policy()->refusalFor('   ', '', $this->context()));
    }

    /** Punctuation alone leaves no key to group on, so there is no word to count. */
    public function testASubmissionThatNormalisesToNothingIsRefused(): void
    {
        self::assertSame(WordCloudRefusal::Empty, $this->policy()->refusalFor('!!!', '', $this->context()));
    }

    public function testThirtyCharactersIsTheCeilingOnEveryLengthSetting(): void
    {
        $thirty = str_repeat('a', 30);
        $thirtyOne = str_repeat('a', 31);

        foreach (WordCloudWordLength::cases() as $length) {
            self::assertNull($this->policy()->refusalFor($thirty, $thirty, $this->context(wordLength: $length)));
            self::assertSame(
                WordCloudRefusal::TooLong,
                $this->policy()->refusalFor($thirtyOne, $thirtyOne, $this->context(wordLength: $length)),
            );
        }
    }

    public function testOneWordMeansOneWord(): void
    {
        $context = $this->context(wordLength: WordCloudWordLength::OneWord);

        self::assertNull($this->policy()->refusalFor('phishing', 'phishing', $context));
        self::assertSame(WordCloudRefusal::TooManyWords, $this->policy()->refusalFor('mot de passe', 'mot de passe', $context));
    }

    /** A hyphen does not make two words: « pare-feu » is one, and reads as one on the board. */
    public function testAHyphenatedWordCountsAsOne(): void
    {
        self::assertNull($this->policy()->refusalFor('pare-feu', 'pare feu', $this->context(wordLength: WordCloudWordLength::OneWord)));
    }

    public function testUpToThreeWordsTakesThreeAndRefusesFour(): void
    {
        $context = $this->context(wordLength: WordCloudWordLength::UpToThreeWords);

        self::assertNull($this->policy()->refusalFor('mot de passe', 'mot de passe', $context));
        self::assertSame(WordCloudRefusal::TooManyWords, $this->policy()->refusalFor('un mot de passe', 'un mot de passe', $context));
    }

    public function testFreeExpressionCountsNoWordsAtAll(): void
    {
        $context = $this->context(wordLength: WordCloudWordLength::FreeExpression);

        self::assertNull($this->policy()->refusalFor('un mot de passe long', 'un mot de passe long', $context));
    }

    public function testTheSameStudentCannotProposeTheSameWordTwice(): void
    {
        $context = $this->context(alreadyProposedKeys: ['pare feu']);

        self::assertSame(WordCloudRefusal::AlreadyProposed, $this->policy()->refusalFor('Pare-Feu', 'pare feu', $context));
    }

    /** A refused word stays proposed: sending it again is what the teacher already said no to. */
    public function testAWordTheTeacherRefusedCannotBeSentBack(): void
    {
        $context = $this->context(alreadyProposedKeys: ['darkweb'], countedTowardsQuota: 0);

        self::assertSame(WordCloudRefusal::AlreadyProposed, $this->policy()->refusalFor('darkweb', 'darkweb', $context));
    }

    public function testTheQuotaStopsAStudentAtTheirLastWord(): void
    {
        self::assertNull($this->policy()->refusalFor('vpn', 'vpn', $this->context(wordsPerStudent: 3, countedTowardsQuota: 2)));
        self::assertSame(
            WordCloudRefusal::QuotaReached,
            $this->policy()->refusalFor('vpn', 'vpn', $this->context(wordsPerStudent: 3, countedTowardsQuota: 3)),
        );
    }

    public function testUnlimitedMeansUnlimited(): void
    {
        self::assertNull($this->policy()->refusalFor('vpn', 'vpn', $this->context(wordsPerStudent: null, countedTowardsQuota: 99)));
    }

    /**
     * The order the refusals are read in: telling somebody at their quota that they have already
     * proposed this exact word is the more useful of the two answers, and the one that tells them
     * to write something else.
     */
    public function testADuplicateIsAnnouncedBeforeTheQuota(): void
    {
        $context = $this->context(wordsPerStudent: 1, alreadyProposedKeys: ['vpn'], countedTowardsQuota: 1);

        self::assertSame(WordCloudRefusal::AlreadyProposed, $this->policy()->refusalFor('VPN', 'vpn', $context));
    }

    /**
     * @param list<string> $alreadyProposedKeys
     */
    private function context(
        bool $open = true,
        WordCloudWordLength $wordLength = WordCloudWordLength::FreeExpression,
        ?int $wordsPerStudent = null,
        array $alreadyProposedKeys = [],
        int $countedTowardsQuota = 0,
    ): WordCloudSubmissionContext {
        return new WordCloudSubmissionContext($open, $wordLength, $wordsPerStudent, $alreadyProposedKeys, $countedTowardsQuota);
    }

    private function policy(): WordCloudSubmissionPolicy
    {
        return new WordCloudSubmissionPolicy();
    }
}
