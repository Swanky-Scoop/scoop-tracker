<?php
if (!defined('ABSPATH')) exit;

/**
 * Parser for a Recipe Cost Calculator recipe export in Markdown.
 *
 * The export interleaves a "Recipe Summary", an "Ingredient Quantities"
 * section, and a "Preparation Method" for each recipe. Two layouts occur in
 * the same file (see RCC_IMPORT_README.md §14):
 *
 *   Table-style (the majority):
 *     #### **Ingredient Quantities**
 *     | Ingredient | Quantity |
 *     | ----- | ----- |
 *     | Chocolate Chips (large) | 270 g |
 *
 *   Run-style (a handful of older recipes):
 *     **Ingredient Quantity** Butter, Room temp 120 g Large Eggs, Whole 1 each
 *
 * Both are reduced to the same list of { name, qty, qty_raw, unit } items.
 * The "Recipe Summary" header (one per recipe) is the spine we split on; the
 * recipe title is the non-empty line immediately preceding it.
 */

/**
 * Parse the whole file. Returns:
 *
 *   ['recipes' => [
 *       [ 'title' => str, 'format' => 'table'|'run'|'none',
 *         'items' => [ ['name'=>, 'qty'=>float, 'qty_raw'=>str, 'unit'=>str], ... ],
 *         'warnings' => [str, ...] ],
 *       ...
 *   ]]
 *
 * or ['error' => '...'] on failure.
 */
function scoop_rcc_parse_recipe_quantities_md(string $filepath): array {

  if (!file_exists($filepath)) {
    return ['error' => "File not found: $filepath"];
  }
  $raw = file_get_contents($filepath);
  if ($raw === false) {
    return ['error' => 'Unable to read file.'];
  }

  // Strip a UTF-8 BOM, normalize newlines.
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  $lines = preg_split("/\r\n|\r|\n/", $raw);

  // Locate every "Recipe Summary" header line.
  $summary_idx = [];
  foreach ($lines as $i => $line) {
    if (scoop_rcc_md_is_header($line, 'Recipe Summary')) $summary_idx[] = $i;
  }
  if (empty($summary_idx)) {
    return ['error' => 'No "Recipe Summary" sections found. This does not look like an RCC recipe export (Markdown).'];
  }

  $recipes  = [];
  $excluded = [];
  $count = count($summary_idx);

  for ($k = 0; $k < $count; $k++) {
    $s = $summary_idx[$k];

    // Title = nearest non-empty line above the summary header.
    $title = '';
    for ($j = $s - 1; $j >= 0; $j--) {
      $t = scoop_rcc_md_strip_heading($lines[$j]);
      if ($t !== '') { $title = $t; break; }
    }

    // RCC auto-generates "(Scaled x N)" variants of recipes — never import those.
    if (scoop_rcc_is_scaled_title($title)) {
      $excluded[] = $title;
      continue;
    }

    // Block body runs to just before the next recipe's title line.
    $block_end = count($lines);
    if ($k + 1 < $count) {
      $next_s = $summary_idx[$k + 1];
      for ($j = $next_s - 1; $j >= 0; $j--) {
        if (scoop_rcc_md_strip_heading($lines[$j]) !== '') { $block_end = $j; break; }
      }
    }

    $block = array_slice($lines, $s, $block_end - $s);
    $recipes[] = scoop_rcc_parse_recipe_block($title, $block);
  }

  return ['recipes' => $recipes, 'excluded' => $excluded];
}

/**
 * True for RCC's auto-scaled recipe variants, whose titles carry a
 * "(Scaled x <number>)" suffix (e.g. "Apple Compote (Scaled x 2450)"). These
 * are never imported. The match is loose on the closing paren since the
 * Markdown sometimes escapes it ("… 2450\)").
 */
function scoop_rcc_is_scaled_title(string $title): bool {
  return (bool) preg_match('/\(\s*scaled\s+x\b/i', $title);
}

/**
 * Parse one recipe block (from its Recipe Summary header to the next title).
 * Returns the quantities parse plus a `prep` string lifted from the
 * "Preparation Method" section (destined for the recipe's `instructions`).
 */
