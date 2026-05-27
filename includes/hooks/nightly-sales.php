<?php
if (!defined('ABSPATH')) exit;

/**
 * Nightly sales hooks.
 *
 * Defaults new entry titles to the sale date, and enriches the record with
 * Woodinville daily weather from Open-Meteo when matching Pods fields exist.
 */

add_filter('pods_api_pre_save_pod_item_nightly_sales', 'scoop_prepare_nightly_sales', 10, 3);
function scoop_prepare_nightly_sales($pieces, $is_new_item, $id = 0) {
  if ($is_new_item && scoop_nightly_sales_pieces_have_upload($pieces)) {
    scoop_nightly_sales_set_title($pieces, 'Nightly sales import ' . current_time('Y-m-d H:i:s'));
    return $pieces;
  }

  $sale_date = scoop_nightly_sales_resolve_sale_date($pieces, (int)$id);

  if ($is_new_item) {
    scoop_nightly_sales_set_title($pieces, $sale_date);

    $date_field = scoop_nightly_sales_date_field();
    if ($date_field && (!isset($pieces['fields'][$date_field]['value']) || scoop_nodate($pieces['fields'][$date_field]['value']))) {
      scoop_nightly_sales_set_field($pieces, $date_field, $sale_date);
    }

    if (scoop_nightly_sales_has_weather_fields() && !scoop_nightly_sales_pieces_have_weather($pieces)) {
      $weather = scoop_nightly_sales_fetch_weather($sale_date);
      if ($weather) {
        scoop_nightly_sales_apply_weather($pieces, $weather);
      }
    }
  }

  return $pieces;
}

add_filter('pods_api_post_save_pod_item_nightly_sales', 'scoop_nightly_sales_process_upload', 20, 3);
function scoop_nightly_sales_process_upload($pieces, $is_new_item, $id) {
  $import_id = (int)$id;
  if (!$import_id || !scoop_nightly_sales_pieces_have_upload($pieces)) return $pieces;
  if (!function_exists('pods_api') || !is_object(pods_api())) return $pieces;

  $guard_key = "nightly_sales_import:{$import_id}";
  if (function_exists('scoop_guard_enter') && !scoop_guard_enter($guard_key)) return $pieces;

  try {
    $path = scoop_nightly_sales_upload_path($import_id);
    if (!$path || !is_readable($path)) {
      scoop_debug_log("nightly_sales import {$import_id}: upload file not readable");
      return $pieces;
    }

    $parsed = scoop_nightly_sales_parse_csv($path);
    $import_rows = [];
    foreach ($parsed['rows'] as $row) {
      if ((int)$row['waffle_cones'] === 0 && (int)$row['waffle_cones_gf'] === 0) {
        continue;
      }
      $import_rows[] = $row;
    }

    $weather_by_date = scoop_nightly_sales_fetch_weather_dates(array_column($import_rows, 'sales_date'));
    $created = 0;
    $updated = 0;
    $skipped = count($parsed['errors']) + (count($parsed['rows']) - count($import_rows));

    foreach ($import_rows as $row) {
      $existing_id = scoop_nightly_sales_find_existing_id($row['sales_date']);
      $data = scoop_nightly_sales_import_data($row);
      if (!empty($weather_by_date[$row['sales_date']])) {
        $data = array_merge($data, scoop_nightly_sales_weather_data($weather_by_date[$row['sales_date']]));
      }

      $params = [
        'pod'  => 'nightly_sales',
        'data' => $data,
      ];
      if ($existing_id) $params['id'] = $existing_id;

      $saved_id = pods_api()->save_pod_item($params);
      if (is_wp_error($saved_id) || !$saved_id) {
        $skipped++;
      } elseif ($existing_id) {
        $updated++;
      } else {
        $created++;
      }
    }

    $note = sprintf(
      'Imported nightly sales CSV. Created: %d. Updated: %d. Skipped: %d.',
      $created,
      $updated,
      $skipped
    );
    if (!empty($parsed['errors'])) {
      $note .= "\n\nSkipped rows:\n" . implode("\n", array_slice($parsed['errors'], 0, 50));
    }

    wp_update_post([
      'ID'           => $import_id,
      'post_content' => $note,
    ]);

    return $pieces;
  } finally {
    if (function_exists('scoop_guard_leave')) scoop_guard_leave($guard_key);
  }
}

