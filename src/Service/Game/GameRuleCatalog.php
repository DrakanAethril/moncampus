<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Enum\GameFamily;

/**
 * The whole barème of design/validated/gamification.md §5, in code.
 *
 * Same principle as App\Enum\Feature: the catalogue lives here and the database stores only the
 * *deviations* from it (App\Entity\GameRule), so adding a rule is a constant plus a case rather
 * than a data migration, and a formation that has never opened the settings screen still plays a
 * complete game.
 *
 * Two things are deliberately absent, and both are §4:
 *
 * - **No rule pays a consultation.** AssignmentView, AudioListenProgress, VideoWatchProgress and
 *   their like are free to plug in and none of them is: a view says a page was opened, not that it
 *   was read, and it is trivial to produce in bulk. What is paid is what remains after the gesture -
 *   a file, an answer, a revision, a human decision (decision 4).
 * - **No rule pays a score.** A quiz sat is worth its 20 points whether it was passed or failed;
 *   quality enters the index once, through the class council's mention (decision 5). Paying
 *   performance would turn the game into a second, worse, academic ranking.
 */
final class GameRuleCatalog
{
    // --- Assiduité (§5.1) ----------------------------------------------------------------------

    /** One net unit of the statement, per week it covers. */
    public const string ATTENDANCE_CLEAN = 'attendance.clean';
    /** Each consecutive net unit beyond the first, capped by GameProgramSettings::$attendanceStreakCap. */
    public const string ATTENDANCE_STREAK = 'attendance.streak';

    // --- Travail (§5.2) ------------------------------------------------------------------------

    public const string WORK_ON_TIME = 'work.on_time';
    public const string WORK_LATE = 'work.late';
    public const string WORK_QUIZ = 'work.quiz';
    public const string WORK_SELF_ASSESSMENT = 'work.self_assessment';
    /** Declared done, found undone in class: the credit is cancelled. Not a malus - see §5.2. */
    public const string WORK_DECLARATION_CANCELLED = 'work.declaration_cancelled';

    // --- Engagement volontaire (§5.3), automatic ------------------------------------------------

    public const string ENGAGEMENT_QUIZ_PROGRESS = 'engagement.quiz_progress';
    public const string ENGAGEMENT_SURVEY = 'engagement.survey';
    public const string ENGAGEMENT_SIGNUP_ATTENDED = 'engagement.signup_attended';
    public const string ENGAGEMENT_WIKI = 'engagement.wiki';
    public const string ENGAGEMENT_APPLICATION = 'engagement.application';
    public const string ENGAGEMENT_SHARED_RESOURCE = 'engagement.shared_resource';

    // --- Engagement volontaire, declared then validated -----------------------------------------

    public const string ENGAGEMENT_CERTIFICATION = 'engagement.certification';
    public const string ENGAGEMENT_REPRESENTATION = 'engagement.representation';
    public const string ENGAGEMENT_PROJECT = 'engagement.project';
    public const string ENGAGEMENT_TUTORING = 'engagement.tutoring';
    public const string ENGAGEMENT_MANDATE = 'engagement.mandate';

    // --- Reconnaissance (§5.4) ------------------------------------------------------------------

    public const string RECOGNITION_COUNCIL = 'recognition.council';
    public const string RECOGNITION_GESTURE_BONUS = 'recognition.gesture_bonus';
    public const string RECOGNITION_GESTURE_MALUS = 'recognition.gesture_malus';
    public const string RECOGNITION_TEAM_GOAL = 'recognition.team_goal';

    /** @var array<string, GameRuleDefinition>|null */
    private static ?array $rules = null;

