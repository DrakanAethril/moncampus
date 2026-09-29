<?php

declare(strict_types=1);

namespace App\Service\Rncp;

/**
 * Reads one fiche out of France compétences' daily XML export (`export-fiches-rncp-v4-1-*.zip`).
 *
 * The export is 74 Mo zipped and 472 Mo once open, so it is **read as a stream**: XMLReader walks
 * the `FICHE` elements through `zip://` without ever inflating the file to disk, and only the one
 * whose `NUMERO_FICHE` matches is expanded. Memory stays at the size of one fiche.
 *
 * The answer is a plain array - what App\Entity\RncpImport stores in `payload` and what the preview
 * screen reads - with only the fields this application uses:
 *
 *     numero, intitule, abrege, niveau, actif, dateEffet, dateFinEnregistrement, certificateurs,
 *     anciennes, blocs: [{code, libelle, competences: [{label, skills}], texte}]
 *
 * `texte` keeps the block's raw list: when the parser recognises no bullet, the screen shows it for
 * a manual entry instead of guessing.
 *
 * @phpstan-type RncpBlock array{code: string, libelle: string, competences: list<array{label: string, skills: list<string>}>, texte: string}
 * @phpstan-type RncpFiche array{numero: string, intitule: string, abrege: string, niveau: string, actif: bool, dateEffet: ?string, dateFinEnregistrement: ?string, certificateurs: list<string>, anciennes: list<string>, blocs: list<RncpBlock>}
 */
final class RncpFicheReader
{
    public function __construct(
        private readonly RncpCompetencyListParser $parser = new RncpCompetencyListParser(),
    ) {
    }

    /**
     * @return RncpFiche|null null when the export holds no fiche with that number
     *
     * @throws RncpReadException when the file cannot be opened or read
     */
    public function readFromZip(string $zipPath, string $rncpCode): ?array
    {
        return $this->readFromUri($this->xmlEntryOf($zipPath), $rncpCode);
    }

    /**
     * The `zip://` address of the XML inside an export archive.
     *
     * @throws RncpReadException
     */
    private function xmlEntryOf(string $zipPath): string
    {
        $zip = new \ZipArchive();

        if (true !== $zip->open($zipPath)) {
            throw new RncpReadException(\sprintf('L’archive %s ne s’ouvre pas.', basename($zipPath)));
        }

        $entry = null;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (\is_string($name) && str_ends_with(strtolower($name), '.xml')) {
                $entry = $name;
                break;
            }
        }
        $zip->close();

        if (null === $entry) {
            throw new RncpReadException('L’archive ne contient aucun fichier XML.');
        }