function scoop_rcc_parse_recipe_block(string $title, array $block): array {

  // Locate the two section headers (order is always IQ then PM, but find each
  // independently so a missing IQ doesn't hide the PM).
  $iq_idx = null; $pm_idx = null;
  foreach ($block as $i => $line) {
    if ($iq_idx === null && scoop_rcc_md_is_header($line, 'Ingredient Quantities')) $iq_idx = $i;
    if ($pm_idx === null && scoop_rcc_md_is_header($line, 'Preparation Method'))    $pm_idx = $i;
  }

  // Preparation Method → instructions: everything after its header to block end.
  $prep = '';
  if ($pm_idx !== null) {
    $prep = scoop_rcc_extract_prep(array_slice($block, $pm_idx + 1));
  }

  if ($iq_idx === null) {
    return [
      'title' => $title, 'format' => 'none', 'items' => [],
      'prep' => $prep, 'warnings' => ['No "Ingredient Quantities" section.'],
    ];
  }

  $quant_end = ($pm_idx !== null && $pm_idx > $iq_idx) ? $pm_idx : count($block);
  $section = array_slice($block, $iq_idx + 1, $quant_end - ($iq_idx + 1));

  // Table layout if any line is a markdown table row.
  $has_table = false;
  foreach ($section as $line) {
    if (strpos(trim($line), '|') === 0) { $has_table = true; break; }
  }

  $parsed = $has_table
    ? scoop_rcc_parse_quantity_table($title, $section)
    : scoop_rcc_parse_quantity_run($title, $section);

  $parsed['prep'] = $prep;
  return $parsed;
}

/**
 * Clean the Preparation Method lines into a plain-text instructions string.
 * Unescapes Markdown ("1\." → "1."), drops table/heading noise, collapses
 * runs of blank lines, and treats RCC's "No preparation method defined."
 * placeholder as empty.
 */
function scoop_rcc_extract_prep(array $lines): string {

  $out = [];
  foreach ($lines as $line) {
    $t = trim($line);
    if ($t === '') { $out[] = ''; continue; }
    // Stop if another section header slipped in (defensive — block usually ends first).
    if (scoop_rcc_md_is_header($line, 'Recipe Summary')
        || scoop_rcc_md_is_header($line, 'Ingredient Quantities')) {
      break;
    }
    if (strpos($t, '|') === 0) continue;           // stray table row
    $t = preg_replace('/^#{1,6}\s*/', '', $t);      // heading markers
    $t = scoop_rcc_md_unbold($t);                   // ** and escapes
    $t = preg_replace('/^[*_]+|[*_]+$/', '', $t);   // stray emphasis wrappers
    $out[] = trim($t);
  }

  $text = trim(implode("\n", $out));
  $text = preg_replace("/\n{3,}/", "\n\n", $text);  // collapse blank runs

  // RCC's empty-state placeholder.
  if ($text === '' || preg_match('/^no preparation method defined\.?$/i', trim($text))) {
    return '';
  }
  return $text;
}

/**
 * Parse a markdown-table quantities section into items.
 */
function scoop_rcc_parse_quantity_table(string $title, array $section): array {

  $items = []; $warnings = [];

  foreach ($section as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '|') !== 0) continue;

    // Split the row into cells, dropping the empty edges from leading/trailing |.
    $cells = array_map('trim', explode('|', trim($line, '|')));
    if (count($cells) < 2) continue;

    $name = $cells[0];
    $qty_cell = $cells[1];

    // Skip the header row and the |---|---| separator row.
    if (strcasecmp($name, 'Ingredient') === 0) continue;
    if (preg_match('/^:?-{2,}:?$/', $name)) continue;
    if ($name === '') continue;

    $parsed = scoop_rcc_split_qty_unit($qty_cell);
    if ($parsed === null) {
      $warnings[] = "Could not read quantity \"{$qty_cell}\" for \"{$name}\".";
      continue;
    }
    $items[] = [
      'name'    => scoop_rcc_clean_ingredient_name($name),
      'qty'     => $parsed['qty'],
      'qty_raw' => $parsed['qty_raw'],
      'unit'    => $parsed['unit'],
    ];
  }

  return ['title' => $title, 'format' => 'table', 'items' => $items, 'warnings' => $warnings];
}

/**
 * Parse a run-style quantities section: a free-text stream of
 * "<name> <qty> <unit>" tuples with no delimiters between tuples. We anchor
 * on "<number> <known-unit>" pairs; everything since the previous unit is the
 * next ingredient name.
 */
