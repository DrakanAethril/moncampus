<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The platform's words on the live contest's screens (assets/quiz_live/render.js), in the reader's
 * language - one list for the room's screen, the phone and the theme gallery, which draws both. The
 * theme's own words (« Bien vu ! », « Poisson d'avril ! ») are not here: they are the theme's
 * (LiveTheme::$words).
 */
final class LiveScreenLabels
{
    private const array KEYS = [
        'question' => 'quizLiveScreenQuestion',
        'scanHint' => 'quizLiveScreenScanHint',
        'classJoinTitle' => 'quizLiveScreenClassJoinTitle',
        'classJoinHint' => 'quizLiveScreenClassJoinHint',
        'removeHint' => 'quizLiveScreenRemoveHint',
        'removeConfirm' => 'quizLiveScreenRemoveConfirm',
        'start' => 'quizLiveScreenStart',
        'cancel' => 'quizLiveScreenCancel',
        'showAnswers' => 'quizLiveShowAnswersAction',
        'skip' => 'quizLiveScreenSkip',
        'answers' => 'quizLiveScreenAnswers',
        'next' => 'quizLiveScreenNext',
        'scoreboard' => 'quizLiveScreenScoreboard',
        'podium' => 'quizLiveScreenPodium',
        'podiumTitle' => 'quizLiveScreenPodium',
        'expected' => 'quizLiveExpectedAnswerLabel',
        'correctCount' => 'quizLiveCorrectCountTemplate',
        'ranking' => 'quizLiveScreenRanking',
        'results' => 'quizLiveScreenResults',
        'guestNote' => 'quizLiveScreenGuestNote',
        'accountNote' => 'quizLiveScreenAccountNote',
        'inTheRoom' => 'quizLiveScreenInTheRoom',
        'lookAtBoard' => 'quizLiveScreenLookAtBoard',
        'playersInRoom' => 'quizLiveScreenPlayersInRoom',
        'answersComing' => 'quizLiveScreenAnswersComing',
        'tickAll' => 'quizLiveScreenTickAll',
        'tapInOrder' => 'quizLiveScreenTapInOrder',
        'numberField' => 'quizLiveScreenNumberField',
        'textField' => 'quizLiveScreenTextField',
        'textHint' => 'quizLiveScreenTextHint',
        'clear' => 'quizLiveScreenClear',
        'validate' => 'quizLiveScreenValidate',
        'retry' => 'quizLiveScreenRetry',
        'waitingOthers' => 'quizLiveScreenWaitingOthers',
        'outOf' => 'quizLiveScreenOutOf',
        'sameVote' => 'quizLiveScreenSameVote',
        'voteNoPoints' => 'quizLiveScreenVoteNoPoints',
        'streak' => 'quizLiveScreenStreak',
        'combo' => 'quizLiveScreenCombo',
        'rightAnswer' => 'quizLiveScreenRightAnswer',
        'places' => 'quizLiveScreenPlaces',
        'behind' => 'quizLiveScreenBehind',
        'leading' => 'quizLiveScreenLeading',
        'outOfPlayers' => 'quizLiveScreenOutOfPlayers',
        'points' => 'quizLiveScreenPoints',
        'guestThanks' => 'quizLiveScreenGuestThanks',
        'accountThanks' => 'quizLiveScreenAccountThanks',
        'home' => 'quizLiveScreenHome',
        'cancelledTitle' => 'quizLiveScreenCancelledTitle',
        'cancelledText' => 'quizLiveScreenCancelledText',
        'removedTitle' => 'quizLiveScreenRemovedTitle',
        'removedText' => 'quizLiveScreenRemovedText',
        'rejoin' => 'quizLiveScreenRejoin',
    ];

    private const array KINDS = [
        'choice' => 'quizLiveKindChoiceHint',
        'multi' => 'quizLiveKindMultiHint',
        'order' => 'quizLiveKindOrderHint',
        'number' => 'quizLiveKindNumberHint',
        'slider' => 'quizLiveKindSliderHintTemplate',
        'text' => 'quizLiveKindTextHint',
        'vote' => 'quizLiveKindVoteHint',
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * Templates keep their %placeholders%: the screens fill them.
     *
     * @return array<string, string|array<string, string>>
     */
    public function all(): array
    {
        $labels = [];
        foreach (self::KEYS as $name => $key) {
            $labels[$name] = $this->raw($key);
        }
        $labels['kinds'] = [];
        foreach (self::KINDS as $name => $key) {
            $labels['kinds'][$name] = $this->raw($key);
        }

        return $labels;
    }

    /** A message as written: its %placeholders% stay for the browser to fill. */
    private function raw(string $key): string
    {
        return $this->translator->trans($key);
    }
}
