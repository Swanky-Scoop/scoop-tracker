<?php
if (!defined('ABSPATH')) exit;

/**
 * Flavor Photos — matches files in flavor-photos/*.webp (plugin root) to
 * flavor titles and writes them into each flavor's Pods 'photo' field.
 *
 * Filenames are expected to already be sanitized versions of the flavor
 * title (see scoop_flavor_photo_sanitize_title()) — this is how the batch
 * of images shipped in flavor-photos/ was named. Re-running this is safe:
 * flavors whose current photo already matches the file on disk (by hash)
 * are left alone; anything else with a matching file is (re)written.
 *
 * Two-step workflow like the reconciler: scan (dry-run) and apply (commit).
 */

/**
 * Sanitize a flavor title into the filename convention used in
 * flavor-photos/: every run of non-alphanumeric characters becomes a
 * single underscore, with leading/trailing underscores stripped.
 *
 * "Strawberry Rhubarb Streusel (PEA)" -> "Strawberry_Rhubarb_Streusel_PEA"
 */
function scoop_flavor_photo_sanitize_title(string $title): string {
  $s = preg_replace('/[^A-Za-z0-9]+/', '_', $title);
  return trim($s, '_');
}

function scoop_flavor_photos_dir(): string {
  return SCOOP_REST_DIR . 'flavor-photos/';
}

/**
 * Read the current attachment ID (if any) out of a loaded flavor pod's
 * 'photo' field. Mirrors the array/numeric shapes documented next to
 * scoop_file_url_out() in bundle-fetch.php.
 */
function scoop_flavor_photos_current_attachment_id($pod): int {
  $v = $pod->field('photo');

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
 * Scan all flavors and match against files in flavor-photos/.
 *
 * @return array {
 *   'ok': bool,
 *   'error': string|null,
 *   'rows': array<int, array{
 *     id:int, title:string, expected_filename:string, source_path:string,
 *     current_attachment_id:int, action:string
 *   }>,
 *   'counts': array<string,int>,
 * }
 */
function scoop_flavor_photos_scan(): array {

  try {
    $pod = pods('flavor');
    if (!$pod) {
      return ['ok' => false, 'error' => 'Could not load flavor pod', 'rows' => [], 'counts' => []];
    }

    $dir = scoop_flavor_photos_dir();
    $rows = [];
    $counts = ['will_set' => 0, 'will_overwrite' => 0, 'skip_identical' => 0, 'no_image_found' => 0];

    $pod->find(['limit' => -1, 'orderby' => 'post_title ASC']);

    while ($pod->fetch()) {
      $flavor_id = (int) $pod->id();
      $title = (string) ($pod->row['post_title'] ?? '');
      if ($title === '') continue;

      $expected_filename = scoop_flavor_photo_sanitize_title($title) . '.webp';
      $source_path = $dir . $expected_filename;
      $file_exists = file_exists($source_path);
      $current_attachment_id = scoop_flavor_photos_current_attachment_id($pod);

      if (!$file_exists) {
        $action = 'no_image_found';
      } elseif ($current_attachment_id <= 0) {
        $action = 'will_set';
      } else {
        $current_path = get_attached_file($current_attachment_id);
        $identical = $current_path
          && file_exists($current_path)
          && filesize($current_path) === filesize($source_path)
          && md5_file($current_path) === md5_file($source_path);
        $action = $identical ? 'skip_identical' : 'will_overwrite';
      }

      $counts[$action]++;

      $rows[] = [
        'id' => $flavor_id,
        'title' => $title,
        'expected_filename' => $expected_filename,
        'source_path' => $source_path,
        'current_attachment_id' => $current_attachment_id,
        'action' => $action,
      ];
    }

    return ['ok' => true, 'error' => null, 'rows' => $rows, 'counts' => $counts];

  } catch (\Throwable $e) {
    return ['ok' => false, 'error' => 'Scan failed: ' . $e->getMessage(), 'rows' => [], 'counts' => []];
  }
}

/**
 * Copy a local file into the media library as a new attachment.
 *
 * @return int|WP_Error  New attachment ID, or WP_Error on failure.
 */
function scoop_flavor_photos_sideload(string $path, string $filename) {

  if (!function_exists('wp_generate_attachment_metadata')) {
    require_once ABSPATH . 'wp-admin/includes/image.php';
  }

  $bits = file_get_contents($path);
  if ($bits === false) {
    return new WP_Error('scoop_flavor_photo_read_failed', "Could not read {$path}");
  }

  $upload = wp_upload_bits($filename, null, $bits);
  if (!empty($upload['error'])) {
    return new WP_Error('scoop_flavor_photo_upload_failed', $upload['error']);
  }

  $filetype = wp_check_filetype($filename, null);

  $attachment_id = wp_insert_attachment([
    'post_mime_type' => $filetype['type'] ?: 'image/webp',
    'post_title'      => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
    'post_content'    => '',
    'post_status'     => 'inherit',
  ], $upload['file']);

  if (is_wp_error($attachment_id)) {
    return $attachment_id;
  }

  $metadata = wp_generate_attachment_metadata($attachment_id, $upload['file']);
  wp_update_attachment_metadata($attachment_id, $metadata);

  return $attachment_id;
}

/**
 * Apply a scan result: sideload + write the 'photo' field for every row
 * whose action is 'will_set' or 'will_overwrite'. Rows the caller doesn't
 * select (via $selected_ids, when non-null) are left untouched.
 *
 * Note: this does NOT delete the old attachment on overwrite — it just
 * repoints the field at the new one, so previous images are left as
 * orphaned media rather than risk deleting something still in use.
 *
 * @return array {
 *   'ok': bool,
 *   'set_count': int,
 *   'overwrite_count': int,
 *   'errors': string[],
 *   'log': array<int, array{id:int, title:string, filename:string, old_attachment_id:int, new_attachment_id:int}>,
 * }
 */
function scoop_flavor_photos_apply(array $scan_rows, ?array $selected_ids = null): array {

  $errors = [];
  $log = [];
  $set_count = 0;
  $overwrite_count = 0;

  foreach ($scan_rows as $row) {
    if ($row['action'] !== 'will_set' && $row['action'] !== 'will_overwrite') continue;
    if ($selected_ids !== null && !in_array($row['id'], $selected_ids, true)) continue;

    $new_id = scoop_flavor_photos_sideload($row['source_path'], $row['expected_filename']);
    if (is_wp_error($new_id)) {
      $errors[] = "Flavor #{$row['id']} ({$row['title']}): {$new_id->get_error_message()}";
      continue;
    }

    try {
      $flavor_pod = pods('flavor', $row['id']);
      if (!$flavor_pod || !$flavor_pod->id()) {
        $errors[] = "Flavor #{$row['id']} ({$row['title']}): not found when saving";
        continue;
      }

      $result = $flavor_pod->save(['photo' => $new_id]);
      if ($result === false) {
        $errors[] = "Flavor #{$row['id']} ({$row['title']}): pods()->save() returned false";
        continue;
      }
    } catch (\Throwable $e) {
      $errors[] = "Flavor #{$row['id']} ({$row['title']}): {$e->getMessage()}";
      continue;
    }

    if ($row['action'] === 'will_set') $set_count++;
    else $overwrite_count++;

    $log[] = [
      'id' => $row['id'],
      'title' => $row['title'],
      'filename' => $row['expected_filename'],
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
