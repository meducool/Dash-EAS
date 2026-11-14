<?php

namespace App\Domain\SupplierPrices;

use App\Models\Brand;
use App\Models\BrandProfile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The standard price-list template, valid for every brand: one row per
 * tyre, columns the importer recognises without any mapping. Meant to be
 * opened in Google Sheets (File › Import), filled by the supplier, then
 * shared by link and added as a "Lien en ligne" price list.
 *
 * Sheets: "Tarif" (read by the import, Marque column offered as a list),
 * "Marques" (brands and profiles of the catalogue), "Mode d'emploi" (with
 * filled-in examples — the import never reads that sheet).
 */
class SupplierPriceTemplate
{
    /** Column headers of the "Tarif" sheet — every one is recognised by SupplierPriceImporter. */
    public const HEADERS = ['Référence', 'Marque', 'Profil', 'Dimension', 'IC/IV', 'RUNFLAT', 'Prix TTC'];

    public const SHEET = 'Tarif';

    private const ROWS_WITH_LIST = 2000;

    public function build(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle('Modèle tarif fournisseur')->setCreator('POS');

        $brands = Brand::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $profiles = BrandProfile::query()->whereIn('brand_id', $brands->pluck('id'))->orderBy('name')->get(['brand_id', 'name'])->groupBy('brand_id');

        $this->tarifSheet($book->getActiveSheet(), $brands->count());
        $this->brandsSheet($book->createSheet(), $brands, $profiles);
        $this->guideSheet($book->createSheet());

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function tarifSheet(Worksheet $sheet, int $brandCount): void
    {
        $sheet->setTitle(self::SHEET);
        $sheet->fromArray(self::HEADERS, null, 'A1');
        $this->styleHeader($sheet, 'A1:G1');
        $this->columnWidths($sheet);
        $sheet->freezePane('A2');
        $sheet->getStyle('G2:G'.self::ROWS_WITH_LIST)->getNumberFormat()->setFormatCode('#,##0.00');

        if ($brandCount > 0) {
            // Marque: picked from the catalogue's brands (another name is still accepted).
            $this->listValidation($sheet, 'B', "'Marques'!\$A\$2:\$A\$".($brandCount + 1), 'Marque',
                'Marque absente de la liste : elle sera importée telle quelle.', DataValidation::STYLE_INFORMATION);
        }
        $this->listValidation($sheet, 'F', '"Oui,Non"', 'RUNFLAT', 'Oui ou Non (vide = Non).', DataValidation::STYLE_STOP);

        foreach (['G' => 'Prix TTC'] as $column => $title) {
            $price = $sheet->getCell("{$column}2")->getDataValidation();
            $price->setType(DataValidation::TYPE_DECIMAL)
                ->setOperator(DataValidation::OPERATOR_GREATERTHAN)
                ->setFormula1('0')
                ->setAllowBlank(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle($title)
                ->setError('Prix en chiffres, sans « DH » (ex : 850 ou 850,50).');
            $price->setSqref("{$column}2:{$column}".self::ROWS_WITH_LIST);
        }
    }

    private function listValidation(Worksheet $sheet, string $column, string $formula, string $title, string $error, string $style): void
    {
        $validation = $sheet->getCell("{$column}2")->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle($style)
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle($title)
            ->setError($error)
            ->setFormula1($formula);
        $validation->setSqref("{$column}2:{$column}".self::ROWS_WITH_LIST);
    }

    private function columnWidths(Worksheet $sheet): void
    {
        foreach (['A' => 20, 'B' => 18, 'C' => 24, 'D' => 16, 'E' => 10, 'F' => 11, 'G' => 12] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * @param  Collection<int, Brand>  $brands
     * @param  Collection<int, Collection<int, BrandProfile>>  $profiles
     */
    private function brandsSheet(Worksheet $sheet, $brands, $profiles): void
    {
        $sheet->setTitle('Marques');
        $sheet->fromArray(['Marque', 'Profils connus'], null, 'A1');
        $this->styleHeader($sheet, 'A1:B1');
        $row = 2;
        foreach ($brands as $brand) {
            $sheet->setCellValue("A{$row}", $brand->name);
            $sheet->setCellValue("B{$row}", ($profiles[$brand->id] ?? collect())->pluck('name')->implode(', '));
            $row++;
        }
        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(90);
        $sheet->freezePane('A2');
    }

    private function guideSheet(Worksheet $sheet): void
    {
        $sheet->setTitle("Mode d'emploi");
        $lines = [
            ['MODÈLE DE TARIF FOURNISSEUR'],
            [],
            ['1. Remplir l\'onglet « Tarif » : une ligne par pneu, sans ligne vide au-dessus des en-têtes.'],
            ['2. Ne pas renommer l\'onglet « Tarif » ni ses en-têtes (ligne 1).'],
            ['3. Marque : choisir dans la liste (onglet « Marques »). Profil : de préférence un profil connu de la marque.'],
            ['4. Dimension : largeur/hauteur R diamètre, ex : 205/55R16 (la forme courte 2055516 est acceptée).'],
            ['   IC/IV : indice de charge + indice de vitesse, ex : 91V (ou 112/110R). Ajouter XL si renforcé : 94V XL.'],
            ['5. RUNFLAT : Oui ou Non (vide = Non). Prix TTC : prix du fournisseur, en chiffres, sans « DH ». Le prix de vente est calculé par le POS (marge réglée par l\'administrateur).'],
            ['6. Google Sheets : Partager › « Tous les utilisateurs disposant du lien » (lecteur), copier le lien.'],
            ['7. Dans le POS : Tarifs fournisseurs › + Ajouter un tarif › Lien en ligne, coller le lien. Cocher « Relire chaque nuit » pour suivre les mises à jour.'],
            [],
            ['EXEMPLES (cet onglet n\'est jamais importé)'],
            self::HEADERS,
            ['MI-PRI4-2055516', 'MICHELIN', 'PRIMACY 4', '205/55R16', '91V', 'Non', 1150],
            ['', 'CONTINENTAL', 'PREMIUMCONTACT 6', '225/45R17', '94Y XL', 'Non', 1390],
            ['BR-T005-2254518', 'BRIDGESTONE', 'TURANZA T005', '225/45R18', '91W', 'Oui', 1850],
        ];
        $sheet->fromArray($lines, null, 'A1', true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A12')->getFont()->setBold(true);
        $this->styleHeader($sheet, 'A13:G13');
        $this->columnWidths($sheet);
    }

    private function styleHeader(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1F2937');
    }
}
