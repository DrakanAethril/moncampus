<?php

declare(strict_types=1);

namespace App\Service\LaptopImport;

use App\Service\CsvTable;

/**
 * Turns the inventory spreadsheet a purchase produces into LaptopRow objects: two named columns the
 * file must carry, three it may.
 *
 * Answers only "what does the file say" - nothing is resolved against the inventory and no value is
 * validated here. What it does decide is what cannot be read at all, and those refusals throw
 * rather than being reported per line: a file with no inventory-number column, a file with no
 * serial-number column, an empty file, and a file of more than 500 machines, which is no longer a
 * delivery.
 *
 * The header spellings are matched folded (CsvTable::fold), so « N° inventaire », « N°INVENTAIRE »
 * and « numero_inventaire » are the same column. That is what lets the school's own export -
 * `N° inventaire;N° série;Marque;Modèle;Prix garantie` - be uploaded untouched.
 */
final class LaptopImportCsvReader
{
    // Past this, the file is something else - a full asset export, another site's inventory.
    // Refused whole rather than truncated: an import nobody can see the end of is worse than no
    // import.
    public const int MAX_ROWS = 500;

    /** Folded header spelling => the column it names. First spelling seen wins. */
    private const array COLUMN_ALIASES = [
        'n_inventaire' => 'assetTag',
        'no_inventaire' => 'assetTag',
        'num_inventaire' => 'assetTag',
        'numero_inventaire' => 'assetTag',
        'numero_d_inventaire' => 'assetTag',
        'inventaire' => 'assetTag',
        'n_interne' => 'assetTag',
        'n_interne_pc' => 'assetTag',
        'asset_tag' => 'assetTag',
        'n_serie' => 'serialNumber',
        'no_serie' => 'serialNumber',
        'num_serie' => 'serialNumber',
        'numero_serie' => 'serialNumber',
        'numero_de_serie' => 'serialNumber',
        'serie' => 'serialNumber',
        'serial' => 'serialNumber',
        'serial_number' => 'serialNumber',
        'marque' => 'brand',
        'brand' => 'brand',
        'modele' => 'model',
        'model' => 'model',
        'type' => 'model',
        'prix_garantie' => 'replacementValue',
        'valeur_garantie' => 'replacementValue',
        'valeur_de_remplacement' => 'replacementValue',
        'valeur_de_rachat' => 'replacementValue',
        'valeur' => 'replacementValue',
        'prix' => 'replacementValue',
        'montant' => 'replacementValue',
    ];

    /**
     * Both are required as *columns*, not as values: a blank cell is a finding the analysis reports
     * on its own line, a missing column is a file that was never about machines.
     */
    private const array REQUIRED_COLUMNS = ['assetTag' => 'N° inventaire', 'serialNumber' => 'N° série'];

    private const array OPTIONAL_COLUMNS = ['brand', 'model', 'replacementValue'];

    /**
     * @return list<LaptopRow>
     *
     * @throws LaptopImportFileException
     */
    public function read(string $content): array
    {
        $rows = CsvTable::fromContent($content)->rows();
        $header = array_shift($rows);

        if (null === $header || [] === $rows) {
            throw new LaptopImportFileException('laptopImportFileEmptyMessage');
        }

        $columns = $this->mapColumns($header);
        foreach (self::REQUIRED_COLUMNS as $key => $label) {
            if (!isset($columns[$key])) {
                throw new LaptopImportFileException('laptopImportFileMissingColumnMessage', ['%column%' => $label]);
            }
        }

        $laptops = [];
        foreach ($rows as $index => $row) {
            $cells = [];
            foreach ([...array_keys(self::REQUIRED_COLUMNS), ...self::OPTIONAL_COLUMNS] as $key) {
                $cells[$key] = isset($columns[$key]) ? $this->cell($row, $columns[$key]) : '';
            }

            // A spreadsheet keeps rows that only carry formatting. An entirely empty line is
            // noise, not an error; a line that says anything at all is kept and answered for.
            if ('' === implode('', $cells)) {
                continue;
            }

            $laptops[] = new LaptopRow(
                $index + 2,
                $cells['assetTag'],
                $cells['serialNumber'],
                $cells['brand'],
                $cells['model'],
                $cells['replacementValue'],
            );
        }

        if ([] === $laptops) {
            throw new LaptopImportFileException('laptopImportFileEmptyMessage');
        }

        if (\count($laptops) > self::MAX_ROWS) {
            throw new LaptopImportFileException('laptopImportFileTooManyRowsMessage', ['%max%' => self::MAX_ROWS]);
        }

        return $laptops;
    }

    /**
     * @param list<string> $header
     *
     * @return array<string, int> column key => position in a row
     */
    private function mapColumns(array $header): array
    {
        $columns = [];
        foreach ($header as $position => $label) {
            $key = self::COLUMN_ALIASES[CsvTable::fold($label)] ?? null;
            if (null !== $key && !isset($columns[$key])) {
                $columns[$key] = $position;
            }
        }

        return $columns;
    }

    /** @param list<string> $row */
    private function cell(array $row, int $position): string
    {
        return trim($row[$position] ?? '');
    }
}