    /** @return array<string, GameRuleDefinition> */
    public static function all(): array
    {
        return self::$rules ??= self::index([
            new GameRuleDefinition(self::ATTENDANCE_CLEAN, GameFamily::Attendance, 30),
            new GameRuleDefinition(self::ATTENDANCE_STREAK, GameFamily::Attendance, 10),

            new GameRuleDefinition(self::WORK_ON_TIME, GameFamily::Work, 30, null, 30),
            new GameRuleDefinition(self::WORK_LATE, GameFamily::Work, 10, null, 30),
            new GameRuleDefinition(self::WORK_QUIZ, GameFamily::Work, 20, null, 20),
            new GameRuleDefinition(self::WORK_SELF_ASSESSMENT, GameFamily::Work, 10, null, 10),
            new GameRuleDefinition(self::WORK_DECLARATION_CANCELLED, GameFamily::Work, 0),

            new GameRuleDefinition(self::ENGAGEMENT_QUIZ_PROGRESS, GameFamily::Engagement, 15, 3),
            new GameRuleDefinition(self::ENGAGEMENT_SURVEY, GameFamily::Engagement, 10),
            new GameRuleDefinition(self::ENGAGEMENT_SIGNUP_ATTENDED, GameFamily::Engagement, 20),
            new GameRuleDefinition(self::ENGAGEMENT_WIKI, GameFamily::Engagement, 5, 2),
            new GameRuleDefinition(self::ENGAGEMENT_APPLICATION, GameFamily::Engagement, 5, 2),
            new GameRuleDefinition(self::ENGAGEMENT_SHARED_RESOURCE, GameFamily::Engagement, 15),

            new GameRuleDefinition(self::ENGAGEMENT_CERTIFICATION, GameFamily::Engagement, 60),
            new GameRuleDefinition(self::ENGAGEMENT_REPRESENTATION, GameFamily::Engagement, 40),
            new GameRuleDefinition(self::ENGAGEMENT_PROJECT, GameFamily::Engagement, 40),
            new GameRuleDefinition(self::ENGAGEMENT_TUTORING, GameFamily::Engagement, 30),
            new GameRuleDefinition(self::ENGAGEMENT_MANDATE, GameFamily::Engagement, 30),

            new GameRuleDefinition(self::RECOGNITION_COUNCIL, GameFamily::Recognition, 0),
            new GameRuleDefinition(self::RECOGNITION_GESTURE_BONUS, GameFamily::Recognition, 0),
            new GameRuleDefinition(self::RECOGNITION_GESTURE_MALUS, GameFamily::Recognition, 0),
            new GameRuleDefinition(self::RECOGNITION_TEAM_GOAL, GameFamily::Recognition, 40),
        ]);
    }

    public static function get(string $code): ?GameRuleDefinition
    {
        return self::all()[$code] ?? null;
    }

    public static function familyOf(string $code): ?GameFamily
    {
        return self::get($code)?->family;
    }

    /**
     * The codes an administrator may retune from the settings screen - the ones whose points are a
     * fixed number rather than something the gesture itself carries.
     *
     * A gesture, a council mention and a cancelled declaration are excluded because their value is
     * chosen at the moment they are posed (+5/+10/+20, the mention's own scale, the exact credit
     * being undone); offering a "points" field for them would let a program quietly re-rate what a
     * teacher already decided.
     *
     * @return list<GameRuleDefinition>
     */
    public static function tunable(): array
    {
        $excluded = [
            self::WORK_DECLARATION_CANCELLED,
            self::RECOGNITION_COUNCIL,
            self::RECOGNITION_GESTURE_BONUS,
            self::RECOGNITION_GESTURE_MALUS,
        ];

        return array_values(array_filter(
            self::all(),
            static fn (GameRuleDefinition $rule): bool => !\in_array($rule->code, $excluded, true),
        ));
    }

    /**
     * @param list<GameRuleDefinition> $rules
     *
     * @return array<string, GameRuleDefinition>
     */
    private static function index(array $rules): array
    {
        $indexed = [];
        foreach ($rules as $rule) {
            $indexed[$rule->code] = $rule;
        }

        return $indexed;
    }
}
