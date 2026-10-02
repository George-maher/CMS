<?php

/**
 * Backend i18n key-parity checker (mirrors the frontend check-i18n contract).
 *
 * Usage: php scripts/check-lang-parity.php
 * Exit code 0 on exact parity, 1 otherwise.
 */
$langDir = __DIR__.'/../resources/lang';

$readKeys = static function (string $path): array {
    $raw = (string) file_get_contents($path);

    // A UTF-8 BOM makes json_decode() fail and therefore silently breaks every
    // translated string, so it is reported explicitly rather than ignored.
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        fwrite(STDERR, 'UTF-8 BOM present (breaks translation lookups): '.$path."\n");
        exit(1);
    }

    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        fwrite(STDERR, 'Invalid JSON: '.$path."\n");
        exit(1);
    }

    $keys = array_keys($decoded);
    sort($keys);

    return $keys;
};

$en = $readKeys($langDir.'/en.json');
$ar = $readKeys($langDir.'/ar.json');

$enOnly = array_diff($en, $ar);
$arOnly = array_diff($ar, $en);

printf("EN keys: %d | AR keys: %d\n", count($en), count($ar));

if ($enOnly === [] && $arOnly === []) {
    echo "[PASS] en/ar key parity exact\n";
    exit(0);
}

echo "[FAIL] key parity mismatch\n";
if ($enOnly !== []) {
    echo '  en-only: '.implode(', ', $enOnly)."\n";
}
if ($arOnly !== []) {
    echo '  ar-only: '.implode(', ', $arOnly)."\n";
}
exit(1);