function scoop_rcc_parse_quantity_run(string $title, array $section): array {

  // Join the section into one string and drop the inline "Ingredient Quantity"
  // column header if present.
  $run = trim(implode(' ', array_map('trim', $section)));
  $run = scoop_rcc_md_unbold($run);
  $run = preg_replace('/^\s*Ingredient\s+Quantity\b/i', '', $run);
  $run = trim($run);

  $items = []; $warnings = [];
  if ($run === '') {
    return ['title' => $title, 'format' => 'run', 'items' => [], 'warnings' => ['Empty quantities section.']];
  }

  $unit_alt = implode('|', array_map('preg_quote', scoop_rcc_unit_vocab()));
  $pattern = '/(?P<name>.+?)\s+(?P<qty>\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:\.\d+)?)\s+(?P<unit>' . $unit_alt . ')(?=\s|$)/iu';

  $matched_to = 0;
  if (preg_match_all($pattern, $run, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
    foreach ($matches as $m) {
      $name = scoop_rcc_clean_ingredient_name($m['name'][0]);
      $qty  = scoop_rcc_parse_qty_number($m['qty'][0]);
      if ($name === '' || $qty === null) continue;
      $items[] = [
        'name'    => $name,
        'qty'     => $qty,
        'qty_raw' => trim($m['qty'][0]),
        'unit'    => trim($m['unit'][0]),
      ];
      $matched_to = $m[0][1] + strlen($m[0][0]);
    }
  }

  $leftover = trim(substr($run, $matched_to));
  if ($leftover !== '') {
    $warnings[] = 'Unparsed trailing text: "' . scoop_rcc_truncate_str($leftover, 80) . '".';
  }
  if (empty($items)) {
    $warnings[] = 'No "<name> <qty> <unit>" tuples recognised in run-style section.';
  }

  return ['title' => $title, 'format' => 'run', 'items' => $items, 'warnings' => $warnings];
}

/**
 * Split a "Quantity" cell like "270 g", "1.5 tbsp", "1/2 tsp", "10 qts" into
 * a numeric quantity plus a unit string. Returns null if no leading number.
 */
function scoop_rcc_split_qty_unit(string $s): ?array {
  $s = trim(scoop_rcc_md_unbold($s));
  if ($s === '') return null;

  if (!preg_match('/^(\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:\.\d+)?)\s*(.*)$/u', $s, $m)) {
    return null;
  }
  $qty = scoop_rcc_parse_qty_number($m[1]);
  if ($qty === null) return null;

  return ['qty' => $qty, 'qty_raw' => trim($m[1]), 'unit' => trim($m[2])];
}

/**
 * Convert a quantity token to a float. Handles integers, decimals, simple
 * fractions ("1/2") and mixed numbers ("1 1/2").
 */
function scoop_rcc_parse_qty_number(string $s): ?float {
  $s = trim($s);
  if ($s === '') return null;

  if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $s, $m)) {
    $den = (float) $m[3];
    if ($den === 0.0) return null;
    return (float) $m[1] + ((float) $m[2] / $den);
  }
  if (preg_match('/^(\d+)\/(\d+)$/', $s, $m)) {
    $den = (float) $m[2];
    if ($den === 0.0) return null;
    return (float) $m[1] / $den;
  }
  if (is_numeric($s)) return (float) $s;

  return null;
}

/**
 * Strip markdown bold (**...**) and stray escape backslashes from a fragment.
 */
function scoop_rcc_md_unbold(string $s): string {
  $s = str_replace('**', '', $s);
  $s = preg_replace('/\\\\([.\-<>()])/', '$1', $s);
  return $s;
}

/**
 * Normalize an ingredient name fragment for storage: unbold, collapse
 * whitespace, trim trailing separators.
 */
function scoop_rcc_clean_ingredient_name(string $s): string {
  $s = scoop_rcc_md_unbold($s);
  $s = preg_replace('/\s+/u', ' ', $s);
  $s = trim((string) $s);
  return rtrim($s, " ,;:-");
}

/**
 * True when a line, ignoring heading markup, is exactly the given section
 * label (e.g. "Recipe Summary"). Matches both `### **Recipe Summary**` and
 * the bare `**Recipe Summary**` layouts.
 */
function scoop_rcc_md_is_header(string $line, string $label): bool {
  return strcasecmp(scoop_rcc_md_strip_heading($line), $label) === 0;
}

/**
 * Strip leading `#` heading markers and surrounding bold/whitespace, returning
 * the inner text. Returns '' for blank/structural lines.
 */
function scoop_rcc_md_strip_heading(string $line): string {
  $s = trim($line);
  if ($s === '') return '';
  $s = preg_replace('/^#{1,6}\s*/', '', $s);
  $s = trim(scoop_rcc_md_unbold($s));
  return trim($s);
}

function scoop_rcc_truncate_str(string $s, int $n): string {
  if (mb_strlen($s) <= $n) return $s;
  return mb_substr($s, 0, $n - 1) . '…';
}