        return 'zip://'.$zipPath.'#'.$entry;
    }

    /**
     * @return RncpFiche|null
     *
     * @throws RncpReadException
     */
    public function readFromUri(string $uri, string $rncpCode): ?array
    {
        $reader = new \XMLReader();

        if (!@$reader->open($uri, 'UTF-8', \LIBXML_NONET | \LIBXML_COMPACT)) {
            throw new RncpReadException('Le fichier XML de France compétences ne s’ouvre pas.');
        }

        $rncpCode = strtoupper(trim($rncpCode));

        try {
            // Move to the first FICHE, then hop from sibling to sibling: next() skips a whole
            // subtree without expanding it, which is what keeps the 472 Mo walk cheap.
            while (@$reader->read()) {
                if (\XMLReader::ELEMENT === $reader->nodeType && 'FICHE' === $reader->name) {
                    break;
                }
            }

            while (\XMLReader::ELEMENT === $reader->nodeType && 'FICHE' === $reader->name) {
                $xml = $reader->readOuterXml();

                // A cheap test before any parsing: the number is near the top of each fiche.
                if ('' !== $xml && str_contains($xml, '<NUMERO_FICHE>'.$rncpCode.'</NUMERO_FICHE>')) {
                    $fiche = @simplexml_load_string($xml, \SimpleXMLElement::class, \LIBXML_NONET);

                    if (false === $fiche) {
                        throw new RncpReadException(\sprintf('La fiche %s est illisible.', $rncpCode));
                    }

                    return $this->toArray($fiche);
                }

                if (!@$reader->next('FICHE')) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return null;
    }

    /**
     * The fiches that name `$rncpCode` among the certifications they replace - a renovated diploma.
     *
     * @return list<string> their numbers
     *
     * @throws RncpReadException
     */
    public function replacing(string $uri, string $rncpCode): array
    {
        $reader = new \XMLReader();
        if (!@$reader->open($uri, 'UTF-8', \LIBXML_NONET | \LIBXML_COMPACT)) {
            throw new RncpReadException('Le fichier XML de France compétences ne s’ouvre pas.');
        }

        $needle = '<ID_FICHE_ANCIENNE_CERTIFICATION>'.strtoupper(trim($rncpCode)).'</ID_FICHE_ANCIENNE_CERTIFICATION>';
        $found = [];

        try {
            while (@$reader->read()) {
                if (\XMLReader::ELEMENT === $reader->nodeType && 'FICHE' === $reader->name) {
                    break;
                }
            }

            while (\XMLReader::ELEMENT === $reader->nodeType && 'FICHE' === $reader->name) {
                $xml = $reader->readOuterXml();
                if (str_contains($xml, $needle) && 1 === preg_match('/<NUMERO_FICHE>([^<]+)<\/NUMERO_FICHE>/', $xml, $match)) {
                    $found[] = trim($match[1]);
                }
                if (!@$reader->next('FICHE')) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return $found;
    }

    /**
     * @return list<string>
     *
     * @throws RncpReadException
     */
    public function replacingFromZip(string $zipPath, string $rncpCode): array
    {
        return $this->replacing($this->xmlEntryOf($zipPath), $rncpCode);
    }

    /**
     * @return RncpFiche
     */
    private function toArray(\SimpleXMLElement $fiche): array
    {
        $certifiers = [];
        foreach ($fiche->CERTIFICATEURS->CERTIFICATEUR ?? [] as $certifier) {
            $name = trim((string) $certifier->NOM_CERTIFICATEUR);
            if ('' !== $name) {
                $certifiers[] = $name;
            }
        }

        $previous = [];
        foreach ($fiche->ANCIENNES_CERTIFICATIONS->ANCIENNE_CERTIFICATION ?? [] as $old) {
            $code = trim((string) $old->ID_FICHE_ANCIENNE_CERTIFICATION);
            if ('' !== $code) {
                $previous[] = $code;
            }
        }

        $blocks = [];
        foreach ($fiche->BLOCS_COMPETENCES->BLOC_COMPETENCES ?? [] as $block) {
            $text = (string) $block->LISTE_COMPETENCES;
            $blocks[] = [
                'code' => trim((string) $block->CODE),
                'libelle' => self::oneLine((string) $block->LIBELLE),
                'competences' => $this->parser->parse($text),
                'texte' => trim($text),
            ];
        }

        return [
            'numero' => trim((string) $fiche->NUMERO_FICHE),
            'intitule' => self::oneLine((string) $fiche->INTITULE),
            'abrege' => trim((string) ($fiche->ABREGE->CODE ?? '')),
            'niveau' => trim((string) ($fiche->NOMENCLATURE_EUROPE->LIBELLE ?? '')),
            'actif' => 'Oui' === trim((string) $fiche->ACTIF),
            'dateEffet' => self::isoDate((string) $fiche->DATE_EFFET),
            'dateFinEnregistrement' => self::isoDate((string) $fiche->DATE_FIN_ENREGISTREMENT),
            'certificateurs' => $certifiers,
            'anciennes' => $previous,
            'blocs' => $blocks,
        ];
    }

    private static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** The export writes `31/08/2028`. */
    private static function isoDate(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', trim($value));

        return false === $date ? null : $date->format('Y-m-d');
    }
}
