<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardSource;
use App\Enum\JobboardBtsAccess;
use App\Enum\JobboardContract;
use App\Enum\JobboardCountry;
use App\Enum\JobboardLevelSource;
use App\Enum\JobboardRemote;
use App\Repository\JobboardSourceRepository;

/**
 * The instruction sheet an administrator copies into the collecting agent.
 *
 * **It is generated, not written.** Every enumerated value, every limit and every source rule below
 * comes from the same constants OfferPayloadParser validates against, and the table of sites is
 * read from the table the ingestion itself resolves against. A sheet typed by hand would be wrong
 * the day a source is added, and it would be wrong *silently* - on the agent's side, where nobody
 * re-reads it.
 *
 * Since the list of sites was opened, that table is a **reminder rather than a menu**: a site
 * missing from it is not refused, it is created from the URL's domain. What the sheet insists on
 * instead is the thing no platform can repair afterwards - a `source_ref` built the same way from
 * one pass to the next.
 *
 * The text is French because it is read from a French screen by an agent that works in French. It
 * never contains a key: the secret is shown once, at creation, and pasting it in here would make it
 * readable from this screen for ever.
 */
final readonly class IngestInstructions
{
    public const int MAX_OFFERS_PER_REQUEST = 500;

    public function __construct(private JobboardSourceRepository $sources)
    {
    }

    public function markdown(string $baseUrl): string
    {
        $sources = implode("\n", array_map(
            static fn (JobboardSource $source): string => \sprintf(
                '| `%s` | %s | %s | %s |',
                $source->getSlug(),
                $source->getLabel(),
                implode(', ', $source->getDomains()),
                // A site discovered on the fly has no rule anybody wrote down. Saying so is the
                // honest answer; inventing one would make the agent change what it already sends.
                $source->getRefRule() ?? 'règle non fixée — garde exactement celle que tu utilises déjà',
            ),
            $this->sources->findAllOrdered(),
        ));

        $contracts = $this->quoted(JobboardContract::values());
        $countries = $this->quoted(JobboardCountry::values());
        $remotes = $this->quoted(JobboardRemote::values());
        $levelSources = $this->quoted(JobboardLevelSource::values());
        $btsAccess = $this->quoted(JobboardBtsAccess::values());
        $maxOffers = self::MAX_OFFERS_PER_REQUEST;
        $maxRaw = OfferPayloadParser::MAX_RAW_BYTES;

        return <<<MD
            # Déposer des offres dans MonCampus

            Tu envoies des offres, la plateforme les range. Elle ne collecte rien et ne lit aucune
            page : ce que tu déposes est ce qu'elle affichera.

            ## Authentification

            Chaque appel porte l'en-tête `Authorization: Bearer <clé>`, la clé étant celle qui t'a
            été remise (elle commence par `mcjb_`). **La filière est portée par la clé** : elle
            n'apparaît jamais dans une URL ni dans un corps de requête, et tu n'as rien à en dire.

            Base : `{$baseUrl}/api/jobboard`

            ## Le déroulé d'un passage

            1. `GET /cursors` — lis où tu t'étais arrêté, **avant** de collecter.
            2. `POST /batches` — ouvre un lot. Réponse : `{"batch_id": 12}`.
            3. `POST /batches/12/offers` — envoie les offres, par paquets de {$maxOffers} au plus.
            4. `POST /batches/12/close` — clôture, et lis le bilan.
            5. `PUT /cursors` — réécris les curseurs, **après** la clôture et jamais pendant.
            6. `POST /offers/close` — signale les offres qui ont disparu des sites.

            ## Envoyer des offres

            ```
            POST {$baseUrl}/api/jobboard/batches/12/offers
            Authorization: Bearer <clé>
            Idempotency-Key: <une chaîne unique par requête>
            Content-Type: application/json

            {"offers": [ { … }, { … } ]}
            ```

            L'en-tête `Idempotency-Key` n'est pas facultatif : si la connexion tombe, renvoie la
            **même** requête avec la **même** clé et la réponse enregistrée te sera rejouée à
            l'identique, sans rien recompter. Une clé déjà vue avec un corps différent est refusée
            (409).

            La réponse dit, offre par offre, ce qui a été `created`, `reviewed` ou `rejected`, avec
            le motif du refus. **Une offre malformée ne fait jamais échouer les autres.**

            Chaque ligne acceptée renvoie aussi le `source` **retenu**, qui n'est pas forcément
            celui que tu as déclaré : c'est l'URL qui décide. Et la réponse porte une clé `sources`
            listant ce que ce dépôt a appris — `source_created` (un site créé depuis le domaine de
            l'`url`) et `domain_attached` (un domaine rattaché à un site que tu as nommé). C'est
            informatif : rien n'a été refusé. Mais si tu y vois un raccourcisseur de liens ou un
            agrégateur, c'est que tes URL ne pointent pas là où tu crois.

            Un détail à ne pas lire comme une anomalie : `created + reviewed + rejected` peut être
            **inférieur** au nombre d'offres envoyées, et la liste `offers` plus courte d'autant.
            L'établissement peut écarter un site ; ses offres sont acceptées puis abandonnées, sans
            motif et sans ligne. Il n'y a rien à corriger et rien à réessayer — continue de
            collecter, la décision se prend et se défait côté plateforme.

            ## Les champs d'une offre

            | champ | obligatoire | remarque |
            |---|---|---|
            | `source` | oui | le nom du site. Un site absent du tableau ci-dessous n'est **pas** refusé : la plateforme le crée à partir du domaine de l'`url` |
            | `source_ref` | oui | l'identifiant de l'offre **chez la source**, stable dans le temps |
            | `url` | oui | le lien de l'annonce, en `http`/`https`. C'est lui qui décide de la source : une URL d'un site connu range l'offre chez lui, quel que soit le nom déclaré |
            | `poste` | oui | l'intitulé tel qu'affiché |
            | `entreprise` | oui | `"Non précisée"` est une valeur acceptée |
            | `categorie` | non | texte libre, ton classement (`sisr`, `slam`, `mixte`, …) |
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
            | `date_publication_approx` | oui | `true` dès que la date est reconstituée d'une ancienneté relative, ou absente |
            | `note` | non | une précision courte tirée de l'annonce |
            | `brut` | non | ta charge utile d'origine, {$maxRaw} octets au plus |

            **`non_precise` n'est pas `aucun`.** La plupart des annonces n'abordent pas le sujet ;
            les confondre fausserait toute la lecture de la colonne. Même chose pour
            `niveau_source` : dis `estime` quand tu as déduit, l'incertitude doit rester visible.

            ## Les sites déjà connus, et comment construire `source_ref`

            `source_ref` est le champ qui décide si une offre revue est reconnue ou refilée en
            double. Garde exactement ces règles — ce sont celles qui ont servi jusqu'ici.

            | source | nom | domaines | `source_ref` |
            |---|---|---|---|
            {$sources}

            **Ce tableau n'est pas une liste fermée.** Un site qui n'y figure pas s'envoie
            normalement : donne-lui un nom, la plateforme le crée à partir du domaine de l'`url` et
            il apparaîtra ici au passage suivant. Deux exigences, en revanche, et elles ne se
            rattrapent pas après coup :

            - **garde le même nom d'un passage à l'autre** — c'est ainsi que le site est reconnu
              tant qu'il n'a pas encore de domaine enregistré ;
            - **garde la même règle de `source_ref`** — un identifiant construit autrement crée un
              doublon, et la date de première vue de l'offre d'origine est perdue pour toujours.

            ## Ce qui est refusé

            - une `date_publication` dans le futur ;
            - un `contrat`, `pays`, `teletravail`, `niveau_source` ou `acces_bts` hors de sa liste ;
            - un `niveau_source` absent ;
            - un `departement` renseigné alors que le pays n'est pas la France, ou hors format ;
            - une `url` qui n'est pas un lien (`http`/`https` et un domaine lisible) ;
            - un champ obligatoire vide.

            **La `source` n'est plus un motif de refus**, et c'est volontaire : les sites de veille
            changent plus vite qu'une mise en production, et une offre refusée est une offre perdue.

            Ne sont **pas** refusés, ce sont des cas normaux : une date de publication absente, une
            ville vide, une entreprise à « Non précisée », une catégorie inconnue.

            ## Les curseurs

            ```
            GET {$baseUrl}/api/jobboard/cursors
            PUT {$baseUrl}/api/jobboard/cursors
            {"source": "hellowork", "search": "technicien-33", "last_ref": "83313525", "last_published_at": "2026-09-12"}
            ```

            `search` est ton propre identifiant de recherche : la plateforme ne l'interprète pas,
            elle demande seulement qu'il soit stable d'un passage à l'autre.

            Lis les curseurs **avant** de collecter et réécris-les **après** la clôture du lot.
            Jamais pendant : une interruption en plein passage laisserait un curseur avancé sur des
            offres jamais enregistrées, et elles seraient perdues pour toujours.

            ## Les offres qui disparaissent

            ```
            POST {$baseUrl}/api/jobboard/offers/close
            {"offers": [{"source": "hellowork", "source_ref": "83313525"}]}
            ```

            Rien n'est jamais supprimé : une offre retirée reçoit une date de fermeture et cesse
            d'être affichée.

            Ces retraits sont comptés sur le passage du jour, avec les offres ajoutées et les offres
            revues : appelle-les après la clôture du lot, le même jour.

            ## Ce que tu n'envoies jamais

            Le salaire, la description complète de l'annonce, des coordonnées de contact, un nom de
            personne. Et aucun identifiant inventé : l'identité d'une offre est le couple
            (`source`, `source_ref`), la plateforme s'occupe du reste.
            MD;
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(' · ', array_map(static fn (string $value): string => '`'.$value.'`', $values));
    }
}
