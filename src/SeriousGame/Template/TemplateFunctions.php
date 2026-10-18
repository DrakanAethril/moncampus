<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

use App\SeriousGame\Answer\IntervalChecker;
use App\SeriousGame\Math\MathFormat;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

/**
 * The library of functions a question template may call (design/validated/serious-games.md §5.2) -
 * and nothing else: no PHP, no network, which is what lets an administrator change a template
 * through the connector.
 *
 * The catalogue is written once, here, with a French description per function: the interpreter
 * registers it, and the connector's `format_guide` prints it (catalogue()), so the guide and the
 * engine cannot drift apart.
 *
 * Three families, as the format describes them:
 * - **draws** (`int`, `pick`, `nonZero`…): only in `params`, from the question's seeded draw;
 * - **calculation** on numbers and lists (`gcd`, `median`, `map`…);
 * - **writing** (`fmt`, `frac`, `pow`, `poly`, `table`…), producing the restricted HTML of a
 *   statement.
 *
 * map(), filter(), any(), all(), find() and sortBy() take their per-item rule as an expression in a
 * string, evaluated with `item` and `index` bound - ExpressionLanguage has no lambdas.
 */
final class TemplateFunctions implements ExpressionFunctionProviderInterface
{
    public const string CONTEXT = '__ctx';

    /**
     * @return list<ExpressionFunction>
     */
    public function getFunctions(): array
    {
        $functions = [];
        foreach ($this->definitions() as $name => [$callable]) {
            $functions[] = new ExpressionFunction(
                $name,
                static fn (): string => throw new \LogicException('Templates are evaluated, never compiled.'),
                static function (array $variables, mixed ...$arguments) use ($callable): mixed {
                    $context = $variables[self::CONTEXT] ?? null;
                    if (!$context instanceof TemplateContext) {
                        throw new \LogicException('A template function was called outside a template.');
                    }

                    return $callable($context, $variables, ...$arguments);
                },
            );
        }

        return $functions;
    }

