<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

use App\SeriousGame\Math\MathFormat;

/**
 * The figures a template declares (design/validated/serious-games.md §5.2, `figure`), drawn as SVG
 * on the server: a sheet of squared paper on the astronomer's desk.
 *
 * Two kinds, ported from the reference's `Fig()` and its weighted tree:
 *
 * - `grid` - a squared repère from x[0] to x[1] and y[0] to y[1], graduated, with items drawn on it:
 *   `curve` (a broken line through points), `smooth` (a smooth curve through turning points,
 *   horizontal tangents), `line` (y = m x + p, clipped to the sheet), `parabola` (y = a x² + b x + c),
 *   `hline` (a dashed horizontal line y = k, labelled), `dot` (a point, optionally named) and `label`;
 * - `tree` - a two-level weighted tree A / Ā then B / B̄, its probabilities p, q (under A) and q2
 *   (under Ā).
 *
 * The interpreter evaluates the item's expressions first; this class only draws numbers and short
 * texts, and escapes every text it writes.
 */
final class TemplateFigures
{
    private const string PAPER = '#F7F1E3';
    private const string GRID = '#DCCFB2';
    private const string AXIS = '#2B3A6B';
    private const array COLORS = ['ink' => '#B5444A', 'red' => '#B5444A', 'blue' => '#2F6FB0', 'axis' => '#2B3A6B'];

    /** The items a grid draws, and the keys each one reads. */
    public const array GRID_ITEMS = [
        'curve' => ['points'],
        'smooth' => ['points'],
        'line' => ['m', 'p'],
        'parabola' => ['a', 'b', 'c'],
        'hline' => ['k'],
        'dot' => ['x', 'y'],
        'label' => ['x', 'y', 'text'],
    ];

    /**
     * @param array<string, mixed> $figure the declared figure, its expressions already evaluated
     */
    public static function render(array $figure): string
    {
        return match ($figure['type'] ?? null) {
            'grid' => self::grid($figure),
            'tree' => self::tree(self::float($figure['p'] ?? 0), self::float($figure['q'] ?? 0), self::float($figure['q2'] ?? 0)),
            default => throw new TemplateFormatException('figure.type', 'figure inconnue (attendu : grid, tree)'),
        };
    }

