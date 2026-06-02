<?php
if (!defined('ABSPATH')) exit;

/**
 * Mapper — matches CSV rows to existing pod rows.
 *
 * Loads the entire pod (one query) into an in-memory index keyed by rcc_id,
 * cc_id, and normalized title. Then classifies each CSV row by which of those
 * lookups succeed. See RCC_IMPORT_README.md §3 "Screen 2" for the
 * classification taxonomy.
 */

/**
 * One-shot read of every pod row of the given type. Returns:
 *
 *   [
 *     'rows'      => [ <pod_id> => <pod row as assoc array including post_title> ],
 *     'by_rcc_id' => [ <rcc_id_string> => <pod_id> ],
 *     'by_cc_id'  => [ <cc_id_string>  => <pod_id> ],
 *     'by_title'  => [ <normalized title> => [<pod_id>, ...] ],
 *     'columns'   => [ <column name> => true, ... ]   // what's writable
 *     'has_rcc_id_column' => bool,
 *     'has_cc_id_column'  => bool,
 *   ]
 *
 * Defensive about missing columns: if `rcc_id` doesn't exist on the table yet,
 * the index just won't have a by_rcc_id map. Phase F adds the column on
 * `ingredient` so this gracefully degrades until then.
 */
function scoop_rcc_load_pod_index(string $pod_name): array {

  global $wpdb;
  $table = $wpdb->prefix . 'pods_' . $pod_name;
  $posts = $wpdb->prefix . 'posts';

  $columns = scoop_rcc_table_columns($table);
  $has_rcc = isset($columns['rcc_id']);
  $has_cc  = isset($columns['cc_id']);

  $sql = $wpdb->prepare(
    "SELECT pd.*, p.post_title AS post_title
       FROM `{$table}` pd
       INNER JOIN `{$posts}` p ON p.ID = pd.id
      WHERE p.post_type = %s
        AND p.post_status NOT IN ('trash', 'auto-draft')",
    $pod_name
  );
  $raw = $wpdb->get_results($sql, ARRAY_A);

  $rows = [];
  $by_rcc_id = [];
  $by_cc_id  = [];
  $by_title  = [];

  foreach ($raw as $r) {
    $pid = (int) $r['id'];
    $rows[$pid] = $r;

    if ($has_rcc) {
      $rcc = (string) ($r['rcc_id'] ?? '');
      if ($rcc !== '') $by_rcc_id[$rcc] = $pid;
    }
    if ($has_cc) {
      $cc = $r['cc_id'];
      if ($cc !== null && $cc !== '' && (float) $cc !== 0.0) {
        // cc_id is decimal(12,0); normalize to int string for matching
        $key = (string) (int) $cc;
        if (!isset($by_cc_id[$key])) $by_cc_id[$key] = $pid;
      }
    }

    $tkey = scoop_rcc_normalize_title((string) $r['post_title']);
    if ($tkey !== '') $by_title[$tkey][] = $pid;
  }

  return [
    'rows'              => $rows,
    'by_rcc_id'         => $by_rcc_id,
    'by_cc_id'          => $by_cc_id,
    'by_title'          => $by_title,
    'columns'           => $columns,
    'has_rcc_id_column' => $has_rcc,
    'has_cc_id_column'  => $has_cc,
  ];
}

/**
 * Returns the set of column names that exist on the given pod table,
 * keyed for O(1) `isset()` checks. Cached per-request.
 */
function scoop_rcc_table_columns(string $table): array {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];

  global $wpdb;
  $rows = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A);
  $cols = [];
  foreach ((array) $rows as $r) {
    $cols[$r['Field']] = true;
  }
  return $cache[$table] = $cols;
}

/**
 * Title normalizer for matching. Casefold, strip non-alphanumeric, collapse
 * whitespace. Conservative — preserves character order so similar_text still
 * works against the normalized form.
 */
function scoop_rcc_normalize_title(string $s): string {
  $s = mb_strtolower($s, 'UTF-8');
  $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim((string) $s);
}

/**
 * For each parsed CSV row, decide which pod row it maps to (if any) and
 * label the relationship. Returns:
 *
 *   [
 *     'classified'   => [ csv_index => [ 'csv'=>..., 'class'=>..., 'pod_id'=>?, 'pod_title'=>?, 'similarity'=>? ] ],
 *     'pod_orphans'  => [ <pod row>, ... ],
 *     'counts'       => [ <class> => N, ... ],
 *   ]
 */
