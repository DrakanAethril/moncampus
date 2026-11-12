<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Entity\GameProgramSettings;
use App\Entity\GameRule;
use App\Entity\RewardItem;
use App\Enum\SchoolYearImportBlock;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;
use App\Service\SchoolYearImport\SchoolYearImportRules;

/**
 * « Jeu du campus »: weights, thresholds and switches (only on a formation that has none), the
 * rules recognised by their code, the formation's rewards by their label. Never a point, never a
 * reward handed out: the ledger stays in its year.
 */
final readonly class GameSettingsCopier implements ProgramCopier
{
    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        if (!$run->has(SchoolYearImportBlock::Game)) {
            return;
        }

        $write = $run->writable($pair);

        $settings = $run->data->rowsOf(GameProgramSettings::class, $pair->source)[0] ?? null;
        if (null !== $settings && [] === $run->data->rowsOf(GameProgramSettings::class, $pair->target)) {
            $run->report->add($pair, 'gameSettings');
            if (null !== $write) {
                $copy = new GameProgramSettings($write);
                $copy->setWeightAttendance($settings->getWeightAttendance());
                $copy->setWeightWork($settings->getWeightWork());
                $copy->setWeightEngagement($settings->getWeightEngagement());
                $copy->setWeightRecognition($settings->getWeightRecognition());
                $copy->setEngagementCap($settings->getEngagementCap());
                $copy->setRecognitionCap($settings->getRecognitionCap());
                $copy->setAttendanceStep($settings->getAttendanceStep());
                $copy->setAttendanceStreakCap($settings->getAttendanceStreakCap());
                $copy->setThresholdBronze($settings->getThresholdBronze());
                $copy->setThresholdSilver($settings->getThresholdSilver());
                $copy->setThresholdGold($settings->getThresholdGold());
                $copy->setTeamThreshold($settings->getTeamThreshold());
                $copy->setTeamMode($settings->getTeamMode());
                $copy->setRankingEnabled($settings->isRankingEnabled());
                $copy->setRankedMonths($settings->getRankedMonths());
                $copy->setAliasEnabled($settings->isAliasEnabled());
                $copy->setMalusEnabled($settings->isMalusEnabled());
                $run->persist($copy);
            }
        }

        $codes = [];
        foreach ($run->data->rowsOf(GameRule::class, $pair->target) as $rule) {
            $codes[$rule->getCode()] = true;
        }
        foreach ($run->data->rowsOf(GameRule::class, $pair->source) as $rule) {
            if (isset($codes[$rule->getCode()])) {
                continue;
            }
            $codes[$rule->getCode()] = true;
            $run->report->add($pair, 'gameRules');
            if (null !== $write) {
                $copy = new GameRule($write, $rule->getCode(), $rule->getPoints());
                $copy->setWeeklyCap($rule->getWeeklyCap());
                $copy->setEnabled($rule->isEnabled());
                $run->persist($copy);
            }
        }

        $labels = [];
        foreach ($run->data->rowsOf(RewardItem::class, $pair->target) as $reward) {
            $labels[SchoolYearImportRules::key($reward->getLabel())] = true;
        }
        foreach ($run->data->rowsOf(RewardItem::class, $pair->source) as $reward) {
            $key = SchoolYearImportRules::key($reward->getLabel());
            if (isset($labels[$key])) {
                continue;
            }
            $labels[$key] = true;
            $run->report->add($pair, 'rewards');
            if (null !== $write) {
                $copy = new RewardItem($write, $reward->getLabel(), $reward->getNature(), $reward->getScope());
                $copy->setDescription($reward->getDescription());
                $copy->setIcon($reward->getIcon());
                $copy->setQuantity($reward->getQuantity());
                $copy->setLevel($reward->getLevel());
                $copy->setActive($reward->isActive());
                $run->persist($copy);
            }
        }
    }
}