    /**
     * @param array<string, mixed> $figure
     */
    private static function grid(array $figure): string
    {
        [$x0, $x1] = self::pair($figure['x'] ?? [-5, 5]);
        [$y0, $y1] = self::pair($figure['y'] ?? [-4, 5]);
        $u = isset($figure['unit']) ? self::float($figure['unit']) : 24.0;
        $m = 16;
        $width = ($x1 - $x0) * $u + 2 * $m;
        $height = ($y1 - $y0) * $u + 2 * $m;
        $X = static fn (float $x): float => MathFormat::round($m + ($x - $x0) * $u, 2);
        $Y = static fn (float $y): float => MathFormat::round($m + ($y1 - $y) * $u, 2);
        $n = static fn (float $v): string => MathFormat::plainNumber($v);

        $out = ['<rect x="0" y="0" width="'.$n($width).'" height="'.$n($height).'" rx="6" fill="'.self::PAPER.'"/>'];
        for ($gx = $x0; $gx <= $x1; ++$gx) {
            $out[] = '<line x1="'.$n($X($gx)).'" y1="'.$n($Y($y0)).'" x2="'.$n($X($gx)).'" y2="'.$n($Y($y1)).'" stroke="'.self::GRID.'" stroke-width="1"/>';
        }
        for ($gy = $y0; $gy <= $y1; ++$gy) {
            $out[] = '<line x1="'.$n($X($x0)).'" y1="'.$n($Y($gy)).'" x2="'.$n($X($x1)).'" y2="'.$n($Y($gy)).'" stroke="'.self::GRID.'" stroke-width="1"/>';
        }
        if ($y0 <= 0 && $y1 >= 0) {
            $out[] = '<line x1="'.$n($X($x0)).'" y1="'.$n($Y(0)).'" x2="'.$n($X($x1)).'" y2="'.$n($Y(0)).'" stroke="'.self::AXIS.'" stroke-width="1.6"/>';
        }
        if ($x0 <= 0 && $x1 >= 0) {
            $out[] = '<line x1="'.$n($X(0)).'" y1="'.$n($Y($y0)).'" x2="'.$n($X(0)).'" y2="'.$n($Y($y1)).'" stroke="'.self::AXIS.'" stroke-width="1.6"/>';
        }

        $mono = static fn (float $x, float $y, string $t, string $anchor = 'middle'): string => '<text x="'.$n($x).'" y="'.$n($y).'" font-family="IBM Plex Mono, monospace" font-size="9.5" fill="'.self::AXIS.'" text-anchor="'.$anchor.'">'.$t.'</text>';
        for ($lx = $x0 + 1; $lx < $x1; ++$lx) {
            if (0.0 !== $lx) {
                $out[] = $mono($X($lx), $Y(0) + 12, MathFormat::fmt($lx));
            }
        }
        for ($ly = $y0 + 1; $ly < $y1; ++$ly) {
            if (0.0 !== $ly) {
                $out[] = $mono($X(0) - 5, $Y($ly) + 3.5, MathFormat::fmt($ly), 'end');
            }
        }
        $out[] = $mono($X(0) - 5, $Y(0) + 12, '0', 'end');

        $polyline = static fn (array $points, string $color, bool $dashed = false): string => '<polyline fill="none" stroke="'.$color.'" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"'.($dashed ? ' stroke-dasharray="5 4"' : '').' points="'.implode(' ', array_map(static fn (array $p): string => $n($X($p[0])).','.$n($Y($p[1])), $points)).'"/>';

        foreach (\is_array($figure['items'] ?? null) ? $figure['items'] : [] as $i => $item) {
            if (!\is_array($item)) {
                continue;
            }

            $color = self::COLORS[\is_string($item['color'] ?? null) ? $item['color'] : 'ink'] ?? self::COLORS['ink'];
            switch ($item['draw'] ?? null) {
                case 'curve':
                    $out[] = $polyline(self::points($item['points'] ?? []), $color, true === ($item['dashed'] ?? false));
                    break;
                case 'smooth':
                    $points = self::points($item['points'] ?? []);
                    if ([] === $points) {
                        break;
                    }
                    $d = 'M'.$n($X($points[0][0])).' '.$n($Y($points[0][1]));
                    for ($k = 1, $count = \count($points); $k < $count; ++$k) {
                        [$a, $b] = [$points[$k - 1], $points[$k]];
                        $dx = ($b[0] - $a[0]) / 3;
                        $d .= ' C'.$n($X($a[0] + $dx)).' '.$n($Y($a[1])).' '.$n($X($b[0] - $dx)).' '.$n($Y($b[1])).' '.$n($X($b[0])).' '.$n($Y($b[1]));
                    }
                    $out[] = '<path d="'.$d.'" fill="none" stroke="'.$color.'" stroke-width="2.4" stroke-linecap="round"/>';
                    break;
                case 'line':
                    $slope = self::float($item['m'] ?? 0);
                    $p = self::float($item['p'] ?? 0);
                    [$a, $b] = [$x0, $x1];
                    if (0.0 !== $slope) {
                        $xa = ($y0 - $p) / $slope;
                        $xb = ($y1 - $p) / $slope;
                        $a = max($x0, min($xa, $xb));
                        $b = min($x1, max($xa, $xb));
                    }
                    $out[] = $polyline([[$a, $slope * $a + $p], [$b, $slope * $b + $p]], $color);
                    break;
                case 'parabola':
                    [$qa, $qb, $qc] = [self::float($item['a'] ?? 0), self::float($item['b'] ?? 0), self::float($item['c'] ?? 0)];
                    $points = [];
                    for ($x = $x0; $x <= $x1 + 1e-9; $x += 0.1) {
                        $y = $qa * $x * $x + $qb * $x + $qc;
                        if ($y >= $y0 - 0.5 && $y <= $y1 + 0.5) {
                            $points[] = [$x, max($y0 - 0.5, min($y1 + 0.5, $y))];
                        }
                    }
                    $out[] = $polyline($points, $color);
                    break;
                case 'hline':
                    $k = self::float($item['k'] ?? 0);
                    $out[] = $polyline([[$x0, $k], [$x1, $k]], self::COLORS[\is_string($item['color'] ?? null) ? $item['color'] : 'blue'] ?? self::COLORS['blue'], true);
                    if (isset($item['label'])) {
                        $out[] = self::label($X($x1 - 0.6), $Y($k + 0.35), self::text($item['label']), null, self::COLORS['blue']);
                    }
                    break;
                case 'dot':
                    $x = self::float($item['x'] ?? 0);
                    $y = self::float($item['y'] ?? 0);
                    $out[] = '<circle cx="'.$n($X($x)).'" cy="'.$n($Y($y)).'" r="3.6" fill="'.(self::COLORS[\is_string($item['color'] ?? null) ? $item['color'] : 'blue'] ?? self::COLORS['blue']).'"/>';
                    if (isset($item['label']) && '' !== self::text($item['label'])) {
                        $out[] = '<text x="'.$n($X($x) + 6).'" y="'.$n($Y($y) - 6).'" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-size="14" fill="'.self::AXIS.'">'.htmlspecialchars(self::text($item['label'])).'</text>';
                    }
                    break;
                case 'label':
                    $out[] = self::label($X(self::float($item['x'] ?? 0)), $Y(self::float($item['y'] ?? 0)), self::text($item['text'] ?? ''), isset($item['sub']) ? self::text($item['sub']) : null, $color);
                    break;
                default:
                    throw new TemplateFormatException('figure.items['.$i.'].draw', 'élément inconnu (attendu : '.implode(', ', array_keys(self::GRID_ITEMS)).')');
            }
        }

        return '<svg class="sgm-fig" viewBox="0 0 '.$n($width).' '.$n($height).'" width="'.$n($width).'" height="'.$n($height).'" role="img" aria-label="Figure">'.implode('', $out).'</svg>';
    }

