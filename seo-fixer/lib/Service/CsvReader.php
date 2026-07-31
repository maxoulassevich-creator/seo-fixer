<?php
namespace Relod\SeoFixer\Service;

class CsvReader
{
    public function read(string $filePath): array
    {
        $delimiter = $this->detectDelimiter($filePath);
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Не удалось открыть CSV-файл.');
        }
        $headers = [];
        $rows = [];
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $data = array_map([$this, 'normalizeEncoding'], $data);
            if (!$headers) {
                $headers = array_map('trim', $data);
                continue;
            }
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header ?: ('column_' . ($i + 1))] = isset($data[$i]) ? trim($data[$i]) : '';
            }
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }
        fclose($handle);
        return ['headers' => $headers, 'rows' => $rows];
    }

    private function detectDelimiter(string $filePath): string
    {
        $sample = file_get_contents($filePath, false, null, 0, 4096) ?: '';
        return substr_count($sample, ';') > substr_count($sample, ',') ? ';' : ',';
    }

    private function normalizeEncoding(string $value): string
    {
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            $converted = function_exists('mb_convert_encoding') ? @mb_convert_encoding($value, 'UTF-8', 'Windows-1251,CP1251,UTF-8') : false;
            return $converted ?: $value;
        }
        return $value;
    }
}
