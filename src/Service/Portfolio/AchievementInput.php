<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Enum\PortfolioSetting;
use App\Service\PostValue;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the « Nouvelle réalisation » form posts, typed once at the boundary.
 */
final readonly class AchievementInput
{
    /**
     * @param array<int, string> $claims competency id => justification, for the ticked competencies
     */
    public function __construct(
        public string $title,
        public PortfolioSetting $setting,
        public ?\DateTimeImmutable $startsOn,
        public ?\DateTimeImmutable $endsOn,
        public ?string $organisation,
        public ?string $place,
        public bool $teamwork,
        public ?string $teamNote,
        public ?string $descriptionHtml,
        public array $claims,
        public ?int $requestedReviewerId,
    ) {
    }

    public static function fromRequest(Request $request, callable $sanitize): self
    {
        $ticked = PostValue::all($request, 'claim');
        $reasons = PostValue::all($request, 'justification');
        $claims = [];

        foreach ($ticked as $competencyId => $value) {
            if ('1' === $value && is_numeric($competencyId)) {
                $reason = $reasons[$competencyId] ?? '';
                $claims[(int) $competencyId] = \is_string($reason) ? trim($reason) : '';
            }
        }

        $description = PostValue::string($request, 'description');
        $cleaned = '' === trim($description) ? null : $sanitize($description);

        return new self(
            mb_substr(PostValue::trimmed($request, 'title'), 0, 255),
            PortfolioSetting::tryFrom(PostValue::string($request, 'setting')) ?? PortfolioSetting::Training,
            self::date(PostValue::trimmed($request, 'startsOn')),
            self::date(PostValue::trimmed($request, 'endsOn')),
            mb_substr(PostValue::trimmed($request, 'organisation'), 0, 255),
            mb_substr(PostValue::trimmed($request, 'place'), 0, 255),
            PostValue::bool($request, 'teamwork'),
            PostValue::trimmed($request, 'teamNote'),
            \is_string($cleaned) ? $cleaned : null,
            $claims,
            PostValue::nullableInt($request, 'requestedReviewer'),
        );
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        $date = '' === $value ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }
}
