<?php

declare(strict_types=1);

namespace App\Attendance\Service;

/**
 * RFC 4180 CSV for spreadsheets: UTF-8 with a BOM, comma, double quote, CRLF (#15 D3).
 * String cells that a spreadsheet would read as a formula (first character = + - @ tab or
 * carriage return) get a leading apostrophe (OWASP CSV injection, D5); integer cells are
 * written as numbers and never prefixed, so a balance of -1 stays a number.
 */
final class CsvEncoder
{
    public const BOM = "\xEF\xBB\xBF";
    public const SEPARATOR = ',';
    public const EOL = "\r\n";
    public const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param iterable<list<string|int|null>> $rows null is an empty cell; an empty row is an empty line
     */
    public function encode(iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open a temporary stream for the CSV.');
        }

        try {
            foreach ($rows as $row) {
                if ([] === $row) {
                    fwrite($handle, self::EOL);

                    continue;
                }
                $cells = array_map(fn (string|int|null $cell): string => match (true) {
                    null === $cell => '',
                    \is_int($cell) => (string) $cell,
                    default => $this->neutralise($cell),
                }, $row);
                fputcsv($handle, $cells, self::SEPARATOR, '"', '', self::EOL);
            }
            rewind($handle);
            $csv = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        return self::BOM.(false === $csv ? '' : $csv);
    }

    private function neutralise(string $cell): string
    {
        if ('' !== $cell && \in_array($cell[0], self::FORMULA_PREFIXES, true)) {
            return "'".$cell;
        }

        return $cell;
    }
}