function scoop_nightly_sales_pieces_have_upload(array $pieces): bool {
  if (!empty($pieces['fields']['upload']['value'])) return true;
  if (!empty($pieces['params']) && is_array($pieces['params']) && !empty($pieces['params']['data']) && is_array($pieces['params']['data']) && !empty($pieces['params']['data']['upload'])) return true;
  if (!empty($_FILES)) {
    foreach ($_FILES as $key => $file) {
      if (strpos((string)$key, 'upload') !== false && !empty($file['tmp_name'])) return true;
    }
  }

  return false;
}

function scoop_nightly_sales_pieces_have_weather(array $pieces): bool {
  foreach (array_keys(scoop_nightly_sales_weather_field_map()) as $field) {
    if (isset($pieces['fields'][$field]['value']) && $pieces['fields'][$field]['value'] !== '') return true;
    if (!empty($pieces['params']) && is_array($pieces['params']) && !empty($pieces['params']['data']) && is_array($pieces['params']['data']) && isset($pieces['params']['data'][$field]) && $pieces['params']['data'][$field] !== '') return true;
  }

  return false;
}

function scoop_nightly_sales_upload_path(int $id): string {
  if (!function_exists('pods')) return '';

  $pod = pods('nightly_sales', $id);
  if (!$pod || !$pod->exists()) return '';

  $upload = $pod->field('upload');
  $attachment_id = scoop_nightly_sales_attachment_id($upload);
  if ($attachment_id) {
    $path = get_attached_file($attachment_id);
    if (is_string($path) && $path !== '') return $path;
  }

  $url = scoop_nightly_sales_upload_url($upload);
  if ($url) {
    $uploads = wp_upload_dir();
    $baseurl = $uploads['baseurl'] ?? '';
    $basedir = $uploads['basedir'] ?? '';
    if ($baseurl && $basedir && strpos($url, $baseurl) === 0) {
      return $basedir . str_replace('/', DIRECTORY_SEPARATOR, substr($url, strlen($baseurl)));
    }
  }

  return '';
}

function scoop_nightly_sales_attachment_id($value): int {
  if (is_numeric($value)) return (int)$value;
  if (is_array($value)) {
    if (isset($value['ID']) && is_numeric($value['ID'])) return (int)$value['ID'];
    if (isset($value['id']) && is_numeric($value['id'])) return (int)$value['id'];
    $first = reset($value);
    return scoop_nightly_sales_attachment_id($first);
  }
  if (is_object($value)) {
    if (isset($value->ID) && is_numeric($value->ID)) return (int)$value->ID;
    if (isset($value->id) && is_numeric($value->id)) return (int)$value->id;
  }

  return 0;
}

function scoop_nightly_sales_upload_url($value): string {
  if (is_string($value) && preg_match('#^https?://#', $value)) return $value;
  if (is_array($value)) {
    foreach (['guid', 'url', 'link'] as $key) {
      if (!empty($value[$key]) && is_string($value[$key])) return $value[$key];
    }
    $first = reset($value);
    return scoop_nightly_sales_upload_url($first);
  }
  if (is_object($value)) {
    foreach (['guid', 'url', 'link'] as $key) {
      if (!empty($value->{$key}) && is_string($value->{$key})) return $value->{$key};
    }
  }

  return '';
}

function scoop_nightly_sales_parse_csv(string $path): array {
  $rows = [];
  $errors = [];
  $csv = [];

  $handle = fopen($path, 'r');
  if (!$handle) return ['rows' => [], 'errors' => ['Unable to open CSV file.']];

  while (($line = fgetcsv($handle)) !== false) {
    $csv[] = $line;
  }
  fclose($handle);

  $day_offsets = [
    'monday'    => 0,
    'tuesday'   => 1,
    'wednesday' => 2,
    'thursday'  => 3,
    'friday'    => 4,
    'saturday'  => 5,
    'sunday'    => 6,
  ];

  $seen = [];
  $row_count = count($csv);
  for ($i = 0; $i < $row_count; $i++) {
    $header = $csv[$i];
    $week_starts = scoop_nightly_sales_week_start_columns($header);
    if (empty($week_starts)) continue;

    for ($offset = 0; $offset < 7; $offset++) {
      $day_row_index = $i + 1 + $offset;
      if ($day_row_index >= $row_count) break;

      $day_row = $csv[$day_row_index];
      $day_name = strtolower(trim((string)($day_row[0] ?? '')));
      if (!isset($day_offsets[$day_name])) continue;

      foreach ($week_starts as $col => $week_start) {
        $sales_date = gmdate('Y-m-d', strtotime($week_start . " +{$day_offsets[$day_name]} days"));
        $waffle = scoop_nightly_sales_csv_int($day_row[$col] ?? null);
        $gf = scoop_nightly_sales_csv_int($day_row[$col + 1] ?? null);

        if ($waffle === null || $gf === null) {
          $errors[] = "Missing/invalid cone values for {$sales_date}.";
          continue;
        }

        if (isset($seen[$sales_date])) {
          $errors[] = "Duplicate sales date {$sales_date}; later duplicate skipped.";
          continue;
        }

        $seen[$sales_date] = true;
        $rows[] = [
          'sales_date'      => $sales_date,
          'waffle_cones'    => $waffle,
          'waffle_cones_gf' => $gf,
        ];
      }
    }
  }

  return ['rows' => $rows, 'errors' => $errors];
}

