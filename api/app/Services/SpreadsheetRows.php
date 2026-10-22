<?php

namespace App\Services;

use ZipArchive;

/** Reads the first sheet of a CSV or XLSX file into rows of cells (strings). No library needed. */
class SpreadsheetRows
{
    /** @return list<list<string>> */
    public static function read(string $path, string $ext): array
    {
        return strtolower($ext) === 'xlsx' ? self::xlsx($path) : self::csv($path);
    }

    private static function csv(string $path): array
    {
        $rows = [];
        $h = fopen($path, 'r');
        if (! $h) {
            return [];
        }
        $first = true;
        while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            if ($first && isset($r[0])) {
                $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $r[0]);      // Excel puts a byte-order mark first
                $first = false;
            }
            $rows[] = array_map(fn ($c) => trim((string) $c), $r);
        }
        fclose($h);

        return $rows;
    }

    private static function colIndex(string $ref): int
    {
        preg_match('/^([A-Z]+)/', $ref, $m);
        $n = 0;
        foreach (str_split($m[1] ?? 'A') as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach ((@simplexml_load_string($xml)->si ?? []) as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string) $si->t;
                } else {
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                }
                $shared[] = $text;
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) {
            return [];
        }
        $rows = [];
        foreach ((@simplexml_load_string($sheet)->sheetData->row ?? []) as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $i = self::colIndex((string) $c['r']);
                $type = (string) $c['t'];
                $v = $type === 'inlineStr' ? (string) ($c->is->t ?? '') : (string) ($c->v ?? '');
                $cells[$i] = $type === 's' ? ($shared[(int) $v] ?? '') : trim($v);
            }
            $max = $cells ? max(array_keys($cells)) : -1;
            $line = [];
            for ($k = 0; $k <= $max; $k++) {
                $line[] = trim((string) ($cells[$k] ?? ''));
            }
            $rows[] = $line;
        }

        return $rows;
    }
}
