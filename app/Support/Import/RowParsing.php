<?php
namespace App\Support\Import;

use Illuminate\Support\Str;

class RowParsing
{
    public static function looksLikeHeader(array $row): bool
    {
        $s = strtolower(implode('|', array_map(fn($v) => (string) $v, $row)));
        return str_contains($s, 'sku') || str_contains($s, 'article')
        || str_contains($s, 'катег') || str_contains($s, 'category')
        || str_contains($s, 'найменув') || str_contains($s, 'товар')
        || str_contains($s, 'price') || str_contains($s, 'цена') || str_contains($s, 'варт');
    }

    public static function couldBeHeader(array $row): bool
    {
        $texty = 0;
        $len   = 0;
        foreach ($row as $v) {
            $sv = (string) $v;
            $len += strlen($sv);
            if (! is_numeric($sv)) {
                $texty++;
            }
        }
        return $texty >= 2 && $len <= 120;
    }

    public static function buildMap(array $headers): array
    {
        $canon = [
            'sku'      => ['sku', 'article', 'арт', 'код', 'код товара', 'артикул'],
            'name'     => ['name', 'title', 'product', 'товар', 'найменування', 'найменув', 'опис'],
            'category' => ['category', 'категор', 'розділ', 'группа', 'section'],
            'price'    => ['price', 'цена', 'варт', 'cost', 'amount'],
        ];
        $map = [];
        foreach ($headers as $i => $h) {
            foreach ($canon as $k => $alts) {
                foreach ($alts as $a) {
                    if (str_contains($h, $a)) {
                        $map[$i] = $k;
                        break 2;
                    }
                }
            }
        }
        return $map;
    }

    public static function probeMapFromRow(array $row): ?array
    {
        $fakeHeaders = array_map(fn($v) => strtolower((string) $v), $row);
        $map         = self::buildMap($fakeHeaders);
        return $map ? $map : null;
    }

    public static function alignRow(array $row, array $map): ?array
    {
        $out = [];
        foreach ($map as $i => $k) {
            $out[$k] = $row[$i] ?? null;
        }
        return array_filter($out, fn($v) => null !== $v && '' !== $v) ? $out : null;
    }

    public static function pickSku(array $cells): string
    {
        $best = '';
        foreach ($cells as $c) {
            $s = is_string($c) ? trim($c) : '';
            if ('' === $s) {
                continue;
            }
            $candidate = preg_replace('/\s+/', '', $s);
            if (preg_match('/^[A-Za-z0-9\-\/_\.]{4,40}$/u', $candidate)) {
                $score = strlen($candidate)
                     + (preg_match('/\d/', $candidate) ? 5 : 0)
                     + (str_contains($candidate, '-') ? 1 : 0)
                     + (str_contains($candidate, '/') ? 1 : 0);
                if ($score > strlen($best)) {
                    $best = $candidate;
                }
            }
        }
        return $best;
    }

    public static function pickPrice(array $cells): ?float
    {
        $picked = null;
        for ($i = count($cells) - 1; $i >= 0; $i--) {
            $raw = (string) ($cells[$i] ?? '');
            if ('' === $raw) {
                continue;
            }
            $norm = str_replace(["\xC2\xA0", ' '], '', $raw);
            $norm = str_replace(',', '.', $norm);
            if (preg_match('/^\d+(\.\d+)?$/', $norm)) {
                $picked = (float) $norm;
                break;
            }
        }
        return $picked;
    }

    public static function pickName(array $cells, string $sku, array $last): string
    {
        $skip = array_map(fn($v) => (string) $v, [$last['cat1'] ?? null, $last['cat2'] ?? null, $last['cat3'] ?? null, $last['brand'] ?? null, $sku]);
        $best = '';
        foreach ($cells as $c) {
            $s = trim((string) $c);
            if ('' === $s || in_array($s, $skip, true)) {
                continue;
            }
            if (mb_strlen($s, 'UTF-8') > mb_strlen($best, 'UTF-8')) {
                $best = $s;
            }
        }
        return $best;
    }

    public static function normalizeSlug(string $name): ?string
    {
        $slug = Str::slug($name, '-', 'uk');
        if ('' === $slug) {
            $slug = Str::slug($name);
        }
        if ('' === $slug) {
            $slug = Str::ascii($name);
        }
        $slug = mb_substr($slug, 0, 128);
        return '' !== $slug ? $slug : null;
    }

    public static function clamp(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }
}
