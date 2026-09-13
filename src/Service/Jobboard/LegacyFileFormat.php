<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Enum\JobboardBtsAccess;
use App\Enum\JobboardContract;
use App\Enum\JobboardCountry;
use App\Enum\JobboardLevelSource;
use App\Enum\JobboardRemote;
use App\Enum\JobboardSource;
use Symfony\Component\Clock\ClockInterface;

/**
 * What « Configuration > Jobboard > Import » accepts, said on the screen that accepts it - plus a
 * two-offer file that goes through it unchanged.
 *
 * The sibling of App\Service\Jobboard\IngestInstructions and generated the same way, from the
 * enums OfferPayloadParser validates against: a format sheet typed by hand is wrong the day a
 * source is added, and wrong on the screen where somebody is about to trust it. What differs is
 * the door it describes - the **legacy** shape of the file the veille already produces (`{meta,
 * offres}`, an `id` prefixed by the source, `date_approx`, `date_reperage`), not the API's.
 *
 * The example is dated from the clock rather than written with fixed dates: a `date_publication`
 * in the future is refused, and a sample file that expires is worse than none.
 */
final readonly class LegacyFileFormat
{
    public const string SAMPLE_FILENAME = 'offres-exemple.json';

    /** A file bigger than this is not a legacy export, it is a mistake. Stated on the screen. */
    public const int MAX_ROWS = 2000;

    public function __construct(private ClockInterface $clock)
    {
    }

    public function markdown(): string
    {
        $sources = implode("\n", array_map(
            static fn (JobboardSource $source): string => \sprintf(
                '| `%s-…` | %s | %s |',
                $source->legacyPrefix(),
                $source->label(),
                $source->refRule(),
            ),
            JobboardSource::cases(),
        ));

        $contracts = $this->quoted(JobboardContract::values());
        $countries = $this->quoted(JobboardCountry::values());
        $remotes = $this->quoted(JobboardRemote::values());
        $levelSources = $this->quoted(JobboardLevelSource::values());
        $btsAccess = $this->quoted(JobboardBtsAccess::values());
        $maxRows = self::MAX_ROWS;

        return <<<MD
            # Le format accepté par l'import

            Un fichier JSON, {$maxRows} offres au plus, dans la forme que la veille produit déjà :

            ```
            {"meta": { … }, "offres": [ { … }, { … } ]}
            ```

            Le bloc `meta` n'est pas lu. Une liste nue (`[ { … } ]`) est acceptée telle quelle.

            La filière n'est **pas** dans le fichier : elle est choisie sur cet écran, et c'est
            volontaire — la déduire du contenu des annonces est précisément ce que cette
            fonctionnalité refuse de faire partout ailleurs.

            ## Les champs d'une offre

            | champ | obligatoire | remarque |
            |---|---|---|
            | `id` | oui | l'identifiant préfixé de la source (`hw-83313525`) — voir le tableau plus bas |
            | `source` | oui | le nom du site, en toutes lettres (`HelloWork`) ou en minuscules (`hellowork`) |
            | `url` | oui | doit appartenir au domaine de la source déclarée |
            | `poste` | oui | l'intitulé tel qu'affiché |
            | `entreprise` | oui | `"Non précisée"` est une valeur acceptée |
            | `categorie` | non | texte libre, votre classement (`sisr`, `slam`, `mixte`, …) |
            | `contrat` | oui | {$contracts} |
            | `pays` | oui | {$countries} |
            | `region` | non | vide pour une offre 100 % télétravail sans ancrage |
            | `departement` | non | **uniquement si `pays` vaut `France`** — `"01"` à `"95"`, `"2A"`, `"2B"`, `"971"` à `"976"`, en chaîne, zéro initial conservé |
            | `ville` | non | `"Télétravail"` ou `"France entière"` sont acceptés |
            | `niveau` | oui | texte libre de l'annonce (`"Bac+2"`, `"Non précisé"`) |
            | `niveau_source` | oui | {$levelSources} — `annonce` = lu sur la page, `estime` = déduit de l'intitulé |
            | `acces_bts` | oui | {$btsAccess} |
            | `teletravail` | oui | {$remotes} |
            | `date_publication` | non | `AAAA-MM-JJ`. Une date absente est une situation normale |
            | `date_approx` | oui | `true` dès que la date est reconstituée d'une ancienneté relative, ou absente |
            | `date_reperage` | non | le jour où la veille a vu l'offre pour la première fois |
            | `note` | non | une précision courte tirée de l'annonce |
            | `brut` | non | la charge utile d'origine |

            Les valeurs peuvent être écrites avec leurs majuscules (`Alternance`, `Non_Precise`) :
            elles sont lues sans tenir compte de la casse.

            **`date_reperage` est le seul champ que rien ne peut reconstituer.** Aucun site ne rend
            la date à laquelle une offre a été vue pour la première fois : si elle manque, c'est le
            jour de l'import qui fait foi, et cette date-là ne bougera plus jamais.

            ## Le préfixe de `id`

            | `id` | source | ce qui suit le préfixe |
            |---|---|---|
            {$sources}

            ## Ce qui est refusé, ligne par ligne

            - une `date_publication` dans le futur ;
            - un `contrat`, `pays`, `teletravail`, `niveau_source`, `acces_bts` ou `source` hors de
              sa liste ;
            - un `niveau_source` absent ;
            - un `departement` renseigné alors que le pays n'est pas la France, ou hors format ;
            - une `url` dont le domaine ne correspond pas à la source déclarée ;
            - un champ obligatoire vide.

            **Une ligne refusée ne fait jamais échouer le fichier** : l'analyse les nomme toutes
            avant que quoi que ce soit ne soit écrit, et les autres offres sont importées.

            Ne sont pas refusés, ce sont des cas normaux : une date de publication absente, une
            ville vide, une entreprise à « Non précisée », une catégorie inconnue.
            MD;
    }

    /**
     * Two offers that go through the import untouched - one complete and dated, one with the holes
     * a real advert has. Downloading it, importing it and reading « 2 créées » is the shortest way
     * to check the whole chain before trusting it with the real file.
     */
    public function sample(): string
    {
        $seen = $this->clock->now();

        $file = [
            'meta' => [
                'genere_le' => $seen->format('Y-m-d'),
                'source' => 'exemple MonCampus',
                'offres' => 2,
            ],
            'offres' => [
                [
                    'id' => 'hw-83313525',
                    'source' => 'HelloWork',
                    'url' => 'https://www.hellowork.com/fr-fr/emplois/83313525.html',
                    'poste' => 'Technicien support informatique (H/F)',
                    'entreprise' => 'Astek',
                    'categorie' => 'sisr',
                    'contrat' => 'Alternance',
                    'pays' => 'France',
                    'region' => 'Nouvelle-Aquitaine',
                    'departement' => '87',
                    'ville' => 'Limoges',
                    'niveau' => 'Bac+2',
                    'niveau_source' => 'annonce',
                    'acces_bts' => 'accessible',
                    'teletravail' => 'partiel',
                    'date_publication' => $seen->modify('-11 days')->format('Y-m-d'),
                    'date_approx' => false,
                    'date_reperage' => $seen->modify('-10 days')->format('Y-m-d'),
                    'note' => 'Contrat de 24 mois, rythme 3 semaines en entreprise / 1 semaine au centre.',
                ],
                [
                    // The holes are the point: no publication date, no company, a level nobody
                    // stated. All four are normal, and all four are what a file made of the first
                    // offer twice would fail to exercise.
                    'id' => 'ft-212WTTG',
                    'source' => 'France Travail',
                    'url' => 'https://candidat.francetravail.fr/offres/recherche/detail/212WTTG',
                    'poste' => 'Développeur web junior (H/F)',
                    'entreprise' => 'Non précisée',
                    'categorie' => 'slam',
                    'contrat' => 'Stage',
                    'pays' => 'France',
                    'region' => 'Nouvelle-Aquitaine',
                    'departement' => '19',
                    'ville' => 'Brive-la-Gaillarde',
                    'niveau' => 'Non précisé',
                    'niveau_source' => 'estime',
                    'acces_bts' => 'accessible',
                    'teletravail' => 'non_precise',
                    'date_publication' => null,
                    'date_approx' => true,
                    'date_reperage' => $seen->modify('-3 days')->format('Y-m-d'),
                ],
            ],
        ];

        return (string) json_encode($file, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(' · ', array_map(static fn (string $value): string => '`'.$value.'`', $values));
    }
}