function scoop_nightly_sales_week_start_columns(array $row): array {
  $out = [];
  foreach ($row as $idx => $cell) {
    $date = scoop_nightly_sales_normalize_csv_date($cell);
    if ($date !== '') {
      $out[(int)$idx] = $date;
    }
  }

  return $out;
}

function scoop_nightly_sales_normalize_csv_date($value): string {
  $value = trim((string)$value);
  if ($value === '') return '';

  $value = preg_replace('#/+#', '/', $value);
  $dt = DateTime::createFromFormat('!n/j/Y', $value, wp_timezone());
  if (!$dt) return '';

  $errors = DateTime::getLastErrors();
  if (is_array($errors) && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0)) {
    return '';
  }

  return $dt->format('Y-m-d');
}

function scoop_nightly_sales_csv_int($value): ?int {
  $value = trim((string)$value);
  if ($value === '') return null;
  if (!preg_match('/^-?\d+$/', $value)) return null;

  return max(0, (int)$value);
}

function scoop_nightly_sales_find_existing_id(string $sales_date): int {
  if (!function_exists('pods')) return 0;

  $date_field = scoop_nightly_sales_date_field();
  if (!$date_field) return 0;

  $pod = pods('nightly_sales', [
    'where' => "{$date_field} = '" . esc_sql($sales_date) . "'",
    'limit' => 1,
  ]);
  if (!$pod || $pod->total() <= 0 || !$pod->fetch()) return 0;

  return (int)$pod->id();
}

function scoop_nightly_sales_import_data(array $row): array {
  $date_field = scoop_nightly_sales_date_field() ?: 'sales_date';
  $data = [
    'post_title'  => $row['sales_date'],
    'post_status' => 'publish',
    $date_field   => $row['sales_date'],
  ];

  foreach (scoop_nightly_sales_cone_field_map() as $field => $source) {
    if (scoop_nightly_sales_pod_has_field($field)) {
      $data[$field] = (int)$row[$source];
    }
  }

  if (scoop_nightly_sales_pod_has_field('location') && defined('SCOOP_DEFAULT_LOCATION_ID')) {
    $data['location'] = (int)SCOOP_DEFAULT_LOCATION_ID;
  } elseif (scoop_nightly_sales_pod_has_field('location') && function_exists('scoop_get_default_location_id')) {
    $location_id = (int)scoop_get_default_location_id();
    if ($location_id) $data['location'] = $location_id;
  }

  return $data;
}

function scoop_nightly_sales_weather_data(array $weather): array {
  $data = [];
  foreach (scoop_nightly_sales_weather_field_map() as $field => $weather_key) {
    if (!array_key_exists($weather_key, $weather)) continue;
    if (scoop_nightly_sales_pod_has_field($field)) {
      $data[$field] = $weather[$weather_key];
    }
  }

  return $data;
}

function scoop_nightly_sales_cone_field_map(): array {
  return [
    'waffle_cones'       => 'waffle_cones',
    'regular_cones'      => 'waffle_cones',
    'waffle_cones_gf'    => 'waffle_cones_gf',
    'gluten_free_cones'  => 'waffle_cones_gf',
  ];
}

