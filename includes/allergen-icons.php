<?php
if (!defined('ABSPATH')) exit;

/**
 * Allergen Icons — matches files in allergen-icons/*.svg (plugin root) to
 * allergen titles and writes them into each allergen's Pods 'icon' field.
 *
 * Unlike flavor-photos (which requires an exact sanitized-title filename),
 * matching here is fuzzy on separators: '-', '_', and spaces in both the
 * filename and the title are treated as interchangeable, and comparison is
 * case-insensitive. So "Tree nuts" matches both "Tree_nuts.svg" and
 * "tree-nuts.svg". A typo in the filename (e.g. "artifical-dye.svg" for
 * "Artificial dye") will NOT match — that's a real spelling mismatch, not
 * a separator difference, and shows up as an unmatched allergen + an
 * orphan file in the scan so it's easy to spot and fix.
 *
 * Two-step workflow like the reconciler/flavor-photos: scan (dry-run) and
 * apply (commit).
 */

/**
 * Normalize a title or filename stem into a comparison key: lowercase,
 * collapse any run of non-alphanumeric characters (including '-', '_',
 * spaces) into a single underscore, strip leading/trailing underscores.
 */
function scoop_allergen_icon_normalize(string $s): string {
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/', '_', $s);
  return trim($s, '_');
}

function scoop_allergen_icons_dir(): string {
  return SCOOP_REST_DIR . 'allergen-icons/';
}

/**
 * Build a normalized-key => filepath map of every .svg in the icons dir.
 * If two files normalize to the same key, both are recorded and the caller
 * treats that key as ambiguous.
 *
 * @return array{files: array<string,string>, ambiguous: array<string,string[]>}
 */
function scoop_allergen_icons_index(): array {
  $dir = scoop_allergen_icons_dir();
  $files = [];
  $ambiguous = [];

  foreach (glob($dir . '*.svg') ?: [] as $path) {
    $stem = pathinfo($path, PATHINFO_FILENAME);
    $key = scoop_allergen_icon_normalize($stem);
    if ($key === '') continue;

    if (isset($files[$key])) {
      $ambiguous[$key] = $ambiguous[$key] ?? [$files[$key]];
      $ambiguous[$key][] = $path;
    } else {
      $files[$key] = $path;
    }
  }

  // Ambiguous keys can't be safely auto-matched — pull them out of $files.
  foreach ($ambiguous as $key => $paths) {
    unset($files[$key]);
  }

  return ['files' => $files, 'ambiguous' => $ambiguous];
}

/**
 * Read the current attachment ID (if any) out of a loaded allergen pod's
 * 'icon' field. Mirrors the array/numeric shapes Pods file fields return
 * (see scoop_file_url_out() in bundle-fetch.php).
 */
function scoop_allergen_icons_current_attachment_id($pod): int {
  $v = $pod->field('icon');

  if (empty($v)) return 0;

  if (is_array($v)) {
    if (isset($v[0]) && is_array($v[0])) $v = $v[0];
    if (!empty($v['ID'])) return (int) $v['ID'];
    return 0;
  }

  if (is_numeric($v)) return (int) $v;

  return 0;
}

/**
 * Scan all allergens and fuzzy-match against files in allergen-icons/.
 *
 * @return array {
 *   'ok': bool,
 *   'error': string|null,
 *   'rows': array<int, array{
 *     id:int, title:string, matched_filename:string, source_path:string,
 *     current_attachment_id:int, action:string
 *   }>,
 *   'counts': array<string,int>,
 *   'ambiguous': array<string,string[]>,
 *   'orphan_files': string[],
 * }
 */
