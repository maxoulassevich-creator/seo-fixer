<?php
namespace Relod\SeoFixer\Service;

class XlsxReader
{
    public function read(string $filePath): array
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('На сервере недоступен ZipArchive. XLSX нельзя прочитать без этого расширения PHP.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Не удалось открыть XLSX-файл.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetPath = $this->detectFirstSheetPath($zip) ?: 'xl/worksheets/sheet1.xml';
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            $zip->close();
            throw new \RuntimeException('Не удалось найти первый лист в XLSX-файле.');
        }

        $xml = simplexml_load_string($sheetXml);
        if (!$xml) {
            $zip->close();
            throw new \RuntimeException('Не удалось прочитать XML первого листа XLSX.');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                $ref = (string)$cell['r'];
                $index = $this->columnIndex($ref);
                $values[$index] = $this->cellValue($cell, $sharedStrings);
            }
            if ($this->hasData($values)) {
                ksort($values);
                $rows[] = $values;
            }
        }
        $zip->close();

        if (!$rows) {
            return ['headers' => [], 'rows' => []];
        }

        $headerRow = array_shift($rows);
        $headers = $this->normalizeHeaders($headerRow);
        $assocRows = [];
        foreach ($rows as $row) {
            $assoc = [];
            foreach ($headers as $idx => $header) {
                $assoc[$header] = isset($row[$idx]) ? trim((string)$row[$idx]) : '';
            }
            if ($this->hasData($assoc)) {
                $assocRows[] = $assoc;
            }
        }

        return ['headers' => array_values($headers), 'rows' => $assocRows];
    }

    private function readSharedStrings(\ZipArchive $zip): array
    {
        $strings = [];
        $xmlString = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlString === false) {
            return $strings;
        }
        $xml = simplexml_load_string($xmlString);
        if (!$xml) {
            return $strings;
        }
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $strings[] = (string)$si->t;
                continue;
            }
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string)$run->t;
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
        $wb = simplexml_load_string($workbook);
        $rs = simplexml_load_string($rels);
        if (!$wb || !$rs || empty($wb->sheets->sheet[0])) {
            return null;
        }
        $attrs = $wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string)$attrs['id'];
        if ($rid === '') {
            return null;
        }
        foreach ($rs->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $target = (string)$rel['Target'];
                if ($target === '') return null;
                return strpos($target, 'xl/') === 0 ? $target : 'xl/' . ltrim($target, '/');
            }
        }
        return null;
    }

    private function cellValue($cell, array $sharedStrings): string
    {
        $type = (string)$cell['t'];
        if ($type === 's') {
            $idx = (int)$cell->v;
            return isset($sharedStrings[$idx]) ? $sharedStrings[$idx] : '';
        }
        if ($type === 'inlineStr') {
            return isset($cell->is->t) ? (string)$cell->is->t : '';
        }
        return isset($cell->v) ? (string)$cell->v : '';
    }

    private function columnIndex(string $cellRef): int
    {
        preg_match('/^[A-Z]+/i', $cellRef, $m);
        $letters = strtoupper($m[0] ?? 'A');
        $num = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $num = $num * 26 + (ord($letters[$i]) - 64);
        }
        return $num - 1;
    }

    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $idx => $header) {
            $name = trim((string)$header);
            if ($name === '') {
                $name = 'column_' . ($idx + 1);
            }
            if (isset($out[$idx])) {
                $name .= '_' . $idx;
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
