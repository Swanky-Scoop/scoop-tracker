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
  $sale_date = scoop_nightly_sales_resolve_sale_date($pieces, (int)$id);

  if ($is_new_item) {
    scoop_nightly_sales_set_title($pieces, $sale_date);

    $date_field = scoop_nightly_sales_date_field();
    if ($date_field && (!isset($pieces['fields'][$date_field]['value']) || scoop_nodate($pieces['fields'][$date_field]['value']))) {
      scoop_nightly_sales_set_field($pieces, $date_field, $sale_date);
    }
  }

  if (scoop_nightly_sales_has_weather_fields()) {
    $weather = scoop_nightly_sales_fetch_weather($sale_date);
    if ($weather) {
      scoop_nightly_sales_apply_weather($pieces, $weather);
    }
  }

  return $pieces;
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
  $url = add_query_arg(
    [
      'latitude'   => '47.7543',
      'longitude'  => '-122.1635',
      'start_date' => $sale_date,
      'end_date'   => $sale_date,
      'daily'      => 'temperature_2m_max,temperature_2m_min,weathercode',
      'timezone'   => 'America/Los_Angeles',
    ],
    'https://archive-api.open-meteo.com/v1/archive'
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

  return $out;
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
  if (!$screen || $screen->post_type !== 'nightly_sales') return;

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

    const prefill = () => {
      [
        document.querySelector('#title'),
        document.querySelector('.editor-post-title__input')
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
    setTimeout(prefill, 250);
    setTimeout(prefill, 1000);
  })();
  </script>
  <?php
}