function scoop_rcc_classify_rows(array $parsed_rows, array $pod_index): array {

  $matched_pod_ids = [];
  $classified = [];

  $by_rcc = $pod_index['by_rcc_id'];
  $by_cc  = $pod_index['by_cc_id'];
  $by_t   = $pod_index['by_title'];

  // Pre-flatten normalized titles into a flat list for near-title fallback.
  $all_titles = array_keys($by_t);

  foreach ($parsed_rows as $i => $row) {

    $csv_id   = trim((string) ($row['ID']   ?? ''));
    $csv_name = trim((string) ($row['Name'] ?? ''));
    $name_key = scoop_rcc_normalize_title($csv_name);

    $by_id_match = null;
    if ($csv_id !== '') {
      if     (isset($by_rcc[$csv_id])) $by_id_match = $by_rcc[$csv_id];
      elseif (isset($by_cc[$csv_id]))  $by_id_match = $by_cc[$csv_id];
    }

    $by_title_matches = $by_t[$name_key] ?? [];
    $by_title_match   = (count($by_title_matches) === 1) ? $by_title_matches[0] : null;

    $class      = null;
    $pod_id     = null;
    $similarity = null;

    if ($by_id_match !== null && $by_title_match !== null && $by_id_match === $by_title_match) {
      $class = 'exact_match';
      $pod_id = $by_id_match;
    } elseif ($by_id_match !== null) {
      // ID hits, title differs or has duplicates
      $pod_id = $by_id_match;
      $pod_title = (string) ($pod_index['rows'][$pod_id]['post_title'] ?? '');
      $similarity = scoop_rcc_title_similarity($csv_name, $pod_title);
      $class = 'exact_id_near_title';
    } elseif ($by_title_match !== null) {
      // Title hits, no ID match. Either pod row has no IDs at all
      // (back-fillable) or pod row's IDs don't agree with the CSV's.
      $pod_id = $by_title_match;
      $pod_row = $pod_index['rows'][$pod_id];
      $pod_has_id =
        (!empty($pod_row['rcc_id']) && $pod_row['rcc_id'] !== '0')
        || (!empty($pod_row['cc_id']) && (float) $pod_row['cc_id'] !== 0.0);
      $class = $pod_has_id ? 'title_match_id_conflict' : 'exact_title_missing_id';
    } else {
      // Last resort: near-title fallback across all pod titles.
      $near = scoop_rcc_find_near_title($name_key, $all_titles);
      if ($near !== null && count($by_t[$near]) === 1) {
        $pod_id = $by_t[$near][0];
        $similarity = scoop_rcc_title_similarity(
          $csv_name,
          (string) $pod_index['rows'][$pod_id]['post_title']
        );
        $class = 'near_title';
      } else {
        $class = 'csv_orphan';
      }
    }

    $classified[$i] = [
      'csv_index'  => $i,
      'csv'        => $row,
      'class'      => $class,
      'pod_id'     => $pod_id,
      'pod_title'  => $pod_id !== null ? (string) $pod_index['rows'][$pod_id]['post_title'] : null,
      'similarity' => $similarity,
    ];

    if ($pod_id !== null) $matched_pod_ids[$pod_id] = true;
  }

  $pod_orphans = [];
  foreach ($pod_index['rows'] as $pid => $row) {
    if (!isset($matched_pod_ids[$pid])) $pod_orphans[] = $row;
  }

  $counts = [];
  foreach ($classified as $c) {
    $counts[$c['class']] = ($counts[$c['class']] ?? 0) + 1;
  }

  return [
    'classified'  => $classified,
    'pod_orphans' => $pod_orphans,
    'counts'      => $counts,
  ];
}

/**
 * similar_text-based score 0..100. Compares normalized forms so casing
 * and punctuation don't penalize.
 */
function scoop_rcc_title_similarity(string $a, string $b): int {
  $na = scoop_rcc_normalize_title($a);
  $nb = scoop_rcc_normalize_title($b);
  if ($na === '' || $nb === '') return 0;
  similar_text($na, $nb, $pct);
  return (int) round($pct);
}

/**
 * Find the single best near-title match for $needle from a list of normalized
 * titles, with similarity >= 70%. Returns the matched title key, or null if
 * no single best exists (tied or no candidate).
 */
function scoop_rcc_find_near_title(string $needle, array $all_titles): ?string {

  if ($needle === '' || empty($all_titles)) return null;

  $best = null; $best_pct = 0;
  $second_pct = 0;

  foreach ($all_titles as $t) {
    similar_text($needle, $t, $pct);
    if ($pct >= 70 && $pct > $best_pct) {
      $second_pct = $best_pct;
      $best_pct = $pct;
      $best = $t;
    } elseif ($pct > $second_pct) {
      $second_pct = $pct;
    }
  }

  // Require a clear winner (>= 5 point lead) to avoid ambiguous matches.
  if ($best === null) return null;
  if ($best_pct - $second_pct < 5) return null;
  return $best;
}

/**
 * Human label for a classification key. Used in the UI.
 */
function scoop_rcc_class_label(string $class): string {
  $labels = [
    'exact_match'             => 'Exact match',
    'exact_id_near_title'     => 'ID match · title differs',
    'exact_title_missing_id'  => 'Title match · pod has no ID',
    'title_match_id_conflict' => 'Title match · pod has a different ID',
    'near_title'              => 'Near title match',
    'csv_orphan'              => 'No match in pod',
  ];
  return $labels[$class] ?? $class;
}
