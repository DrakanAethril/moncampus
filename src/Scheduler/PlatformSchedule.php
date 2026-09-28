<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Everything the platform does on its own, on a clock - the one place that says what runs when.
 *
 * This replaces the production host's crontab. The `worker` service (compose.prod.yaml) consumes
 * this schedule with `messenger:consume scheduler_default`, and each task is the same console
 * command a cron line used to `docker compose exec`: the commands did not change, only what
 * presses them. What that buys:
 *
 * - **The schedule ships with the code.** A task added to a release runs with that release; the
 *   crontab was a file on the server that a deploy never touched, which is how
 *   `app:purge-platform-activity` stayed unwired for a month while the screen promised
 *   « Conservation 90 jours ».
 * - **A failure is heard.** A cron line appended its output to a file under /var/log that nobody
 *   read. Here a command that exits non-zero is logged at error level, with the tail of its output,
 *   and therefore reaches Discord - see ScheduledCommandLogger. Several of these commands were
 *   written to exit non-zero precisely « so a scheduler notices ».
 *
 * **One worker, one task at a time.** Tasks run one after the other in the worker's process, so
 * two of them never overlap, and a long nightly task delays the every-minute ones by its own
 * duration. The minute tasks then run once, not once per minute missed: that is
 * `processOnlyLastMissedRun()`. `stateful()` keeps the last run in cache.app, so a worker that
 * restarts (it does, every hour, by design) catches up the task whose minute fell in the gap
 * instead of skipping it - a nightly purge must not depend on the worker not having restarted at
 * 03:15.
 *
 * Times are Paris time, whatever the server's clock says. Every command below is documented in
 * docs/production.md; the table in CLAUDE.md says which are scheduled.
 */
#[AsSchedule]
final class PlatformSchedule implements ScheduleProviderInterface
{
    private const string TIMEZONE = 'Europe/Paris';

    /**
     * Command => cron expression. Kept as data so that PlatformScheduleTest can pin it, and so that
     * reading the schedule does not mean reading twelve `RecurringMessage::cron()` calls.
     *
     * @var array<string, string>
     */
    public const array TASKS = [
        // Courrier pro: inbound mail and SES delivery events, drained from their SQS queues. A
        // minute of latency is invisible at a few dozen mails a day.
        'app:mail:consume-inbound' => '* * * * *',
        'app:mail:consume-events' => '* * * * *',
        // What makes a VM deployment and an account rename survive the browser tab that started
        // them: the screen's own polling loop is never what carries the work.
        'app:vm-batch:advance' => '* * * * *',
        'app:ldap:apply-account-requests' => '* * * * *',
        // e-CO: what the IGN says about a parcours and about a closed race, too slow for a request.
        'app:eco:read-terrain' => '* * * * *',
        // The hypervisors' badges and the address ranges, refreshed off the page render. Offset
        // from each other so the two never start in the same minute.
        'app:proxmox:check' => '*/5 * * * *',
        'app:proxmox:scan-addresses' => '2-59/5 * * * *',
        // The night, spread so each pass has the database to itself.
        'app:mail:reconcile' => '30 2 * * *',
        'app:uploads:purge' => '0 3 * * *',
        'app:purge-platform-activity' => '15 3 * * *',
        'app:counters:recompute' => '45 3 * * *',
        'app:game:close-month' => '30 4 * * *',
        // The morning, once the night is done: the VM batches whose date has passed, each reminded
        // about once. It destroys nothing - an administrator deletes in Proxmox.
        'app:proxmox:expire-batches' => '0 7 * * *',
    ];

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        foreach (self::TASKS as $command => $expression) {
            // throwOnFailure: false - a non-zero exit is not an exception to retry but an outcome
            // to report, with the command's own output; ScheduledCommandLogger does that.
            $schedule->add(RecurringMessage::cron(
                $expression,
                new RunCommandMessage($command, throwOnFailure: false),
                self::TIMEZONE,
            ));
        }

        return $schedule;
    }
}
