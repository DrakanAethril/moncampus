<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The kinds of leaf an access condition is made of - see design/comparaison/conception_1_3_5.md,
 * "Point 3". Each one names an object already tracked somewhere in the application: nothing here
 * introduces a new measurement, they all read a trace that already exists.
 *
 * The value is what lands in the access_condition JSON column, so it is a stored format: renaming a
 * case means migrating rows.
 */
enum AccessConditionType: string
{
    case QuizScore = 'quiz_score';
    case AssignmentDone = 'assignment_done';
    case AudioListened = 'audio_listened';
    case VideoWatched = 'video_watched';
    case ResourceViewed = 'resource_viewed';
    case SeancePassed = 'seance_passed';
    case DateFrom = 'date_from';
    case Group = 'group';

    /**
     * The JSON key holding the referenced object's id, which differs per type so that a hand-read
     * condition says what it points at. DateFrom names no object and carries a date instead.
     */
    public function targetKey(): ?string
    {
        return match ($this) {
            self::QuizScore => 'instance',
            self::AssignmentDone => 'assignment',
            self::AudioListened => 'recording',
            self::VideoWatched => 'video',
            self::ResourceViewed => 'resource',
            self::SeancePassed => 'seance',
            self::Group => 'group',
            self::DateFrom => null,
        };
    }

    /** Whether the teacher is offered a percentage alongside the object. */
    public function hasPercent(): bool
    {
        return \in_array($this, [self::QuizScore, self::AudioListened, self::VideoWatched], true);
    }

    /** Only a score has a meaningful upper bound - "listened to at most 60 %" says nothing. */
    public function hasMaxPercent(): bool
    {
        return self::QuizScore === $this;
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::QuizScore => 'accessConditionTypeQuizScoreLabel',
            self::AssignmentDone => 'accessConditionTypeAssignmentDoneLabel',
            self::AudioListened => 'accessConditionTypeAudioListenedLabel',
            self::VideoWatched => 'accessConditionTypeVideoWatchedLabel',
            self::ResourceViewed => 'accessConditionTypeResourceViewedLabel',
            self::SeancePassed => 'accessConditionTypeSeancePassedLabel',
            self::DateFrom => 'accessConditionTypeDateFromLabel',
            self::Group => 'accessConditionTypeGroupLabel',
        };
    }

    /**
     * In the order the teacher's picker offers them: the séance first, per the conception - inside a
     * sequence it is the choice that stays true when the timetable moves.
     *
     * @return list<self>
     */
    public static function forPicker(): array
    {
        return [
            self::SeancePassed,
            self::AssignmentDone,
            self::QuizScore,
            self::ResourceViewed,
            self::AudioListened,
            self::VideoWatched,
            self::DateFrom,
            self::Group,
        ];
    }
}
