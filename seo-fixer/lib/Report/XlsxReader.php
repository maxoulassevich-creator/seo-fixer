<?php
namespace Relod\SeoFixer\Report;

/**
 * Чтение XLSX без внешних библиотек.
 *
 * Учитывает особенности выгрузки Netpeak Spider:
 *  - разреженные ячейки (пустые колонки в XML отсутствуют) — читаем по ссылке r="C5";
 *  - строки-разделители групп в отчётах о дубликатах: одна ячейка в колонке A
 *    с повторяющимся значением (title/description/H1), общим для группы ниже;
 *  - shared strings с форматированием (несколько <r><t>);
 *  - лист может называться не sheet1.xml.
 */
class XlsxReader
{
    /**
     * @return array{headers:string[],rows:array<int,array<string,string>>,groups:array<int,string>}
     */
    public function read(string $filePath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('На сервере нет расширения PHP ZipArchive — без него XLSX прочитать нельзя. Сохраните отчёт в CSV или включите расширение.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Не удалось открыть XLSX-файл: возможно, он повреждён или это не Excel-файл.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath = $this->detectFirstSheetPath($zip);
            $sheetXml = $sheetPath !== null ? $zip->getFromName($sheetPath) : false;
            if ($sheetXml === false) {
                $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            }
            if ($sheetXml === false) {
                throw new \RuntimeException('В XLSX-файле не найден лист с данными.');
            }

            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOENT);
            libxml_use_internal_errors($prev);
            if (!$xml || !isset($xml->sheetData)) {
                throw new \RuntimeException('Не удалось разобрать XML первого листа XLSX.');
            }

            $rawRows = [];
            foreach ($xml->sheetData->row as $row) {
                $values = [];
                foreach ($row->c as $cell) {
                    $index = $this->columnIndex((string)$cell['r']);
                    $values[$index] = $this->cellValue($cell, $sharedStrings);
                }
                if ($values) {
                    ksort($values);
                }
                $rawRows[] = $values;
            }
        } finally {
            $zip->close();
        }

        return $this->buildTable($rawRows);
    }

    /**
     * Превращает сырые строки в таблицу с именованными колонками.
     *
     * @param array<int,array<int,string>> $rawRows
     * @return array{headers:string[],rows:array<int,array<string,string>>,groups:array<int,string>}
     */
    private function buildTable(array $rawRows): array
    {
        // Первая непустая строка — заголовки.
        $headerRow = null;
        while ($rawRows) {
            $candidate = array_shift($rawRows);
            if ($this->hasData($candidate)) {
                $headerRow = $candidate;
                break;
            }
        }
        if ($headerRow === null) {
            return ['headers' => [], 'rows' => [], 'groups' => []];
        }

        $headers = $this->normalizeHeaders($headerRow);
        $rows = [];
        $groups = [];
        $currentGroup = '';

        foreach ($rawRows as $raw) {
            if (!$this->hasData($raw)) {
                continue;
            }

            // Строка-разделитель группы дубликатов: единственная заполненная
            // ячейка в первой колонке, при этом не порядковый номер.
            if ($this->isGroupHeader($raw)) {
                $currentGroup = trim((string)reset($raw));
                continue;
            }

            $assoc = [];
            foreach ($headers as $idx => $header) {
                $assoc[$header] = isset($raw[$idx]) ? trim((string)$raw[$idx]) : '';
            }
            if (!$this->hasData($assoc)) {
                continue;
            }
            $rows[] = $assoc;
            $groups[count($rows) - 1] = $currentGroup;
        }

        return ['headers' => array_values($headers), 'rows' => $rows, 'groups' => $groups];
    }

    /**
     * @param array<int,string> $raw
     */
    private function isGroupHeader(array $raw): bool
    {
        $filled = [];
        foreach ($raw as $idx => $value) {
            if (trim((string)$value) !== '') {
                $filled[$idx] = trim((string)$value);
            }
        }
        if (count($filled) !== 1) {
            return false;
        }
        $index = array_key_first($filled);
        if ($index !== 0) {
            return false;
        }
        // Порядковый номер строки — это данные, а не заголовок группы.
        return !preg_match('/^\d+$/', $filled[$index]);
    }

    /**
     * @return string[]
     */
    private function readSharedStrings(\ZipArchive $zip): array
    {
        $strings = [];
        $xmlString = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlString === false) {
            return $strings;
        }
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOENT);
        libxml_use_internal_errors($prev);
        if (!$xml) {
            return $strings;
        }
        foreach ($xml->si as $si) {
            if (isset($si->t) && !isset($si->r)) {
                $strings[] = (string)$si->t;
                continue;
            }
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string)$run->t;
            }
            if ($text === '' && isset($si->t)) {
                $text = (string)$si->t;
            }
            $strings[] = $text;
        }
        return $strings;
    }

    private function detectFirstSheetPath(\ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $rels === false) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $wb = simplexml_load_string($workbook);
        $rs = simplexml_load_string($rels);
        libxml_use_internal_errors($prev);
        if (!$wb || !$rs || !isset($wb->sheets->sheet[0])) {
            return null;
        }
        $attrs = $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string)($attrs['id'] ?? '');
        if ($rid === '') {
            return null;
        }
        foreach ($rs->Relationship as $rel) {
            if ((string)$rel['Id'] !== $rid) {
                continue;
            }
            $target = (string)$rel['Target'];
            if ($target === '') {
                return null;
            }
            $target = ltrim($target, '/');
            return strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
        }
        return null;
    }

    /**
     * @param \SimpleXMLElement $cell
     * @param string[]          $sharedStrings
     */
    private function cellValue($cell, array $sharedStrings): string
    {
        $type = (string)$cell['t'];
        if ($type === 's') {
            $idx = (int)$cell->v;
            return $sharedStrings[$idx] ?? '';
        }
        if ($type === 'inlineStr') {
            if (!isset($cell->is)) {
                return '';
            }
            if (isset($cell->is->t)) {
                return (string)$cell->is->t;
            }
            $text = '';
            foreach ($cell->is->r as $run) {
                $text .= (string)$run->t;
            }
            return $text;
        }
        if ($type === 'b') {
            return ((string)$cell->v) === '1' ? '1' : '0';
        }
        return isset($cell->v) ? (string)$cell->v : '';
    }

    private function columnIndex(string $cellRef): int
    {
        if (!preg_match('/^([A-Z]+)/i', $cellRef, $m)) {
            return 0;
        }
        $letters = strtoupper($m[1]);
        $num = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return $num - 1;
    }

    /**
     * @param array<int,string> $headers
     * @return array<int,string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        $seen = [];
        foreach ($headers as $idx => $header) {
            $name = trim((string)$header);
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

    private function hasData(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }
}
