<?php

namespace Tests\Unit;

use App\Domain\Products\TyreDescriptionParser;
use App\Domain\SupplierPrices\SupplierPriceImporter;
use PHPUnit\Framework\TestCase;

class TyreDescriptionParserTest extends TestCase
{
    private array $brands = [
        ['id' => 1, 'name' => 'GOODRICH'],
        ['id' => 2, 'name' => 'BF GOODRICH'],
        ['id' => 3, 'name' => 'CONTINENTAL'],
        ['id' => 4, 'name' => 'GITI'],
    ];

    public function test_reads_dimensions_indexes_brand_and_profile(): void
    {
        $parsed = (new TyreDescriptionParser)->parse('CONTINENTAL 225/45 ZR18 95Y XL SportContact 7 MO', $this->brands);

        $this->assertSame(225.0, $parsed['width']);
        $this->assertSame(45.0, $parsed['height']);
        $this->assertSame(18.0, $parsed['diameter']);
        $this->assertSame('95', $parsed['load_index']);
        $this->assertSame('Y', $parsed['speed_index']);
        $this->assertTrue($parsed['reinforced']);
        $this->assertSame(3, $parsed['brand_id']);
        $this->assertSame('MO', $parsed['marking']);
        $this->assertSame('SportContact 7', $parsed['profile']);
    }

    public function test_longest_brand_and_known_profile_win(): void
    {
        $parsed = (new TyreDescriptionParser)->parse('BF GOODRICH 245/70R16 113S ALL-TERRAIN T/A KO2 RFT', $this->brands, [2 => ['ALL-TERRAIN T/A', 'ALL-TERRAIN T/A KO2']]);

        $this->assertSame(2, $parsed['brand_id']);
        $this->assertSame('ALL-TERRAIN T/A KO2', $parsed['profile']);
        $this->assertTrue($parsed['runflat']);
    }

    public function test_dimension_column_accepts_the_shop_shorthand(): void
    {
        $parser = new TyreDescriptionParser;

        $this->assertSame(['width' => 205.0, 'height' => 55.0, 'diameter' => 16.0, 'load_index' => '91', 'speed_index' => 'V'], $parser->parseDimension('2055516 91V'));
        $this->assertSame(['width' => 195.0, 'height' => 65.0, 'diameter' => 15.0], $parser->parseDimension('195/65R15'));
        $this->assertSame([], $parser->parseDimension('pneu'));
    }

    public function test_a_brand_ending_in_p_keeps_its_last_letter(): void
    {
        $parser = new TyreDescriptionParser;
        $parsed = $parser->parse('TESTUP 195/65R15 91H ECO', [['id' => 9, 'name' => 'TESTUP']]);

        $this->assertSame(9, $parsed['brand_id']);
        $this->assertSame(195.0, $parsed['width']);
        // The P-metric prefix itself is still read.
        $this->assertSame(195.0, $parser->parse('P195/65R15 89H')['width']);
        $this->assertSame(265.0, $parser->parse('LT265/70R17 121S')['width']);
    }

    public function test_indexes_column(): void
    {
        $parser = new TyreDescriptionParser;

        $this->assertSame(['load_index' => '91', 'speed_index' => 'V'], $parser->parseIndexes('91V'));
        $this->assertSame(['load_index' => '91', 'speed_index' => 'V'], $parser->parseIndexes('91 / v'));
        $this->assertSame(['load_index' => '112', 'speed_index' => 'R'], $parser->parseIndexes('112/110R'));
        $this->assertSame(['load_index' => '94', 'speed_index' => 'V', 'reinforced' => true], $parser->parseIndexes('94V XL'));
        $this->assertSame([], $parser->parseIndexes(''));
    }

    public function test_prices_in_french_and_english_formats(): void
    {
        $importer = (new \ReflectionClass(SupplierPriceImporter::class))->newInstanceWithoutConstructor();

        $this->assertSame(1234.5, $importer->parsePrice('1 234,50 DH'));
        $this->assertSame(1234.5, $importer->parsePrice('1.234,50'));
        $this->assertSame(1234.5, $importer->parsePrice('1,234.50'));
        $this->assertSame(850.0, $importer->parsePrice(850));
        $this->assertSame(99.9, $importer->parsePrice('99,9'));
        $this->assertNull($importer->parsePrice('sur demande'));
    }
}