function scoop_nightly_sales_resolve_sale_date(array $pieces, int $id = 0): string {
  foreach (scoop_nightly_sales_date_fields() as $field) {
    $incoming = $pieces['fields'][$field]['value'] ?? '';
    $date = scoop_nightly_sales_normalize_date($incoming);
    if ($date !== '') return $date;
  }

  if ($id > 0 && function_exists('pods')) {
    $pod = pods('nightly_sales', $id);
    if ($pod && $pod->exists()) {
      foreach (scoop_nightly_sales_date_fields() as $field) {
        $date = scoop_nightly_sales_normalize_date($pod->field($field));
        if ($date !== '') return $date;
      }
    }
  }

  return current_time('Y-m-d');
}

function scoop_nightly_sales_date_fields(): array {
  return ['sales_date', 'sale_date'];
}

function scoop_nightly_sales_date_field(): string {
  foreach (scoop_nightly_sales_date_fields() as $field) {
    if (scoop_nightly_sales_pod_has_field($field)) return $field;
  }

  return '';
}

function scoop_nightly_sales_normalize_date($value): string {
  if (empty($value) || scoop_nodate($value)) return '';

  if (is_numeric($value)) {
    return wp_date('Y-m-d', (int)$value);
  }

  $timestamp = strtotime((string)$value);
  if (!$timestamp) return '';

  return wp_date('Y-m-d', $timestamp);
}

function scoop_nightly_sales_set_title(array &$pieces, string $sale_date): void {
  if (!isset($pieces['object_fields']) || !is_array($pieces['object_fields'])) {
    $pieces['object_fields'] = [];
  }

  $pieces['object_fields']['post_title']['value'] = $sale_date;
  $pieces['object_fields']['post_name']['value']  = sanitize_title($sale_date);

  scoop_mark_active($pieces, 'post_title');
  scoop_mark_active($pieces, 'post_name');
}

function scoop_nightly_sales_set_field(array &$pieces, string $field, $value): void {
  if (!scoop_nightly_sales_pod_has_field($field)) return;

  if (!isset($pieces['fields']) || !is_array($pieces['fields'])) {
    $pieces['fields'] = [];
  }
  if (!isset($pieces['fields'][$field]) || !is_array($pieces['fields'][$field])) {
    $pieces['fields'][$field] = [];
  }

  $pieces['fields'][$field]['value'] = $value;
  scoop_mark_active($pieces, $field);
}

function scoop_nightly_sales_has_weather_fields(): bool {
  foreach (array_keys(scoop_nightly_sales_weather_field_map()) as $field) {
    if (scoop_nightly_sales_pod_has_field($field)) return true;
  }

  return false;
}

function scoop_nightly_sales_pod_has_field(string $field): bool {
  static $cache = [];
  $key = 'nightly_sales:' . $field;

  if (array_key_exists($key, $cache)) return $cache[$key];

  $cache[$key] = !empty(scoop_pods_field_def('nightly_sales', $field));
  return $cache[$key];
}

function scoop_nightly_sales_weather_field_map(): array {
  return [
    'tempature'         => 'tempature',
    'weather_quality'   => 'weather_quality',
    'temperature_2m_max' => 'temperature_2m_max',
    'temperature_2m_min' => 'temperature_2m_min',
    'weathercode'       => 'weathercode',
    'weather_code'      => 'weathercode',
    'temp_max'          => 'temperature_2m_max',
    'temp_min'          => 'temperature_2m_min',
    'high_temp'         => 'temperature_2m_max',
    'low_temp'          => 'temperature_2m_min',
  ];
}

