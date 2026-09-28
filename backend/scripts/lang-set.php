<?php

/**
 * Adds translation keys to the backend JSON language files WITHOUT re-encoding
 * the whole document.
 *
 * Why not a json_decode/json_encode round trip:
 *   that would normalise the file's formatting (the repository keeps a blank
 *   line between entries), rewrite Unicode escaping, and silently collapse
 *   duplicate keys. Editing the text keeps the diff to exactly the added lines.
 *
 * A UTF-8 BOM is stripped if present, because a BOM makes json_decode() fail
 * and therefore breaks every translated string at runtime.
 *
 * Usage:
 *   php scripts/lang-set.php <locale> <additions.json>
 *
 * `additions.json` is a flat JSON object of key => value. It is read from a file
 * so shells that strip quoting cannot corrupt it.
 *
 * Exit codes: 0 ok, 1 usage/IO error, 2 invalid JSON in either file.
 */

$root = dirname(__DIR__).'/resources/lang';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/lang-set.php <locale> <additions.json>\n");
    exit(1);
}

$locale = $argv[1];
$additionsPath = $argv[2];

if (! preg_match('/^[a-z]{2}(_[a-zA-Z]{2})?$/', $locale)) {
    fwrite(STDERR, "Invalid locale: {$locale}\n");
    exit(1);
}

if (! is_file($additionsPath)) {
    fwrite(STDERR, "Additions file not found: {$additionsPath}\n");
    exit(1);
}

$additionsRaw = (string) file_get_contents($additionsPath);
$additions = json_decode($additionsRaw, true, 8, JSON_THROW_ON_ERROR);

if (! is_array($additions) || $additions === []) {
    fwrite(STDERR, "Additions must be a non-empty JSON object.\n");
    exit(1);
}

$path = $root.'/'.$locale.'.json';

if (! is_file($path)) {
    fwrite(STDERR, "Language file not found: {$path}\n");
    exit(1);
}

$raw = (string) file_get_contents($path);
$hadBom = str_starts_with($raw, "\xEF\xBB\xBF");

if ($hadBom) {
    $raw = substr($raw, 3);
}

// Fail fast if the existing file is not valid JSON: never append onto a
// document we could not verify.
$existing = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
unset($existing);

$lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];

/** Encode a value exactly the way the repository does: raw UTF-8, escaped slashes. */
$encode = static function (mixed $value): string {
    return json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
};

// Find the final closing brace, ignoring whitespace.
$closingIndex = null;
for ($i = count($lines) - 1; $i >= 0; $i--) {
    if (trim($lines[$i]) === '}') {
        $closingIndex = $i;
        break;
    }
}

if ($closingIndex === null) {
    fwrite(STDERR, "Could not locate the closing brace in {$path}\n");
    exit(2);
}

$added = [];
$updated = [];
$pending = [];

foreach ($additions as $key => $value) {
    if (! is_string($key) || $key === '') {
        fwrite(STDERR, "Invalid key in additions file.\n");
        exit(1);
    }

    $encodedKey = $encode($key);
    $encodedValue = $encode($value);

    // Replace an existing entry in place, preserving its position.
    $replaced = false;
    foreach ($lines as $i => $line) {
        if (str_contains($line, $encodedKey)) {
            $lines[$i] = '    '.$encodedKey.': '.$encodedValue.',';
            $updated[] = $key;
            $replaced = true;
            break;
        }
    }

    if ($replaced) {
        continue;
    }

    $added[] = $key;
    $pending[] = '    '.$encodedKey.': '.$encodedValue.',';
}

// The last appended entry must not keep a trailing comma before the closing brace.
if ($pending !== []) {
    $last = count($pending) - 1;
    $pending[$last] = rtrim($pending[$last], ',');
}

if ($added !== []) {
    // The final existing entry may legitimately have no trailing comma (it was
    // the last one). Appending after it would produce invalid JSON, so add the
    // separator first.
    for ($i = $closingIndex - 1; $i >= 0; $i--) {
        $trimmed = rtrim($lines[$i]);
        if ($trimmed === '') {
            continue;
        }
        if (! str_ends_with($trimmed, ',')) {
            $lines[$i] = $trimmed.',';
        }
        break;
    }

    // Match the surrounding style: entries are separated by a blank line.
    array_splice($lines, $closingIndex, 0, array_merge([''], $pending));
}

$out = implode("\n", $lines);

// Normalise to LF and guarantee exactly one trailing newline.
$out = rtrim($out, "\n")."\n";

// Validate BEFORE writing: this script must never leave a corrupt language file
// behind, because a broken translation file breaks every localized response.
$final = json_decode($out, true, 512, JSON_THROW_ON_ERROR);

if (! is_array($final)) {
    fwrite(STDERR, "Refusing to write: result would not be a JSON object.\n");
    exit(2);
}

foreach (array_keys($additions) as $key) {
    if (! array_key_exists($key, $final)) {
        fwrite(STDERR, "Refusing to write: key {$key} is missing from the result.\n");
        exit(2);
    }
}

file_put_contents($path, $out, LOCK_EX);

printf(
    "%s: %d keys (added %d, updated %d)%s\n",
    $locale,
    count($final),
    count($added),
    count($updated),
    $hadBom ? ' [stripped UTF-8 BOM]' : '',
);
