<?php

declare(strict_types=1);

namespace App\Service\Game;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\GameTrack;
use App\Repository\ProgramStudentOptionRepository;

/**
 * Which filière of the campus game a student plays in - **per student, not per formation**.
 *
 * A formation is not a filière. BTS SIO holds SLAM and SISR side by side in one class, and which of
 * the two a student belongs to is their **option**; two students of the same formation must
 * therefore read different level wording and draw their pseudonym from different catalogues. That is
 * why the univers hangs on App\Entity\Option::$gameTrack and is resolved here through
 * App\Entity\ProgramStudentOption, rather than being a single value stamped on the Program.
 *
 * `Program::$gameTrack` survives as the **fallback**, and it is not a leftover: a formation whose
 * whole class is one filière - Comptabilité, Management commercial - splits into no option at all,
 * and has nothing else to say so with.
 *
 * A student carrying two options that each name a filière is answered with the first in the
 * formation's own order. That is a data mistake rather than a case to model: a person is in SLAM or
 * in SISR, and the settings screen prints what each option resolves to so the mistake is visible.
 *
 * **A student whose option says nothing plays in every filière their formation holds.** A SIO
 * student not yet placed in SLAM or in SISR is the ordinary case of a first term, and answering
 * them with no filière at all cost them both their level wording and their pseudonym - the two
 * things the univers decides. They draw from the two catalogues at once and read the two wordings
 * of a level; the day their option is set, they are back to one.
 */
final class GameTrackResolver
{
    /** @var array<string, list<GameTrack>> */
    private array $cache = [];

    public function __construct(private readonly ProgramStudentOptionRepository $studentOptions)
    {
    }

    /** The one filière a student's screens are drawn in - the first of the ones they play in. */
    public function forStudent(User $student, Program $program): ?GameTrack
    {
        return $this->tracksForStudent($student, $program)[0] ?? null;
    }

    /**
     * Every filière this student plays in - one when their option names it, all of the formation's
     * when it does not.
     *
     * @return list<GameTrack>
     */
    public function tracksForStudent(User $student, Program $program): array
    {
        $key = $student->getId().'|'.$program->getId();

        if (\array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        foreach ($this->studentOptions->findOptionsForStudent($program, $student) as $option) {
            $track = $option->getGameTrack();

            if (null !== $track) {
                return $this->cache[$key] = [$track];
            }
        }

        // Nothing on their own options: the formation's filières, which is both of them in a SIO
        // class and the single fallback one in a class that does not split.
        return $this->cache[$key] = $this->forProgram($program);
    }

    /**
     * The filières a formation actually plays in - what the settings screen prints so that a class
     * holding two of them can see both.
     *
     * @return list<GameTrack>
     */
    public function forProgram(Program $program): array
    {
        $tracks = [];

        foreach ($program->getOptions() as $option) {
            $track = $option->getGameTrack();

            if (null !== $track && !\in_array($track, $tracks, true)) {
                $tracks[] = $track;
            }
        }

        $fallback = $program->getGameTrack();

        if ([] === $tracks && null !== $fallback) {
            $tracks[] = $fallback;
        }

        return $tracks;
    }

    /**
     * The formation's options that name a filière, keyed by option id - the settings screen's own
     * reading, so an administrator sees which option carries which univers without leaving.
     *
     * @return array<int, Option>
     */
    public function trackedOptions(Program $program): array
    {
        $tracked = [];

        foreach ($program->getOptions() as $option) {
            if (null !== $option->getGameTrack()) {
                $tracked[(int) $option->getId()] = $option;
            }
        }

        return $tracked;
    }
}