function scoop_allergen_icons_scan(): array {

  try {
    $pod = pods('allergen');
    if (!$pod) {
      return ['ok' => false, 'error' => "Could not load 'allergen' pod — check the Pods content type slug", 'rows' => [], 'counts' => [], 'ambiguous' => [], 'orphan_files' => []];
    }

    $index = scoop_allergen_icons_index();
    $files = $index['files'];
    $claimed_keys = [];

    $rows = [];
    $counts = ['will_set' => 0, 'will_overwrite' => 0, 'skip_identical' => 0, 'no_image_found' => 0];

    $pod->find(['limit' => -1, 'orderby' => 'post_title ASC']);

    while ($pod->fetch()) {
      $allergen_id = (int) $pod->id();
      $title = (string) ($pod->row['post_title'] ?? '');
      if ($title === '') continue;

      $key = scoop_allergen_icon_normalize($title);
      $source_path = $files[$key] ?? null;
      $matched_filename = $source_path ? basename($source_path) : '';
      $current_attachment_id = scoop_allergen_icons_current_attachment_id($pod);

      if ($source_path === null) {
        $action = 'no_image_found';
      } else {
        $claimed_keys[$key] = true;
        if ($current_attachment_id <= 0) {
          $action = 'will_set';
        } else {
          $current_path = get_attached_file($current_attachment_id);
          $identical = $current_path
            && file_exists($current_path)
            && filesize($current_path) === filesize($source_path)
            && md5_file($current_path) === md5_file($source_path);
          $action = $identical ? 'skip_identical' : 'will_overwrite';
        }
      }

      $counts[$action]++;

      $rows[] = [
        'id' => $allergen_id,
        'title' => $title,
        'matched_filename' => $matched_filename,
        'source_path' => $source_path ?? '',
        'current_attachment_id' => $current_attachment_id,
        'action' => $action,
      ];
    }

    // Files present on disk that no allergen title claimed.
    $orphan_files = [];
    foreach ($files as $key => $path) {
      if (!isset($claimed_keys[$key])) $orphan_files[] = basename($path);
    }
    sort($orphan_files);

    return [
      'ok' => true,
      'error' => null,
      'rows' => $rows,
      'counts' => $counts,
      'ambiguous' => $index['ambiguous'],
      'orphan_files' => $orphan_files,
    ];

  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => 'Scan failed: ' . $e->getMessage(), 'rows' => [], 'counts' => [], 'ambiguous' => [], 'orphan_files' => []];
  }
}

/**
 * Apply a scan result: sideload + write the 'icon' field for every row
 * whose action is 'will_set' or 'will_overwrite'.
 *
 * Note: this does NOT delete the old attachment on overwrite — it just
 * repoints the field at the new one.
 *
 * @return array {
 *   'ok': bool,
 *   'set_count': int,
 *   'overwrite_count': int,
 *   'errors': string[],
 *   'log': array<int, array{id:int, title:string, filename:string, old_attachment_id:int, new_attachment_id:int}>,
 * }
 */
function scoop_allergen_icons_apply(array $scan_rows): array {

  $errors = [];
  $log = [];
  $set_count = 0;
  $overwrite_count = 0;

  foreach ($scan_rows as $row) {
    if ($row['action'] !== 'will_set' && $row['action'] !== 'will_overwrite') continue;

    $new_id = scoop_flavor_photos_sideload($row['source_path'], $row['matched_filename']);
    if (is_wp_error($new_id)) {
      $errors[] = "Allergen #{$row['id']} ({$row['title']}): {$new_id->get_error_message()}";
      continue;
    }

    try {
      $allergen_pod = pods('allergen', $row['id']);
      if (!$allergen_pod || !$allergen_pod->id()) {
        $errors[] = "Allergen #{$row['id']} ({$row['title']}): not found when saving";
        continue;
      }

      $result = $allergen_pod->save(['icon' => $new_id]);
      if ($result === false) {
        $errors[] = "Allergen #{$row['id']} ({$row['title']}): pods()->save() returned false";
        continue;
      }
    } catch (\Throwable $e) {
      $errors[] = "Allergen #{$row['id']} ({$row['title']}): {$e->getMessage()}";
      continue;
    }

    if ($row['action'] === 'will_set') $set_count++;
    else $overwrite_count++;

    $log[] = [
      'id' => $row['id'],
      'title' => $row['title'],
      'filename' => $row['matched_filename'],
      'old_attachment_id' => $row['current_attachment_id'],
      'new_attachment_id' => $new_id,
    ];
  }

  return [
    'ok' => count($errors) === 0,
    'set_count' => $set_count,
    'overwrite_count' => $overwrite_count,
    'errors' => $errors,
    'log' => $log,
  ];
}
