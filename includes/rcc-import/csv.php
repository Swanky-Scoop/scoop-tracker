<?php
if (!defined('ABSPATH')) exit;

/**
 * Parse an RCC CSV export.
 *
 * Returns `['type' => 'recipe'|'ingredient', 'rows' => [...]]` on success
 * or `['error' => '...']` on failure. Each row is an assoc array keyed by
 * CSV header plus an annotated `_placeholder_suspected` boolean.
 */
function scoop_rcc_parse_csv(string $filepath): array {

  if (!file_exists($filepath)) {
    return ['error' => "File not found: $filepath"];
  }

  $fh = @fopen($filepath, 'r');
  if (!$fh) {
    return ['error' => 'Unable to open file.'];
  }

  // Skip UTF-8 BOM if present.
  $bom = fread($fh, 3);
  if ($bom !== "\xef\xbb\xbf") {
    rewind($fh);
  }

  $headers = fgetcsv($fh);
  if (!$headers) {
    fclose($fh);
    return ['error' => 'Unable to read CSV headers.'];
  }
  $headers = array_map('trim', $headers);

  $type = scoop_rcc_detect_type($headers);
  if ($type === null) {
    fclose($fh);
    return ['error' => 'Could not determine CSV type. Expected a recipes or ingredients export from RCC (header must include either "Ingredient List" or "Price/unit").'];
  }

  $rows = [];
  $hcount = count($headers);

  while (($cols = fgetcsv($fh)) !== false) {
    if ($cols === [null] || (count($cols) === 1 && trim((string) $cols[0]) === '')) continue;
    if (empty($cols[0])) continue;

    if (count($cols) < $hcount) {
      $cols = array_pad($cols, $hcount, '');
    } elseif (count($cols) > $hcount) {
      $cols = array_slice($cols, 0, $hcount);
    }

    $row = array_combine($headers, $cols);
    $row = array_map(function ($v) { return is_string($v) ? trim($v) : $v; }, $row);

    $row['_placeholder_suspected'] = scoop_rcc_is_placeholder($row, $type);

    $rows[] = $row;
  }
  fclose($fh);

  return ['type' => $type, 'rows' => $rows];
}

/**
 * Detect CSV type from header set. Returns 'recipe', 'ingredient', or null.
 */
function scoop_rcc_detect_type(array $headers): ?string {
  if (in_array('Ingredient List', $headers, true)) return 'recipe';
  if (in_array('Price/unit',      $headers, true)) return 'ingredient';
  return null;
}

/**
 * Heuristic for the "1 × <unit> @ $1.00" data-entry stub described in
 * RCC_IMPORT_README.md §10.
 *
 * - Ingredients: Price == 1.00 AND Pack == 1.00.
 * - Recipes:    Cost == 1.00 (no Purchase Amount parallel).
 */
function scoop_rcc_is_placeholder(array $row, string $type): bool {

  if ($type === 'ingredient') {
    $price = isset($row['Price']) ? (float) $row['Price'] : 0.0;
    $pack  = isset($row['Pack'])  ? (float) $row['Pack']  : 0.0;
    return ($price === 1.0 && $pack === 1.0);
  }

  if ($type === 'recipe') {
    $cost = isset($row['Cost']) ? (float) $row['Cost'] : 0.0;
    return ($cost === 1.0);
  }

  return false;
}

/**
 * Strip currency / unit decoration from a value so it can land in a decimal
 * column. RCC's "Price/unit" column ships values like "$1.28/oz", "$52.06/Kg",
 * "$0.00/Kg" — we keep just the leading number.
 *
 * Examples:
 *   "$1.28/oz"     → "1.28"
 *   "$52.06/Kg"    → "52.06"
 *   "$1,234.56/lb" → "1234.56"
 *   "0"            → "0"
 *   "Free"         → ""   (no numeric prefix, drops)
 *   ""             → ""
 */
function scoop_rcc_extract_currency_value(string $s): string {
  if ($s === '') return '';
  if (preg_match('/^\s*\$?\s*([\d,]+(?:\.\d+)?)/', $s, $m)) {
    return str_replace(',', '', $m[1]);
  }
  return '';
}

/**
 * Move a $_FILES upload into wp-content/uploads/RCC/.
 * Returns the absolute destination path on success or ['error' => ...].
 */
function scoop_rcc_stash_upload(array $file): array {

  if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    return ['error' => 'No uploaded file received.'];
  }
  $info = wp_check_filetype($file['name']);
  if (($info['ext'] ?? '') !== 'csv') {
    return ['error' => 'Invalid file type. Please upload a CSV file.'];
  }

  $upload = wp_upload_dir();
  $dir    = trailingslashit($upload['basedir']) . 'RCC';
  if (!is_dir($dir)) wp_mkdir_p($dir);

  $name = 'rcc-' . time() . '-' . sanitize_file_name(basename($file['name']));
  $dest = trailingslashit($dir) . $name;

  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    return ['error' => 'Failed to move uploaded file.'];
  }
  return ['path' => $dest];
}