function scoop_nightly_sales_fetch_weather(string $sale_date): array {
  $endpoint = ($sale_date >= current_time('Y-m-d'))
    ? 'https://api.open-meteo.com/v1/forecast'
    : 'https://archive-api.open-meteo.com/v1/archive';

  $url = add_query_arg(
    [
      'latitude'         => '47.7543',
      'longitude'        => '-122.1635',
      'start_date'       => $sale_date,
      'end_date'         => $sale_date,
      'daily'            => 'temperature_2m_max,temperature_2m_min,weathercode',
      'temperature_unit' => 'fahrenheit',
      'timezone'         => 'America/Los_Angeles',
    ],
    $endpoint
  );

  $response = wp_remote_get($url, ['timeout' => 8]);
  if (is_wp_error($response)) {
    scoop_debug_log('nightly_sales weather fetch failed: ' . $response->get_error_message());
    return [];
  }

  $code = (int)wp_remote_retrieve_response_code($response);
  if ($code < 200 || $code >= 300) {
    scoop_debug_log('nightly_sales weather fetch returned HTTP ' . $code);
    return [];
  }

  $body = json_decode(wp_remote_retrieve_body($response), true);
  if (!is_array($body) || empty($body['daily']) || !is_array($body['daily'])) {
    return [];
  }

  $daily = $body['daily'];
  $index = 0;
  if (!empty($daily['time']) && is_array($daily['time'])) {
    $match = array_search($sale_date, $daily['time'], true);
    $index = ($match === false) ? 0 : (int)$match;
  }

  $out = [];
  foreach (['temperature_2m_max', 'temperature_2m_min', 'weathercode'] as $key) {
    if (isset($daily[$key]) && is_array($daily[$key]) && array_key_exists($index, $daily[$key])) {
      $out[$key] = $daily[$key][$index];
    }
  }
  if (!isset($out['weathercode']) && isset($daily['weather_code']) && is_array($daily['weather_code']) && array_key_exists($index, $daily['weather_code'])) {
    $out['weathercode'] = $daily['weather_code'][$index];
  }

  if (isset($out['temperature_2m_max']) && is_numeric($out['temperature_2m_max'])) {
    $out['tempature'] = (int)round((float)$out['temperature_2m_max']);
  }

  if (isset($out['weathercode']) && is_numeric($out['weathercode'])) {
    $out['weather_quality'] = scoop_nightly_sales_weather_quality((int)$out['weathercode']);
  }

  return $out;
}

function scoop_nightly_sales_fetch_weather_dates(array $dates): array {
  $dates = array_values(array_unique(array_filter($dates, fn($date) => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))));
  sort($dates);
  if (empty($dates)) return [];

  $out = [];
  $today = current_time('Y-m-d');

  $groups = [
    'archive'  => array_values(array_filter($dates, fn($date) => $date < $today)),
    'forecast' => array_values(array_filter($dates, fn($date) => $date >= $today)),
  ];

  foreach ($groups as $kind => $group_dates) {
    if (empty($group_dates)) continue;

    $endpoint = ($kind === 'forecast')
      ? 'https://api.open-meteo.com/v1/forecast'
      : 'https://archive-api.open-meteo.com/v1/archive';

    foreach (scoop_nightly_sales_date_chunks($group_dates, 365) as $chunk) {
      $start = reset($chunk);
      $end = end($chunk);
      $daily = scoop_nightly_sales_fetch_weather_daily($endpoint, $start, $end);
      if (empty($daily['time']) || !is_array($daily['time'])) continue;

      foreach ($daily['time'] as $idx => $date) {
        if (!in_array($date, $chunk, true)) continue;

        $weather = [];
        foreach (['temperature_2m_max', 'temperature_2m_min', 'weathercode'] as $key) {
          if (isset($daily[$key]) && is_array($daily[$key]) && array_key_exists($idx, $daily[$key])) {
            $weather[$key] = $daily[$key][$idx];
          }
        }
        if (!isset($weather['weathercode']) && isset($daily['weather_code']) && is_array($daily['weather_code']) && array_key_exists($idx, $daily['weather_code'])) {
          $weather['weathercode'] = $daily['weather_code'][$idx];
        }
        if (isset($weather['temperature_2m_max']) && is_numeric($weather['temperature_2m_max'])) {
          $weather['tempature'] = (int)round((float)$weather['temperature_2m_max']);
        }
        if (isset($weather['weathercode']) && is_numeric($weather['weathercode'])) {
          $weather['weather_quality'] = scoop_nightly_sales_weather_quality((int)$weather['weathercode']);
        }

        if (!empty($weather)) $out[$date] = $weather;
      }
    }
  }

  return $out;
}

function scoop_nightly_sales_date_chunks(array $dates, int $max_days): array {
  $chunks = [];
  $chunk = [];
  $chunk_start = '';

  foreach ($dates as $date) {
    if (!$chunk) {
      $chunk = [$date];
      $chunk_start = $date;
      continue;
    }

    $span = (int)floor((strtotime($date) - strtotime($chunk_start)) / DAY_IN_SECONDS);
    if ($span > $max_days) {
      $chunks[] = $chunk;
      $chunk = [$date];
      $chunk_start = $date;
    } else {
      $chunk[] = $date;
    }
  }

  if ($chunk) $chunks[] = $chunk;
  return $chunks;
}

