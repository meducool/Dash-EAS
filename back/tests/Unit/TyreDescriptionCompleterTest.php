<?php

namespace Tests\Unit;

use App\Domain\Products\TyreDescriptionCompleter;
use App\Domain\Products\TyreDescriptionParser;
use PHPUnit\Framework\TestCase;

class TyreDescriptionCompleterTest extends TestCase
{
    private TyreDescriptionCompleter $completer;

    private array $tyre = ['width' => 215, 'height' => 45, 'diameter' => 16, 'load_index' => '91', 'speed_index' => 'w', 'brand' => 'Michelin', 'profile' => 'Primacy 3'];

    protected function setUp(): void
    {
        $this->completer = new TyreDescriptionCompleter(new TyreDescriptionParser);
    }

    public function test_indexes_go_right_after_the_dimension(): void
    {
        $this->assertSame(['status' => 'added', 'description' => 'MICHELIN 215/45R16 91W PRIMACY 3'], $this->completer->complete('MICHELIN 215/45R16 PRIMACY 3', $this->tyre));
        $this->assertSame('MATADOR 225/45 R17 91Y FR Hectorra 5', $this->completer->complete('MATADOR 225/45 R17 FR Hectorra 5', ['load_index' => '91', 'speed_index' => 'Y'] + $this->tyre)['description']);
        $this->assertSame('185/60R14 82T RP420 PLATIN', $this->completer->complete('185/60R14 RP420 PLATIN', ['load_index' => '82', 'speed_index' => 'T'] + $this->tyre)['description']);
    }

    public function test_a_complete_description_is_left_alone(): void
    {
        $this->assertSame('unchanged', $this->completer->complete('MICHELIN PILOT SPORT 4 225/45R17 91W', $this->tyre)['status']);
        $this->assertSame('unchanged', $this->completer->complete('215/45 R16 91 W Primacy', $this->tyre)['status']);
        $this->assertSame('unchanged', $this->completer->complete('MICHELIN 215/45R16 PRIMACY 3', ['speed_index' => ''] + $this->tyre)['status']);
    }

    public function test_other_indexes_are_a_conflict_never_changed(): void
    {
        $result = $this->completer->complete('LAUFENN 195/65 R15 91H G FIT EQ+', ['load_index' => '94', 'speed_index' => 'H'] + $this->tyre);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('LAUFENN 195/65 R15 91H G FIT EQ+', $result['description']);
        $this->assertSame('91H', $result['written']);
    }

    public function test_no_dimension_and_empty_descriptions(): void
    {
        // No readable dimension: left alone (references, "18.4-26", "DATE 2021").
        foreach (['PRIMACY 3 MICHELIN', '18.4-26 IND 12PR TI-06', 'M2755020113W-EPCY'] as $text) {
            $this->assertSame(['status' => 'no_dimension', 'description' => $text], $this->completer->complete($text, $this->tyre));
        }
        $this->assertSame(['status' => 'built', 'description' => 'MICHELIN 215/45R16 91W PRIMACY 3'], $this->completer->complete(null, $this->tyre));
    }

    public function test_indexes_written_further_in_the_text_are_recognised(): void
    {
        $twin = ['load_index' => '107/105', 'speed_index' => 'R'] + $this->tyre;
        $this->assertSame('unchanged', $this->completer->complete('PNEU PETLAS 195/75R16C 8PR PT825 PLUS 107/105R', $twin)['status']);
        $this->assertSame('unchanged', $this->completer->complete('PNEU PETLAS 195/75R16C 8PR PT825 PLUS 107/105R', ['load_index' => '107'] + $twin)['status']);
        $this->assertSame('conflict', $this->completer->complete('235/65R16C 8PR 115/113R H188', ['load_index' => '115', 'speed_index' => 'H'] + $this->tyre)['status']);
        // A twin load index on the sheet, a single one in the text: same tyre.
        $this->assertSame('unchanged', $this->completer->complete('235/85R16 114Q CrossContact AT', ['load_index' => '114/111', 'speed_index' => 'Q'] + $this->tyre)['status']);
        // Load numbers already after the dimension, without speed letter: to check.
        $this->assertSame('conflict', $this->completer->complete('245/70R17.5 143/141 HTR2+', ['load_index' => '141/139', 'speed_index' => 'M'] + $this->tyre)['status']);
    }

    public function test_invalid_indexes_on_the_sheet_are_never_written(): void
    {
        foreach ([['107', 'WW'], ['16PR', ''], ['16PR', 'R'], ['91', 'I']] as [$load, $speed]) {
            $result = $this->completer->complete('MICHELIN 215/45R16 PRIMACY 3', ['load_index' => $load, 'speed_index' => $speed] + $this->tyre);
            $this->assertContains($result['status'], ['invalid', 'unchanged'], "$load$speed");
            $this->assertSame('MICHELIN 215/45R16 PRIMACY 3', $result['description']);
        }
        $this->assertSame('invalid', $this->completer->complete('', ['speed_index' => 'WW'] + $this->tyre)['status']);
    }
}