    /**
     * The catalogue as the format guide prints it: function => [family, signature, description].
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public function catalogue(): array
    {
        $out = [];
        foreach ($this->definitions() as $name => [, $family, $signature, $description]) {
            $out[$name] = [$family, $signature, $description];
        }

        return $out;
    }

    /**
     * @return array<string, array{0: \Closure, 1: string, 2: string, 3: string}>
     */
    private function definitions(): array
    {
        return [
            // --- Tirages ---------------------------------------------------------------------
            'int' => [static fn (TemplateContext $c, array $v, mixed $a, mixed $b): int => $c->random('int')->int(self::integer($a), self::integer($b)),
                'tirage', 'int(a, b)', 'Entier tiré entre a et b, bornes comprises.'],
            'nonZero' => [static function (TemplateContext $c, array $v, mixed $a, mixed $b): int {
                $random = $c->random('nonZero');
                do {
                    $n = $random->int(self::integer($a), self::integer($b));
                } while (0 === $n);

                return $n;
            }, 'tirage', 'nonZero(a, b)', 'Entier non nul tiré entre a et b.'],
            'pick' => [static fn (TemplateContext $c, array $v, mixed $items): mixed => $c->random('pick')->pick(self::list($items)),
                'tirage', 'pick(liste)', 'Un élément de la liste, tiré au hasard (répéter un élément le rend plus probable).'],
            'shuffle' => [static fn (TemplateContext $c, array $v, mixed $items): array => $c->random('shuffle')->shuffle(self::list($items)),
                'tirage', 'shuffle(liste)', 'La liste mélangée.'],
            'sample' => [static fn (TemplateContext $c, array $v, mixed $items, mixed $n): array => \array_slice($c->random('sample')->shuffle(self::list($items)), 0, self::integer($n)),
                'tirage', 'sample(liste, n)', 'n éléments distincts de la liste, tirés au hasard.'],
            'random' => [static fn (TemplateContext $c, array $v): float => $c->random('random')->float(),
                'tirage', 'random()', 'Nombre tiré dans [0 ; 1[.'],
            'chance' => [static fn (TemplateContext $c, array $v, mixed $p): bool => $c->random('chance')->float() < self::number($p),
                'tirage', 'chance(p)', 'Vrai avec la probabilité p (chance(0.6) : vrai six fois sur dix).'],
            'sign' => [static fn (TemplateContext $c, array $v): int => $c->random('sign')->pick([1, -1]),
                'tirage', 'sign()', '1 ou −1, au hasard.'],
            'walk' => [static function (TemplateContext $c, array $v, mixed $x0, mixed $x1, mixed $lo, mixed $hi, mixed $maxStep): array {
                $random = $c->random('walk');
                [$x0, $x1, $lo, $hi, $maxStep] = array_map(self::integer(...), [$x0, $x1, $lo, $hi, $maxStep]);
                $points = [];
                $y = $random->int($lo, $hi);
                for ($x = $x0; $x <= $x1; ++$x) {
                    $points[] = [$x, $y];
                    $y = max($lo, min($hi, $y + $random->int(-$maxStep, $maxStep)));
                }

                return $points;
            }, 'tirage', 'walk(x0, x1, ymin, ymax, pas)', 'Points [x, y] d’une ligne brisée aux abscisses entières de x0 à x1, ordonnée entre ymin et ymax, variant d’au plus « pas » d’un point au suivant.'],
            'crossings' => [static function (TemplateContext $c, array $v, mixed $solutions, mixed $k, mixed $x0, mixed $x1, mixed $ymin, mixed $ymax, mixed $flip): array {
                $random = $c->random('crossings');
                $solutions = array_map(self::integer(...), self::list($solutions));
                [$k, $x0, $x1, $ymin, $ymax] = array_map(self::integer(...), [$k, $x0, $x1, $ymin, $ymax]);
                $side = $random->float() < .5 ? 1 : -1;
                $points = [];
                for ($x = $x0; $x <= $x1; ++$x) {
                    if (\in_array($x, $solutions, true)) {
                        $points[] = [$x, $k];
                        if ($random->float() < self::number($flip)) {
                            $side = -$side;
                        }

                        continue;
                    }
                    $points[] = [$x, $k + $side * $random->int(1, 3)];
                }
                foreach ($points as $i => [$x, $y]) {
                    $y = max($ymin, min($ymax, $y));
                    if (!\in_array($x, $solutions, true) && $y === $k) {
                        $y = $k + ($k < $ymax ? 1 : -1);
                    }
                    $points[$i] = [$x, $y];
                }

                return $points;
            }, 'tirage', 'crossings(solutions, k, x0, x1, ymin, ymax, pBascule)', 'Points [x, y] d’une ligne brisée qui touche y = k exactement aux abscisses de « solutions » et s’en écarte de 1 à 3 ailleurs ; après chaque solution elle change de côté avec la probabilité pBascule. Les ordonnées restent dans [ymin ; ymax].'],

            // --- Calcul ----------------------------------------------------------------------
            'abs' => [static fn (TemplateContext $c, array $v, mixed $x): int|float => abs(self::numeric($x)), 'calcul', 'abs(x)', 'Valeur absolue.'],
            'min' => [static fn (TemplateContext $c, array $v, mixed ...$xs): int|float => min(array_map(self::numeric(...), self::flatten($xs))), 'calcul', 'min(a, b, …)', 'Le plus petit (accepte aussi une liste).'],
            'max' => [static fn (TemplateContext $c, array $v, mixed ...$xs): int|float => max(array_map(self::numeric(...), self::flatten($xs))), 'calcul', 'max(a, b, …)', 'Le plus grand (accepte aussi une liste).'],
            'floor' => [static fn (TemplateContext $c, array $v, mixed $x): int => (int) floor(self::number($x)), 'calcul', 'floor(x)', 'Partie entière (arrondi vers le bas).'],
            'ceil' => [static fn (TemplateContext $c, array $v, mixed $x): int => (int) ceil(self::number($x)), 'calcul', 'ceil(x)', 'Arrondi vers le haut.'],
            'sqrt' => [static fn (TemplateContext $c, array $v, mixed $x): float => sqrt(self::number($x)), 'calcul', 'sqrt(x)', 'Racine carrée.'],
            'ln' => [static fn (TemplateContext $c, array $v, mixed $x): float => self::number($x) > 0 ? log(self::number($x)) : \NAN, 'calcul', 'ln(x)', 'Logarithme népérien (valeur d’une réponse de type « power »).'],
            'round' => [static fn (TemplateContext $c, array $v, mixed $x, mixed $n = 9): float => MathFormat::round(self::number($x), self::integer($n)), 'calcul', 'round(x, n = 9)', 'Arrondi à n décimales (demi vers le haut).'],
            'mod' => [static fn (TemplateContext $c, array $v, mixed $a, mixed $b): float => fmod(self::number($a), self::number($b)), 'calcul', 'mod(a, b)', 'Reste de a par b, décimaux compris (l’opérateur % ne travaille que sur des entiers).'],
            'gcd' => [static fn (TemplateContext $c, array $v, mixed $a, mixed $b): int => MathFormat::gcd(self::numeric($a), self::numeric($b)), 'calcul', 'gcd(a, b)', 'PGCD (1 pour gcd(0, 0)).'],
            'lcm' => [static fn (TemplateContext $c, array $v, mixed $a, mixed $b): int => MathFormat::lcm(self::numeric($a), self::numeric($b)), 'calcul', 'lcm(a, b)', 'PPCM.'],
            'isInt' => [static fn (TemplateContext $c, array $v, mixed $x): bool => MathFormat::isInt(self::number($x)), 'calcul', 'isInt(x)', 'Vrai si x est entier (à 1e-9 près).'],
            'decimals' => [static fn (TemplateContext $c, array $v, mixed $x): int => MathFormat::decimals(self::number($x)), 'calcul', 'decimals(x)', 'Nombre de décimales de x.'],
            'isFinite' => [static fn (TemplateContext $c, array $v, mixed $x): bool => is_finite(self::number($x)), 'calcul', 'isFinite(x)', 'Vrai si x est un nombre fini.'],
            'inf' => [static fn (TemplateContext $c, array $v): float => \INF, 'calcul', 'inf()', 'L’infini (−inf() pour moins l’infini).'],
            'count' => [static fn (TemplateContext $c, array $v, mixed $items): int => \count(self::list($items)), 'calcul', 'count(liste)', 'Nombre d’éléments.'],
            'sum' => [static fn (TemplateContext $c, array $v, mixed $items): int|float => array_sum(array_map(self::numeric(...), self::list($items))), 'calcul', 'sum(liste)', 'Somme.'],
            'mean' => [static fn (TemplateContext $c, array $v, mixed $items): float => self::mean(array_map(self::numeric(...), self::list($items))), 'calcul', 'mean(liste)', 'Moyenne.'],
            'median' => [static fn (TemplateContext $c, array $v, mixed $items): float => self::median(array_map(self::numeric(...), self::list($items))), 'calcul', 'median(liste)', 'Médiane.'],
            'sorted' => [static function (TemplateContext $c, array $v, mixed $items): array {
                $sorted = array_map(self::numeric(...), self::list($items));
                sort($sorted);

                return $sorted;
            }, 'calcul', 'sorted(liste)', 'La liste de nombres rangée dans l’ordre croissant.'],
            'reversed' => [static fn (TemplateContext $c, array $v, mixed $items): array => array_reverse(self::list($items)), 'calcul', 'reversed(liste)', 'La liste à l’envers.'],
            'slice' => [static fn (TemplateContext $c, array $v, mixed $items, mixed $start, mixed $length = null): array => \array_slice(self::list($items), self::integer($start), null === $length ? null : self::integer($length)),
                'calcul', 'slice(liste, début, longueur?)', 'Une partie de la liste (début compté à partir de 0, négatif depuis la fin).'],
            'concat' => [static fn (TemplateContext $c, array $v, mixed ...$lists): array => array_merge(...array_map(self::list(...), $lists)), 'calcul', 'concat(l1, l2, …)', 'Les listes mises bout à bout.'],
            'append' => [static fn (TemplateContext $c, array $v, mixed $items, mixed ...$values): array => [...self::list($items), ...$values], 'calcul', 'append(liste, x, …)', 'La liste avec x ajouté à la fin.'],
            'indexOf' => [static function (TemplateContext $c, array $v, mixed $items, mixed $x): int {
                foreach (self::list($items) as $i => $item) {
                    if (self::equal($item, $x)) {
                        return $i;
                    }
                }

                return -1;
            }, 'calcul', 'indexOf(liste, x)', 'Rang de x dans la liste (à partir de 0), −1 s’il n’y est pas.'],
            'has' => [static fn (TemplateContext $c, array $v, mixed $items, mixed $x): bool => [] !== array_filter(self::list($items), static fn (mixed $item): bool => self::equal($item, $x)),
                'calcul', 'has(liste, x)', 'Vrai si x est dans la liste.'],
            'unique' => [static function (TemplateContext $c, array $v, mixed $items): array {
                $out = [];
                foreach (self::list($items) as $item) {
                    if ([] === array_filter($out, static fn (mixed $o): bool => self::equal($o, $item))) {
                        $out[] = $item;
                    }
                }

                return $out;
            }, 'calcul', 'unique(liste)', 'La liste sans ses doublons.'],
            'range' => [static fn (TemplateContext $c, array $v, mixed $a, mixed $b, mixed $step = 1): array => range(self::numeric($a), self::numeric($b), self::numeric($step)),
                'calcul', 'range(a, b, pas = 1)', 'Les nombres de a à b.'],
            'fill' => [static fn (TemplateContext $c, array $v, mixed $n, mixed $x): array => array_fill(0, max(0, self::integer($n)), $x), 'calcul', 'fill(n, x)', 'Une liste de n fois x.'],
            'at' => [static function (TemplateContext $c, array $v, mixed $items, mixed $i): mixed {
                $items = self::list($items);
                $i = self::integer($i);

                return $items[$i < 0 ? \count($items) + $i : $i] ?? null;
            }, 'calcul', 'at(liste, i)', 'L’élément de rang i (à partir de 0 ; −1 pour le dernier).'],
            'first' => [static fn (TemplateContext $c, array $v, mixed $items): mixed => self::list($items)[0] ?? null, 'calcul', 'first(liste)', 'Le premier élément.'],
            'last' => [static function (TemplateContext $c, array $v, mixed $items): mixed {
                $items = self::list($items);

                return [] === $items ? null : $items[\count($items) - 1];
            }, 'calcul', 'last(liste)', 'Le dernier élément.'],
            'map' => [static fn (TemplateContext $c, array $v, mixed $items, mixed $rule): array => array_map(static fn (mixed $item, int $i): mixed => self::apply($c, $v, $rule, $item, $i), self::list($items), array_keys(self::list($items))),
                'calcul', "map(liste, 'règle')", "La liste transformée : la règle est une expression où « item » est l’élément et « index » son rang. map([1, 2], 'item * 10') vaut [10, 20]."],
            'filter' => [static fn (TemplateContext $c, array $v, mixed $items, mixed $rule): array => array_values(array_filter(self::list($items), static fn (mixed $item, int $i): bool => true === self::apply($c, $v, $rule, $item, $i), \ARRAY_FILTER_USE_BOTH)),
                'calcul', "filter(liste, 'condition')", 'Les éléments pour lesquels la condition (sur « item » et « index ») est vraie.'],
            'find' => [static function (TemplateContext $c, array $v, mixed $items, mixed $rule): mixed {
                foreach (self::list($items) as $i => $item) {
                    if (true === self::apply($c, $v, $rule, $item, $i)) {
                        return $item;
                    }
                }

                return null;
            }, 'calcul', "find(liste, 'condition')", 'Le premier élément qui vérifie la condition, null sinon.'],
            'any' => [static function (TemplateContext $c, array $v, mixed $items, mixed $rule): bool {
                foreach (self::list($items) as $i => $item) {
                    if (true === self::apply($c, $v, $rule, $item, $i)) {
                        return true;
                    }
                }

                return false;
            }, 'calcul', "any(liste, 'condition')", 'Vrai si au moins un élément vérifie la condition.'],
            'all' => [static function (TemplateContext $c, array $v, mixed $items, mixed $rule): bool {
                foreach (self::list($items) as $i => $item) {
                    if (true !== self::apply($c, $v, $rule, $item, $i)) {
                        return false;
                    }
                }

                return true;
            }, 'calcul', "all(liste, 'condition')", 'Vrai si tous les éléments vérifient la condition.'],
            'sortBy' => [static function (TemplateContext $c, array $v, mixed $items, mixed $rule): array {
                $items = self::list($items);
                $keys = array_map(static fn (mixed $item, int $i): mixed => self::apply($c, $v, $rule, $item, $i), $items, array_keys($items));
                array_multisort($keys, $items);

                return $items;
            }, 'calcul', "sortBy(liste, 'clé')", 'La liste rangée selon la clé calculée pour chaque élément.'],

            // --- Textes ----------------------------------------------------------------------
            'str' => [static fn (TemplateContext $c, array $v, mixed $x): string => \is_float($x) || \is_int($x) ? MathFormat::plainNumber($x) : (\is_scalar($x) ? (string) $x : ''), 'texte', 'str(x)', 'Le nombre écrit tel quel, avec un point décimal (pour une valeur à relire, pas pour l’élève).'],
            'lower' => [static fn (TemplateContext $c, array $v, mixed $s): string => mb_strtolower(self::string($s)), 'texte', 'lower(texte)', 'En minuscules.'],
            'upper' => [static fn (TemplateContext $c, array $v, mixed $s): string => mb_strtoupper(self::string($s)), 'texte', 'upper(texte)', 'En majuscules.'],
            'lcfirst' => [static fn (TemplateContext $c, array $v, mixed $s): string => mb_strtolower(mb_substr(self::string($s), 0, 1)).mb_substr(self::string($s), 1), 'texte', 'lcfirst(texte)', 'Première lettre en minuscule.'],
            'ucfirst' => [static fn (TemplateContext $c, array $v, mixed $s): string => mb_strtoupper(mb_substr(self::string($s), 0, 1)).mb_substr(self::string($s), 1), 'texte', 'ucfirst(texte)', 'Première lettre en majuscule.'],
            'replace' => [static fn (TemplateContext $c, array $v, mixed $s, mixed $a, mixed $b): string => str_replace(self::string($a), self::string($b), self::string($s)), 'texte', 'replace(texte, a, b)', 'Remplace a par b.'],
            'join' => [static fn (TemplateContext $c, array $v, mixed $items, mixed $separator = ', '): string => implode(self::string($separator), array_map(static fn (mixed $x): string => \is_int($x) || \is_float($x) ? MathFormat::fmt($x) : self::string($x), self::list($items))), 'texte', "join(liste, séparateur = ', ')", 'Les éléments écrits et joints (les nombres à la française).'],
            'esc' => [static fn (TemplateContext $c, array $v, mixed $s): string => htmlspecialchars(self::string($s), \ENT_NOQUOTES), 'texte', 'esc(texte)', 'Le texte avec < > & échappés (un signe < dans un énoncé s’écrit esc(\'<\')).'],
            'de' => [static fn (TemplateContext $c, array $v, mixed $w): string => MathFormat::de(self::string($w)), 'texte', 'de(mot)', '« de » + mot, élidé devant une voyelle (« d’élèves »).'],
            'la' => [static fn (TemplateContext $c, array $v, mixed $w): string => MathFormat::la(self::string($w)), 'texte', 'la(mot)', '« la » + mot, élidé devant une voyelle (« l’aire »).'],

            // --- Écriture mathématique -------------------------------------------------------
            'fmt' => [static fn (TemplateContext $c, array $v, mixed $x): string => MathFormat::fmt($x), 'écriture', 'fmt(x)', 'Nombre à la française : virgule, vrai signe moins, espace fine entre les milliers.'],
            'pct' => [static fn (TemplateContext $c, array $v, mixed $x): string => MathFormat::pct(self::number($x)), 'écriture', 'pct(x)', '« 12,5 % ».'],
            'signedPct' => [static fn (TemplateContext $c, array $v, mixed $x): string => (self::number($x) > 0 ? '+' : '').MathFormat::pct(self::number($x)), 'écriture', 'signedPct(x)', '« +12,5 % » ou « −20 % ».'],
            'signedApproxPct' => [static function (TemplateContext $c, array $v, mixed $x): string {
                $text = MathFormat::approxPct(self::number($x));
                if (self::number($x) <= 0) {
                    return $text;
                }

                return str_starts_with($text, '≈') ? str_replace('≈'.MathFormat::NBSP, '≈'.MathFormat::NBSP.'+', $text) : '+'.$text;
            }, 'écriture', 'signedApproxPct(x)', 'Comme approxPct(x), avec « + » devant un pourcentage positif : « +25 % », « ≈ +33,3 % ».'],
            'approx' => [static fn (TemplateContext $c, array $v, mixed $x): string => MathFormat::approx(self::number($x)), 'écriture', 'approx(x)', 'Exact s’il a au plus deux décimales, « ≈ » arrondi à 0,1 sinon.'],
            'approxPct' => [static fn (TemplateContext $c, array $v, mixed $x): string => MathFormat::approxPct(self::number($x)), 'écriture', 'approxPct(x)', 'Pourcentage exact s’il a au plus une décimale, « ≈ » arrondi à 0,1 % sinon.'],
            'frac' => [static fn (TemplateContext $c, array $v, mixed $n, mixed $d): string => MathFormat::frac(self::numeric($n), self::numeric($d)), 'écriture', 'frac(n, d)', 'Fraction n/d réduite, écrite en entier si elle en est un.'],
            'fracText' => [static fn (TemplateContext $c, array $v, mixed $n, mixed $d): string => MathFormat::fracText(self::numeric($n), self::numeric($d)), 'écriture', 'fracText(n, d)', 'La même fraction réduite, tapée : « −3/4 ».'],
            'fracRaw' => [static fn (TemplateContext $c, array $v, mixed $n, mixed $d): string => MathFormat::stacked(self::piece($n), self::piece($d)), 'écriture', 'fracRaw(numérateur, dénominateur)', 'Une fraction écrite telle quelle, jamais réduite ; numérateur et dénominateur peuvent être du texte.'],
            'num' => [static fn (TemplateContext $c, array $v, mixed $n, mixed $d): string => MathFormat::num(self::numeric($n), self::numeric($d)), 'écriture', 'num(n, d)', 'La valeur n/d en décimal court si elle en est un, en fraction sinon.'],
            'pow' => [static fn (TemplateContext $c, array $v, mixed $b, mixed $e): string => MathFormat::sup(self::piece($b), \is_string($e) ? $e : self::numeric($e)), 'écriture', 'pow(base, exposant)', 'base<sup>exposant</sup> (l’exposant peut être du texte : pow(\'a\', \'m+n\')).'],
            'sub' => [static fn (TemplateContext $c, array $v, mixed $b, mixed $i): string => self::piece($b).'<sub>'.self::piece($i).'</sub>', 'écriture', 'sub(base, indice)', 'base<sub>indice</sub>.'],
            'v' => [static fn (TemplateContext $c, array $v, mixed $name): string => MathFormat::variable(self::string($name)), 'écriture', 'v(nom)', 'Une lettre en italique : v(\'x\').'],
            'par' => [static fn (TemplateContext $c, array $v, mixed $s): string => '('.self::piece($s).')', 'écriture', 'par(texte)', 'Entre parenthèses.'],
            'poly' => [static fn (TemplateContext $c, array $v, mixed $coefficients, mixed $plain = false): string => MathFormat::poly(array_map(self::numeric(...), self::list($coefficients)), true === $plain), 'écriture', 'poly([a, b, c], tapé = false)', 'Le polynôme de coefficients donnés du plus haut degré au plus bas : poly([3, -1, 2]) écrit 3x² − x + 2 ; tapé = true l’écrit sans italique, comme l’élève le tape.'],
            'lin' => [static fn (TemplateContext $c, array $v, mixed $a, mixed $b, mixed $plain = false): string => MathFormat::poly([self::numeric($a), self::numeric($b)], true === $plain), 'écriture', 'lin(a, b, tapé = false)', 'ax + b, écrit comme poly([a, b]).'],
            'polyValue' => [static fn (TemplateContext $c, array $v, mixed $coefficients): string => MathFormat::polyValue(array_map(self::numeric(...), self::list($coefficients))), 'écriture', 'polyValue([a, b, c])', 'Le même polynôme en expression lisible par le correcteur : valeur d’une réponse « expression » ou « polynomial ».'],
            'setHtml' => [static fn (TemplateContext $c, array $v, mixed $values, mixed $shown = null): string => MathFormat::setHtml(array_map(self::numeric(...), self::list($values)), null === $shown ? null : array_map(self::string(...), self::list($shown))), 'écriture', 'setHtml(valeurs, écritures?)', '« S = { −3 ; 2 } », rangé, ou « S = ∅ » ; « écritures » donne l’écriture de chaque valeur, dans l’ordre des valeurs.'],
            'setText' => [static fn (TemplateContext $c, array $v, mixed $values): string => MathFormat::setText(array_map(self::numeric(...), self::list($values))), 'écriture', 'setText(valeurs)', '« −3 ; 2 », ou « ∅ ».'],
            'sci' => [static fn (TemplateContext $c, array $v, mixed $m, mixed $e): string => MathFormat::sci(self::number($m), self::integer($e)), 'écriture', 'sci(m, e)', '« 3,2 × 10<sup>−4</sup> ».'],
            'sciText' => [static fn (TemplateContext $c, array $v, mixed $m, mixed $e): string => MathFormat::sciText(self::number($m), self::integer($e)), 'écriture', 'sciText(m, e)', 'La même notation tapée : « 3,2 × 10^-4 ».'],
            'sciNumber' => [static fn (TemplateContext $c, array $v, mixed $m, mixed $e): array => ['m' => MathFormat::round(self::number($m)), 'e' => self::integer($e)], 'écriture', 'sciNumber(m, e)', 'La valeur d’une réponse « scientific ».'],
            'iv' => [static fn (TemplateContext $c, array $v, mixed $lo, mixed $hi, mixed $lc = true, mixed $hc = true): array => IntervalChecker::make(self::number($lo), self::number($hi), true === $lc, true === $hc), 'écriture', 'iv(min, max, ferméÀGauche = true, ferméÀDroite = true)', 'La valeur d’une réponse « interval » ; une borne infinie est toujours ouverte.'],
            'ivOp' => [static fn (TemplateContext $c, array $v, mixed $op, mixed $k): array => self::intervalOf(self::string($op), self::number($k)), 'écriture', "ivOp('≤', k)", "L’intervalle des x tels que « x op k », op parmi '<', '≤', '>', '≥'."],
            'ivHtml' => [static fn (TemplateContext $c, array $v, mixed $interval, mixed $loShown = null, mixed $hiShown = null): string => MathFormat::intervalHtml(self::interval($interval), null === $loShown ? null : self::string($loShown), null === $hiShown ? null : self::string($hiShown)), 'écriture', 'ivHtml(intervalle, écritureMin?, écritureMax?)', '« ]−∞ ; 4] » ; les écritures remplacent celles des bornes (une fraction, par exemple).'],
            'table' => [static fn (TemplateContext $c, array $v, mixed $rows, mixed $headerRow = false): string => MathFormat::table(array_map(self::list(...), self::list($rows)), true === $headerRow), 'écriture', 'table(lignes, ligneEnTête = false)', 'Un petit tableau : chaque ligne est une liste dont le premier élément est l’en-tête de ligne.'],
            'plain' => [static fn (TemplateContext $c, array $v, mixed $html): string => MathFormat::plain(self::string($html)), 'écriture', 'plain(html)', 'Le texte tapé d’une écriture : fractions en a/b, exposants en ^n, balises retirées.'],
        ];
    }

