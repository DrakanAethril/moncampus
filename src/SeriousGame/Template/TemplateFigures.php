<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

use App\SeriousGame\Math\MathFormat;

/**
 * The figures a template declares (design/validated/serious-games.md §5.2, `figure`), drawn as SVG
 * on the server: a sheet of squared paper on the astronomer's desk.
 *
 * Station Abysse adds three, ported from its conception's generators: `chart` (measurements as
 * broken lines), `web` (a food web) and `cladogram` (a tree of kinship of four species).
 *
 * The first two, ported from the reference's `Fig()` and its weighted tree:
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

    public const array TYPES = ['grid', 'tree', 'chart', 'web', 'cladogram'];

    /** The keys each figure other than a grid reads (a grid's are its items'). */
    public const array KEYS = [
        'tree' => ['p', 'q', 'q2'],
        'chart' => ['x', 'y', 'xstep', 'ystep', 'xlabel', 'ylabel', 'series'],
        'web' => ['levels', 'names', 'edges', 'caption'],
        'cladogram' => ['leaves', 'first', 'second'],
    ];

    /** The colours a chart's series may take, by name. */
    private const array SERIES_COLORS = ['blue' => '#2F6FB0', 'orange' => '#C9702B', 'green' => '#3E8E5E', 'red' => '#B5444A', 'violet' => '#7A5AA0', 'teal' => '#167784'];

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
            'chart' => self::chart($figure),
            'web' => self::web($figure),
            'cladogram' => self::cladogram($figure),
            default => throw new TemplateFormatException('figure.type', 'figure inconnue (attendu : '.implode(', ', self::TYPES).')'),
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

    /**
     * A chart of measurements (Station Abysse's bioreactor, an enzyme, five populations drifting):
     * axes from x[0] to x[1] and y[0] to y[1] graduated every xstep / ystep, their labels, and
     * `series` - each {name, color, points} - drawn as broken lines, named in a legend.
     *
     * @param array<string, mixed> $figure
     */
    private static function chart(array $figure): string
    {
        [$x0, $x1] = self::pair($figure['x'] ?? [0, 10]);
        [$y0, $y1] = self::pair($figure['y'] ?? [0, 10]);
        $xstep = self::float($figure['xstep'] ?? ($x1 - $x0) / 5);
        $ystep = self::float($figure['ystep'] ?? ($y1 - $y0) / 5);
        if ($x1 <= $x0 || $y1 <= $y0 || $xstep <= 0 || $ystep <= 0) {
            throw new TemplateFormatException('figure', 'un graphique a des axes croissants et des pas positifs');
        }
        $width = 440;
        $height = 250;
        [$left, $top, $right, $bottom] = [52, 16, 16, 42];
        $pw = $width - $left - $right;
        $ph = $height - $top - $bottom;
        $n = static fn (float $v): string => MathFormat::plainNumber(MathFormat::round($v, 2));
        $X = static fn (float $x): float => $left + ($x - $x0) / ($x1 - $x0) * $pw;
        $Y = static fn (float $y): float => $top + $ph - (max($y0, min($y1, $y)) - $y0) / ($y1 - $y0) * $ph;
        $mono = static fn (float $x, float $y, string $t, string $anchor = 'middle'): string => '<text x="'.$n($x).'" y="'.$n($y).'" font-family="IBM Plex Mono, monospace" font-size="10" fill="'.self::AXIS.'" text-anchor="'.$anchor.'">'.htmlspecialchars($t).'</text>';

        $out = ['<rect width="'.$width.'" height="'.$height.'" rx="6" fill="'.self::PAPER.'"/>'];
        for ($k = 0; $x0 + $k * $xstep <= $x1 + 1e-9; ++$k) {
            $x = $x0 + $k * $xstep;
            $out[] = '<line x1="'.$n($X($x)).'" y1="'.$top.'" x2="'.$n($X($x)).'" y2="'.($top + $ph).'" stroke="'.self::GRID.'" stroke-width="1"/>';
            $out[] = $mono($X($x), $top + $ph + 14, MathFormat::fmt(MathFormat::round($x, 6)));
        }
        for ($k = 0; $y0 + $k * $ystep <= $y1 + 1e-9; ++$k) {
            $y = $y0 + $k * $ystep;
            $out[] = '<line x1="'.$left.'" y1="'.$n($Y($y)).'" x2="'.($left + $pw).'" y2="'.$n($Y($y)).'" stroke="'.self::GRID.'" stroke-width="1"/>';
            $out[] = $mono($left - 6, $Y($y) + 3.5, MathFormat::fmt(MathFormat::round($y, 6)), 'end');
        }
        $out[] = '<line x1="'.$left.'" y1="'.($top + $ph).'" x2="'.($left + $pw).'" y2="'.($top + $ph).'" stroke="'.self::AXIS.'" stroke-width="1.6"/>';
        $out[] = '<line x1="'.$left.'" y1="'.$top.'" x2="'.$left.'" y2="'.($top + $ph).'" stroke="'.self::AXIS.'" stroke-width="1.6"/>';
        $out[] = $mono($left + $pw / 2, $height - 8, self::text($figure['xlabel'] ?? ''));
        $out[] = '<text x="14" y="'.$n($top + $ph / 2).'" font-family="IBM Plex Mono, monospace" font-size="10" fill="'.self::AXIS.'" text-anchor="middle" transform="rotate(-90 14 '.$n($top + $ph / 2).')">'.htmlspecialchars(self::text($figure['ylabel'] ?? '')).'</text>';

        $legend = 0;
        foreach (\is_array($figure['series'] ?? null) ? array_values($figure['series']) : [] as $i => $series) {
            if (!\is_array($series)) {
                throw new TemplateFormatException('figure.series', 'une série s’écrit { name, color, points }');
            }
            $color = self::SERIES_COLORS[\is_string($series['color'] ?? null) ? $series['color'] : ''] ?? array_values(self::SERIES_COLORS)[$i % \count(self::SERIES_COLORS)];
            $points = self::points($series['points'] ?? []);
            $out[] = '<polyline fill="none" stroke="'.$color.'" stroke-width="2.2" stroke-linejoin="round" points="'.implode(' ', array_map(static fn (array $p): string => $n($X($p[0])).','.$n($Y($p[1])), $points)).'"/>';
            $name = self::text($series['name'] ?? '');
            if ('' !== $name) {
                $out[] = '<text x="'.($left + $pw - 6).'" y="'.($top + 14 + 14 * $legend++).'" font-family="IBM Plex Mono, monospace" font-size="10.5" font-weight="600" fill="'.$color.'" text-anchor="end">'.htmlspecialchars($name).'</text>';
            }
        }

        return '<svg class="sgm-fig" viewBox="0 0 '.$width.' '.$height.'" width="'.$width.'" height="'.$height.'" role="img" aria-label="Graphique">'.implode('', $out).'</svg>';
    }

    /**
     * A food web: `levels` gives each organism's trophic level (0 for a producer), `names` its
     * name, `edges` the arrows [food, eater] - read « est mangé par », as the caption says.
     *
     * @param array<string, mixed> $figure
     */
    private static function web(array $figure): string
    {
        $levels = \is_array($figure['levels'] ?? null) ? $figure['levels'] : [];
        $names = \is_array($figure['names'] ?? null) ? $figure['names'] : [];
        $edges = \is_array($figure['edges'] ?? null) ? $figure['edges'] : [];
        $rows = [];
        foreach ($levels as $key => $level) {
            $rows[(int) self::float($level)][] = (string) $key;
        }
        if ([] === $rows) {
            throw new TemplateFormatException('figure.levels', 'un réseau trophique nomme ses êtres vivants et leur niveau');
        }
        ksort($rows);
        $max = max(array_keys($rows));
        $width = 440;
        $height = 64 + $max * 62;
        $position = [];
        foreach ($rows as $level => $keys) {
            foreach ($keys as $i => $key) {
                $position[$key] = [220 + ($i - (\count($keys) - 1) / 2) * 148, $height - 40 - $level * 62];
            }
        }
        $n = static fn (float $v): string => MathFormat::plainNumber(MathFormat::round($v, 1));

        $out = ['<rect width="'.$width.'" height="'.$height.'" rx="6" fill="'.self::PAPER.'"/>',
            '<defs><marker id="sgm-web-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0 L10 5 L0 10 z" fill="'.self::AXIS.'"/></marker></defs>'];
        foreach ($edges as $edge) {
            if (!\is_array($edge) || 2 !== \count($edge)) {
                throw new TemplateFormatException('figure.edges', 'une flèche s’écrit [aliment, consommateur]');
            }
            [$from, $to] = array_map(static fn (mixed $k): string => \is_string($k) ? $k : '', array_values($edge));
            if (!isset($position[$from], $position[$to])) {
                throw new TemplateFormatException('figure.edges', 'flèche vers un être vivant absent du réseau');
            }
            [$ax, $ay] = $position[$from];
            [$bx, $by] = $position[$to];
            $d = sqrt(($bx - $ax) ** 2 + ($by - $ay) ** 2) ?: 1.0;
            [$ux, $uy] = [($bx - $ax) / $d, ($by - $ay) / $d];
            $out[] = '<line x1="'.$n($ax + $ux * 16).'" y1="'.$n($ay + $uy * 16).'" x2="'.$n($bx - $ux * 17).'" y2="'.$n($by - $uy * 17).'" stroke="'.self::AXIS.'" stroke-width="1.6" marker-end="url(#sgm-web-arrow)"/>';
        }
        foreach ($position as $key => [$x, $y]) {
            $producer = 0 === (int) self::float($levels[$key]);
            $out[] = '<rect x="'.$n($x - 68).'" y="'.$n($y - 13).'" width="136" height="26" rx="13" fill="'.($producer ? '#3E8E5E' : '#167784').'"/>';
            $out[] = '<text x="'.$n($x).'" y="'.$n($y + 4.5).'" font-family="IBM Plex Sans, Source Sans 3, sans-serif" font-size="11.5" fill="#FFFFFF" text-anchor="middle">'.htmlspecialchars(self::text($names[$key] ?? $key)).'</text>';
        }
        $out[] = '<text x="10" y="'.($height - 8).'" font-family="IBM Plex Mono, monospace" font-size="10" fill="'.self::AXIS.'">'.htmlspecialchars(self::text($figure['caption'] ?? '→ signifie « est mangé par »')).'</text>';

        return '<svg class="sgm-fig" viewBox="0 0 '.$width.' '.$height.'" width="'.$width.'" height="'.$height.'" role="img" aria-label="Réseau trophique">'.implode('', $out).'</svg>';
    }

    /**
     * A tree of kinship for four species: `leaves` [outgroup, C, A, B] - A and B the closest
     * relatives -, `first` the characters gained on the stem below C, `second` those gained below
     * A and B, each marked by a tick.
     *
     * @param array<string, mixed> $figure
     */
    private static function cladogram(array $figure): string
    {
        $leaves = \is_array($figure['leaves'] ?? null) ? array_values($figure['leaves']) : [];
        if (4 !== \count($leaves)) {
            throw new TemplateFormatException('figure.leaves', 'un arbre de parenté porte quatre espèces');
        }
        $leafX = [50, 150, 250, 350];
        $nodes = [[80, 186], [168, 126], [260, 70]];
        $stroke = ' stroke="'.self::AXIS.'" stroke-width="2.5"';
        $out = ['<rect width="400" height="226" rx="6" fill="'.self::PAPER.'"/>',
            '<line x1="40" y1="210" x2="'.$nodes[2][0].'" y2="'.$nodes[2][1].'"'.$stroke.'/>',
            '<line x1="'.$nodes[0][0].'" y1="'.$nodes[0][1].'" x2="'.$leafX[0].'" y2="38"'.$stroke.'/>',
            '<line x1="'.$nodes[1][0].'" y1="'.$nodes[1][1].'" x2="'.$leafX[1].'" y2="38"'.$stroke.'/>',
            '<line x1="'.$nodes[2][0].'" y1="'.$nodes[2][1].'" x2="'.$leafX[2].'" y2="38"'.$stroke.'/>',
            '<line x1="'.$nodes[2][0].'" y1="'.$nodes[2][1].'" x2="'.$leafX[3].'" y2="38"'.$stroke.'/>'];
        foreach ($leaves as $i => $leaf) {
            $out[] = '<text x="'.$leafX[$i].'" y="26" font-family="IBM Plex Sans, Source Sans 3, sans-serif" font-size="12.5" font-weight="600" fill="'.self::AXIS.'" text-anchor="middle">'.htmlspecialchars(self::text($leaf)).'</text>';
        }
        $tick = static function (array $a, array $b, mixed $labels) use (&$out): void {
            [$mx, $my] = [($a[0] + $b[0]) / 2, ($a[1] + $b[1]) / 2];
            $out[] = '<line x1="'.($mx - 7).'" y1="'.($my - 7).'" x2="'.($mx + 7).'" y2="'.($my + 7).'" stroke="#B5444A" stroke-width="3"/>';
            foreach (\is_array($labels) ? array_values($labels) : [] as $k => $label) {
                $out[] = '<text x="'.($mx + 14).'" y="'.($my + 14 + $k * 13).'" font-family="IBM Plex Sans, Source Sans 3, sans-serif" font-size="11" fill="'.self::AXIS.'">'.htmlspecialchars(self::text($label)).'</text>';
            }
        };
        $tick($nodes[0], $nodes[1], $figure['first'] ?? []);
        $tick($nodes[1], $nodes[2], $figure['second'] ?? []);

        return '<svg class="sgm-fig" viewBox="0 0 400 226" width="400" height="226" role="img" aria-label="Arbre de parenté">'.implode('', $out).'</svg>';
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
