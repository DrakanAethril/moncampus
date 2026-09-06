<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Enum\WordCloudRefusal;

/**
 * Accepts or refuses one submitted word, and says why.
 *
 * Every rule here is server-side by design. The student's form knows the same limits and enforces
 * none of them: it is a convenience, and a cloud whose period, quota and duplicate rule live only
 * in a browser is a cloud a curl command can fill.
 */
class WordCloudSubmissionPolicy
{
    /**
     * @param string $text the raw text, as typed
     * @param string $key  its aggregation key, from WordCloudAggregator::key()
     *
     * @return WordCloudRefusal|null null when the word is taken
     */
    public function refusalFor(string $text, string $key, WordCloudSubmissionContext $context): ?WordCloudRefusal
    {
        if (!$context->open) {
            return WordCloudRefusal::Closed;
        }

        $trimmed = trim($text);

        // Nothing written, or nothing but punctuation - either way there is no key to group on and
        // therefore no word to count.
        if ('' === $trimmed || '' === $key) {
            return WordCloudRefusal::Empty;
        }

        if (mb_strlen($trimmed) > $context->wordLength::MAX_CHARACTERS) {
            return WordCloudRefusal::TooLong;
        }

        $maxWords = $context->wordLength->maxWords();
        if (null !== $maxWords && $this->wordCount($trimmed) > $maxWords) {
            return WordCloudRefusal::TooManyWords;
        }

        // Read before the quota: somebody at their limit who re-sends a word they already gave is
        // better told that than told they are out of turns, since the second answer sends them
        // away and the first sends them back to write something else.
        if (\in_array($key, $context->alreadyProposedKeys, true)) {
            return WordCloudRefusal::AlreadyProposed;
        }

        if (null !== $context->wordsPerStudent && $context->countedTowardsQuota >= $context->wordsPerStudent) {
            return WordCloudRefusal::QuotaReached;
        }

        return null;
    }

    /**
     * Words are separated by whitespace and by nothing else: « pare-feu » is one word, which is how
     * it reads on the board.
     */
    private function wordCount(string $text): int
    {
        return \count(preg_split('/\s+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