    /**
     * @param array<string, mixed> $variables
     */
    private static function apply(TemplateContext $context, array $variables, mixed $rule, mixed $item, int $index): mixed
    {
        return $context->language->evaluate(self::string($rule), ['item' => $item, 'index' => $index] + $variables);
    }

    /** @return array{lo: float, hi: float, lc: bool, hc: bool} */
    private static function intervalOf(string $op, float $k): array
    {
        return match ($op) {
            '<' => IntervalChecker::make(-\INF, $k, false, false),
            '≤', '<=' => IntervalChecker::make(-\INF, $k, false, true),
            '>' => IntervalChecker::make($k, \INF, false, false),
            '≥', '>=' => IntervalChecker::make($k, \INF, true, false),
            default => throw new TemplateFormatException('', 'ivOp() : opérateur inconnu « '.$op.' »'),
        };
    }

    /** @return array{lo: float, hi: float, lc: bool, hc: bool} */
    private static function interval(mixed $value): array
    {
        if (!\is_array($value) || !isset($value['lo'], $value['hi'])) {
            throw new TemplateFormatException('', 'un intervalle était attendu (iv(…) ou ivOp(…))');
        }

        return IntervalChecker::make(self::number($value['lo']), self::number($value['hi']), true === ($value['lc'] ?? false), true === ($value['hc'] ?? false));
    }