    private static function tree(float $p, float $q, float $q2): string
    {
        $width = 300;
        $height = 170;
        $s = ['<rect width="'.$width.'" height="'.$height.'" rx="6" fill="'.self::PAPER.'"/>'];
        $text = static function (float $x, float $y, string $t, bool $italic) use (&$s): void {
            $s[] = '<text x="'.$x.'" y="'.$y.'" font-family="'.($italic ? 'Cormorant Garamond, Georgia, serif' : 'IBM Plex Mono, monospace').'" '.($italic ? 'font-style="italic" font-size="16" font-weight="600"' : 'font-size="11"').' fill="'.self::AXIS.'" text-anchor="middle">'.$t.'</text>';
        };
        $line = static function (float $x1, float $y1, float $x2, float $y2) use (&$s): void {
            $s[] = '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="'.self::AXIS.'" stroke-width="1.4"/>';
        };

        $line(20, 85, 120, 42);
        $line(20, 85, 120, 128);
        $text(132, 46, 'A', true);
        $text(132, 133, 'A̅', true);
        $text(62, 52, MathFormat::fmt($p), false);
        $text(62, 128, MathFormat::fmt(MathFormat::round(1 - $p, 2)), false);
        $line(146, 42, 246, 18);
        $line(146, 42, 246, 64);
        $line(146, 128, 246, 106);
        $line(146, 128, 246, 152);
        $text(258, 22, 'B', true);
        $text(258, 68, 'B̅', true);
        $text(258, 110, 'B', true);
        $text(258, 156, 'B̅', true);
        $text(192, 22, MathFormat::fmt($q), false);
        $text(192, 66, MathFormat::fmt(MathFormat::round(1 - $q, 2)), false);
        $text(192, 110, MathFormat::fmt($q2), false);
        $text(192, 152, MathFormat::fmt(MathFormat::round(1 - $q2, 2)), false);

        return '<svg class="sgm-fig" viewBox="0 0 '.$width.' '.$height.'" width="'.$width.'" height="'.$height.'" role="img" aria-label="Arbre pondéré">'.implode('', $s).'</svg>';
    }

    private static function label(float $x, float $y, string $text, ?string $sub, string $color): string
    {
        return '<text x="'.MathFormat::plainNumber($x).'" y="'.MathFormat::plainNumber($y).'" font-family="Cormorant Garamond, Georgia, serif" font-style="italic" font-weight="600" font-size="15" fill="'.$color.'">'
            .htmlspecialchars($text).(null === $sub ? '' : '<tspan font-size="10">'.htmlspecialchars($sub).'</tspan>').'</text>';
    }

    /** @return list<array{0: float, 1: float}> */
    private static function points(mixed $points): array
    {
        if (!\is_array($points)) {
            throw new TemplateFormatException('figure', 'une liste de points [x, y] était attendue');
        }

        $out = [];
        foreach ($points as $point) {
            if (!\is_array($point) || \count($point) < 2) {
                throw new TemplateFormatException('figure', 'un point s’écrit [x, y]');
            }
            $point = array_values($point);
            $out[] = [self::float($point[0]), self::float($point[1])];
        }

        return $out;
    }

    /** @return array{0: float, 1: float} */
    private static function pair(mixed $pair): array
    {
        if (!\is_array($pair) || 2 !== \count($pair)) {
            throw new TemplateFormatException('figure', 'un intervalle de repère s’écrit [min, max]');
        }
        $pair = array_values($pair);

        return [self::float($pair[0]), self::float($pair[1])];
    }

    private static function float(mixed $x): float
    {
        if (!\is_int($x) && !\is_float($x)) {
            throw new TemplateFormatException('figure', 'un nombre était attendu');
        }

        return (float) $x;
    }

    private static function text(mixed $x): string
    {
        return \is_string($x) ? $x : (\is_int($x) || \is_float($x) ? MathFormat::fmt($x) : '');
    }
}
