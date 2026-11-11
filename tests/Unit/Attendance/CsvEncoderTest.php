<?php

declare(strict_types=1);

namespace App\Tests\Unit\Attendance;

use App\Attendance\Service\CsvEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvEncoderTest extends TestCase
{
    public function test_the_bom_comes_first_and_only_once(): void
    {
        $csv = (new CsvEncoder())->encode([['a'], ['b']]);

        self::assertSame("\xEF\xBB\xBF", substr($csv, 0, 3));
        self::assertSame(1, substr_count($csv, "\xEF\xBB\xBF"));
    }

    public function test_rows_end_with_crlf_including_the_last(): void
    {
        $csv = (new CsvEncoder())->encode([['a', 'b'], ['c', 'd']]);

        self::assertSame("\xEF\xBB\xBFa,b\r\nc,d\r\n", $csv);
    }

    public function test_an_empty_row_is_an_empty_line(): void
    {
        self::assertSame("\xEF\xBB\xBFa\r\n\r\nb\r\n", (new CsvEncoder())->encode([['a'], [], ['b']]));
    }

    public function test_commas_quotes_and_newlines_are_quoted(): void
    {
        $csv = (new CsvEncoder())->encode([['a,b', 'say "hi"', "two\nlines"]]);

        self::assertSame("\xEF\xBB\xBF\"a,b\",\"say \"\"hi\"\"\",\"two\nlines\"\r\n", $csv);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formulas(): iterable
    {
        yield 'equals' => ['=1+1', "'=1+1"];
        yield 'hyperlink' => ['=HYPERLINK("http://x","y")', "'=HYPERLINK(\"http://x\",\"y\")"];
        yield 'plus' => ['+1', "'+1"];
        yield 'minus' => ['-2+3', "'-2+3"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'carriage return' => ["\r=1", "'\r=1"];
    }

    #[DataProvider('formulas')]
    public function test_formula_cells_are_neutralised(string $cell, string $expected): void
    {
        self::assertSame([$expected], $this->parse((new CsvEncoder())->encode([[$cell]]))[0]);
    }

    public function test_a_formula_character_inside_a_cell_is_left_alone(): void
    {
        self::assertSame("\xEF\xBB\xBFa=b,x-1\r\n", (new CsvEncoder())->encode([['a=b', 'x-1']]));
    }

    public function test_integers_are_written_as_numbers_without_a_prefix(): void
    {
        self::assertSame("\xEF\xBB\xBF-1,0,10\r\n", (new CsvEncoder())->encode([[-1, 0, 10]]));
    }

    public function test_null_is_an_empty_cell(): void
    {
        self::assertSame("\xEF\xBB\xBFa,,b\r\n", (new CsvEncoder())->encode([['a', null, 'b']]));
    }

    public function test_utf8_is_preserved(): void
    {
        // fputcsv quotes a cell that holds a space; RFC 4180 allows it.
        self::assertSame("\xEF\xBB\xBFZoë,\"Zoë Müller\"\r\n", (new CsvEncoder())->encode([['Zoë', 'Zoë Müller']]));
    }

    public function test_round_trip(): void
    {
        $rows = [['Date', 'Student'], ['2026-11-11', 'Zoë, "Z" Müller'], ['x', '=evil()']];

        self::assertSame(
            [['Date', 'Student'], ['2026-11-11', 'Zoë, "Z" Müller'], ['x', "'=evil()"]],
            $this->parse((new CsvEncoder())->encode($rows)),
        );
    }

    /**
     * @return list<list<string|null>>
     */
    private function parse(string $csv): array
    {
        $body = substr($csv, 3);
        $handle = fopen('php://memory', 'r+');
        self::assertNotFalse($handle);
        fwrite($handle, $body);
        rewind($handle);
        $rows = [];
        while (false !== ($row = fgetcsv($handle, null, ',', '"', ''))) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