    private static function number(mixed $x): float
    {
        if (\is_bool($x)) {
            return $x ? 1.0 : 0.0;
        }

        if (!\is_int($x) && !\is_float($x)) {
            throw new TemplateFormatException('', 'un nombre était attendu, '.get_debug_type($x).' reçu');
        }

        return (float) $x;
    }

    private static function numeric(mixed $x): int|float
    {
        return \is_int($x) ? $x : self::number($x);
    }

    private static function integer(mixed $x): int
    {
        return (int) MathFormat::jsRound(self::number($x));
    }

    private static function string(mixed $x): string
    {
        if (\is_string($x)) {
            return $x;
        }

        if (\is_int($x) || \is_float($x)) {
            return MathFormat::fmt($x);
        }

        return \is_bool($x) || null === $x ? '' : throw new TemplateFormatException('', 'un texte était attendu, '.get_debug_type($x).' reçu');
    }

    /** A number written the French way, a text as it is. */
    private static function piece(mixed $x): string
    {
        return \is_int($x) || \is_float($x) ? MathFormat::fmt($x) : self::string($x);
    }

    /** @return list<mixed> */
    private static function list(mixed $x): array
    {
        if (!\is_array($x)) {
            throw new TemplateFormatException('', 'une liste était attendue, '.get_debug_type($x).' reçu');
        }

        return array_values($x);
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    private static function flatten(array $values): array
    {
        return 1 === \count($values) && \is_array($values[0]) ? array_values($values[0]) : $values;
    }

    private static function equal(mixed $a, mixed $b): bool
    {
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            return abs($a - $b) < 1e-9;
        }

        return $a === $b;
    }

    /** @param list<int|float> $values */
    private static function mean(array $values): float
    {
        return [] === $values ? \NAN : array_sum($values) / \count($values);
    }

    /** @param list<int|float> $values */
    private static function median(array $values): float
    {
        if ([] === $values) {
            return \NAN;
        }

        sort($values);
        $n = \count($values);

        return 1 === $n % 2 ? (float) $values[intdiv($n - 1, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
