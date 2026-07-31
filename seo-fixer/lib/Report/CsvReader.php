<?php
namespace Relod\SeoFixer\Report;

/**
 * Чтение CSV-выгрузок Netpeak.
 * Сам определяет разделитель и кодировку, поддерживает строки-разделители
 * групп так же, как XlsxReader.
 */
class CsvReader
{
    /**
     * @return array{headers:string[],rows:array<int,array<string,string>>,groups:array<int,string>}
     */
    public function read(string $filePath): array
    {
        $delimiter = $this->detectDelimiter($filePath);
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Не удалось открыть CSV-файл.');
        }

        $headers = [];
        $rows = [];
        $groups = [];
        $currentGroup = '';
        $first = true;

        try {
            while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($data === [null] || $data === false) {
                    continue;
                }
                $data = array_map([$this, 'normalizeEncoding'], array_map('strval', $data));

                if ($first) {
                    $first = false;
                    $data[0] = $this->stripBom($data[0]);
                    $headers = $this->normalizeHeaders(array_map('trim', $data));
                    continue;
                }

                $filled = array_values(array_filter(array_map('trim', $data), static function ($v) {
                    return $v !== '';
                }));
                if (!$filled) {
                    continue;
                }
                // Строка-разделитель группы дубликатов.
                if (count($filled) === 1 && trim((string)($data[0] ?? '')) === $filled[0] && !preg_match('/^\d+$/', $filled[0])) {
                    $currentGroup = $filled[0];
                    continue;
                }

                $row = [];
                foreach ($headers as $i => $header) {
                    $row[$header] = isset($data[$i]) ? trim($data[$i]) : '';
                }
                $rows[] = $row;
                $groups[count($rows) - 1] = $currentGroup;
            }
        } finally {
            fclose($handle);
        }

        return ['headers' => array_values($headers), 'rows' => $rows, 'groups' => $groups];
    }

    private function detectDelimiter(string $filePath): string
    {
        $sample = (string)file_get_contents($filePath, false, null, 0, 8192);
        $counts = [
            ';' => substr_count($sample, ';'),
            "\t" => substr_count($sample, "\t"),
            ',' => substr_count($sample, ','),
        ];
        arsort($counts);
        $best = array_key_first($counts);
        return $counts[$best] > 0 ? (string)$best : ',';
    }

    private function stripBom(string $value): string
    {
        return strncmp($value, "\xEF\xBB\xBF", 3) === 0 ? substr($value, 3) : $value;
    }

    private function normalizeEncoding(string $value): string
    {
        if (!function_exists('mb_check_encoding') || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }
        if (!function_exists('mb_convert_encoding')) {
            return $value;
        }
        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1251,CP1251,ISO-8859-5');
        return $converted !== false && $converted !== '' ? $converted : $value;
    }

    /**
     * @param string[] $headers
     * @return array<int,string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        $seen = [];
        foreach ($headers as $idx => $header) {
            $name = trim($header);
            if ($name === '') {
                $name = 'column_' . ($idx + 1);
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) {
                $seen[$key]++;
                $name .= ' (' . $seen[$key] . ')';
            } else {
                $seen[$key] = 1;
            }
            $out[$idx] = $name;
        }
        return $out;
    }
}