function scoop_nightly_sales_fetch_weather_daily(string $endpoint, string $start_date, string $end_date): array {
  $url = add_query_arg(
    [
      'latitude'         => '47.7543',
      'longitude'        => '-122.1635',
      'start_date'       => $start_date,
      'end_date'         => $end_date,
      'daily'            => 'temperature_2m_max,temperature_2m_min,weathercode',
      'temperature_unit' => 'fahrenheit',
      'timezone'         => 'America/Los_Angeles',
    ],
    $endpoint
  );

  $response = wp_remote_get($url, ['timeout' => 20]);
  if (is_wp_error($response)) {
    scoop_debug_log('nightly_sales weather batch fetch failed: ' . $response->get_error_message());
    return [];
  }

  $code = (int)wp_remote_retrieve_response_code($response);
  if ($code < 200 || $code >= 300) {
    scoop_debug_log('nightly_sales weather batch fetch returned HTTP ' . $code);
    return [];
  }

  $body = json_decode(wp_remote_retrieve_body($response), true);
  if (!is_array($body) || empty($body['daily']) || !is_array($body['daily'])) return [];

  return $body['daily'];
}

function scoop_nightly_sales_weather_quality(int $weathercode): int {
  if (in_array($weathercode, [0, 1], true)) return 5;
  if ($weathercode === 2) return 4;
  if (in_array($weathercode, [3, 45, 48], true)) return 3;
  if (in_array($weathercode, [65, 67, 75, 77, 82, 85, 86, 95, 96, 99], true)) return 1;
  if (($weathercode >= 51 && $weathercode <= 63) || ($weathercode >= 80 && $weathercode <= 81)) return 2;
  if ($weathercode >= 71 && $weathercode <= 73) return 1;

  return 3;
}

function scoop_nightly_sales_apply_weather(array &$pieces, array $weather): void {
  foreach (scoop_nightly_sales_weather_field_map() as $field => $weather_key) {
    if (!array_key_exists($weather_key, $weather)) continue;
    scoop_nightly_sales_set_field($pieces, $field, $weather[$weather_key]);
  }
}

add_action('admin_footer-post-new.php', 'scoop_nightly_sales_prefill_admin_form');
function scoop_nightly_sales_prefill_admin_form(): void {
  $screen = function_exists('get_current_screen') ? get_current_screen() : null;
  $screen_post_type = $screen ? (string)($screen->post_type ?? '') : '';
  $screen_id = $screen ? (string)($screen->id ?? '') : '';
  $request_post_type = isset($_GET['post_type']) ? sanitize_key((string)$_GET['post_type']) : '';

  if ($screen_post_type !== 'nightly_sales' && $request_post_type !== 'nightly_sales' && strpos($screen_id, 'nightly_sales') === false) {
    return;
  }

  $today = current_time('Y-m-d');
  ?>
  <script>
  (() => {
    const today = <?php echo wp_json_encode($today); ?>;

    const setIfEmpty = (el) => {
      if (!el || String(el.value || el.textContent || '').trim()) return;
      if ('value' in el) {
        el.value = today;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      } else {
        el.textContent = today;
        el.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: today }));
      }
    };

    const prefillEditorTitle = () => {
      const wpData = window.wp?.data;
      if (!wpData?.select || !wpData?.dispatch) return false;

      const editor = wpData.select('core/editor');
      const currentTitle = editor?.getEditedPostAttribute?.('title');
      if (String(currentTitle || '').trim()) return true;

      wpData.dispatch('core/editor')?.editPost?.({ title: today });
      return true;
    };

    const prefill = () => {
      prefillEditorTitle();

      [
        document.querySelector('#title'),
        document.querySelector('#post-title-0'),
        document.querySelector('input[name="post_title"]'),
        document.querySelector('textarea[name="post_title"]'),
        document.querySelector('.editor-post-title__input'),
        document.querySelector('[aria-label="Add title"]')
      ].forEach(setIfEmpty);

      [
        'input[name="pods_meta_sales_date"]',
        'input[name="pods_meta_sale_date"]',
        'input[name="sales_date"]',
        'input[name="sale_date"]',
        '#pods-form-ui-pods-meta-sales-date',
        '#pods-form-ui-pods-meta-sale-date'
      ].forEach((selector) => setIfEmpty(document.querySelector(selector)));
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', prefill, { once: true });
    } else {
      prefill();
    }
    window.wp?.domReady?.(prefill);
    setTimeout(prefill, 250);
    setTimeout(prefill, 1000);
    setTimeout(prefill, 2500);
  })();
  </script>
  <?php
}
