<?php

declare(strict_types=1);

namespace App\Service\PlatformEmail;

use App\Enum\PlatformEmailGroup;
use App\Service\MagicLoginService;

/**
 * Every mail the platform writes itself, in the order UFA > Configuration > Emails lists them. A
 * template that sends two different texts (tutor / alternant, with or without a period) is two
 * entries: the screen shows mails, not files.
 *
 * Read-only by construction - the texts live in templates/emails/ and in the translations, this
 * only names them. PlatformEmailCatalogTest refuses a template of templates/emails/ that no entry
 * names, so a new mail cannot be sent without being listed here.
 *
 * Courrier pro is absent on purpose: those mails are written by students, not by the platform.
 */
final class PlatformEmailCatalog
{
    private const string PERIOD = '$PERIODE';
    private const string DEADLINE = '$DATE_LIMITE';
    private const string PROGRAM = '$FORMATION';
    private const string STUDENT = '$ALTERNANT';
    private const string ADDRESS = '$ADRESSE_EMAIL';
    private const string SENDER = '$EXPEDITEUR';
    private const string SUBJECT = '$OBJET';
    private const string BODY = '$MESSAGE';
    private const string SIGNATURE = '$SIGNATURE';

    /** Only ever lands in a link's address, which the screen does not show. */
    private const string TOKEN = 'JETON';

    /** @return list<PlatformEmail> */
    public function all(): array
    {
        // The routes these two take an id from only accept digits.
        $program = ['id' => 0, 'displayShortName' => self::PROGRAM];
        $period = ['name' => self::PERIOD];

        return [
            new PlatformEmail(
                'engagement-invite-tutor',
                PlatformEmailGroup::Alternance,
                'platformEmailEngagementInviteTutorTitle',
                'platformEmailEngagementInviteTutorWhen',
                'emails/internship_alternance_engagement_invite.html.twig',
                ['role' => 'tutor'],
            ),
            new PlatformEmail(
                'engagement-invite-student',
                PlatformEmailGroup::Alternance,
                'platformEmailEngagementInviteStudentTitle',
                'platformEmailEngagementInviteStudentWhen',
                'emails/internship_alternance_engagement_invite.html.twig',
                ['role' => 'student'],
            ),
            new PlatformEmail(
                'reminder-engagement',
                PlatformEmailGroup::Alternance,
                'platformEmailReminderEngagementTitle',
                'platformEmailReminderEngagementWhen',
                'emails/internship_alternance_reminder.html.twig',
                ['period' => null, 'deadline' => null, 'ctaRoute' => 'app_home', 'ctaRouteParams' => []],
            ),
            new PlatformEmail(
                'reminder-period',
                PlatformEmailGroup::Alternance,
                'platformEmailReminderPeriodTitle',
                'platformEmailReminderPeriodWhen',
                'emails/internship_alternance_reminder.html.twig',
                ['period' => $period, 'deadline' => self::DEADLINE, 'ctaRoute' => 'app_home', 'ctaRouteParams' => []],
                [self::PERIOD => 'platformEmailVariablePeriod', self::DEADLINE => 'platformEmailVariableDeadline'],
            ),
            new PlatformEmail(
                'period-turn',
                PlatformEmailGroup::Alternance,
                'platformEmailPeriodTurnTitle',
                'platformEmailPeriodTurnWhen',
                'emails/internship_alternance_period_turn.html.twig',
                ['periodName' => self::PERIOD, 'ctaRoute' => 'app_program_internship_my_evaluations', 'ctaRouteParams' => ['id' => 0]],
                [self::PERIOD => 'platformEmailVariablePeriod'],
            ),
            new PlatformEmail(
                'evaluation-reminder-student',
                PlatformEmailGroup::Alternance,
                'platformEmailEvaluationReminderStudentTitle',
                'platformEmailEvaluationReminderStudentWhen',
                'emails/internship_student_evaluation_reminder.html.twig',
                ['program' => $program, 'period' => $period],
                [self::PERIOD => 'platformEmailVariablePeriod', self::PROGRAM => 'platformEmailVariableProgram'],
            ),
            new PlatformEmail(
                'evaluation-reminder-tutor',
                PlatformEmailGroup::Alternance,
                'platformEmailEvaluationReminderTutorTitle',
                'platformEmailEvaluationReminderTutorWhen',
                'emails/internship_tutor_evaluation_reminder.html.twig',
                ['program' => $program, 'period' => $period, 'tutorLink' => ['student' => ['displayName' => self::STUDENT]]],
                [self::STUDENT => 'platformEmailVariableStudent', self::PERIOD => 'platformEmailVariablePeriod', self::PROGRAM => 'platformEmailVariableProgram'],
            ),
            new PlatformEmail(
                'magic-login',
                PlatformEmailGroup::Account,
                'platformEmailMagicLoginTitle',
                'platformEmailMagicLoginWhen',
                'emails/magic_login.html.twig',
                ['token' => self::TOKEN],
            ),
            new PlatformEmail(
                'magic-login-mobile',
                PlatformEmailGroup::Account,
                'platformEmailMagicLoginMobileTitle',
                'platformEmailMagicLoginMobileWhen',
                'emails/magic_login_mobile.html.twig',
                // The lifetime is a setting of the platform, not something that varies per send.
                ['token' => self::TOKEN, 'webAppUrl' => null, 'minutes' => MagicLoginService::MOBILE_TOKEN_TTL_MINUTES],
            ),
            new PlatformEmail(
                'contact-email-confirmation',
                PlatformEmailGroup::Account,
                'platformEmailContactEmailConfirmationTitle',
                'platformEmailContactEmailConfirmationWhen',
                'emails/contact_email_confirmation.html.twig',
                ['user' => ['pendingContactEmail' => self::ADDRESS], 'token' => self::TOKEN],
                [self::ADDRESS => 'platformEmailVariableAddress'],
            ),
            new PlatformEmail(
                'message-copy',
                PlatformEmailGroup::Messaging,
                'platformEmailMessageCopyTitle',
                'platformEmailMessageCopyWhen',
                'emails/message_copy.html.twig',
                ['sender' => ['displayName' => self::SENDER, 'signature' => self::SIGNATURE], 'subject' => self::SUBJECT, 'body' => self::BODY],
                [
                    self::SENDER => 'platformEmailVariableSender',
                    self::SUBJECT => 'platformEmailVariableSubject',
                    self::BODY => 'platformEmailVariableBody',
                    self::SIGNATURE => 'platformEmailVariableSignature',
                ],
            ),
        ];
    }

    public function find(string $key): ?PlatformEmail
    {
        return array_find($this->all(), static fn (PlatformEmail $email): bool => $email->key === $key);
    }
}
