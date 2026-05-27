<?php
/**
 * Periodic Pods cache refresh.
 *
 * Background: this plugin's direct-write paths (scoop_create_batch_tubs_direct
 * being the main one) bypass Pods's internal save flow for speed. That speed
 * comes at a cost — Pods's per-item relationship cache for the affected
 * batches/flavors/locations isn't invalidated. Most consumers refresh on next
 * read, but a few (Admin Columns most notably) read through a layer that
 * caches Pods's cached value, so they keep showing pre-write state until
 * someone re-saves the affected record by hand.
 *
 * Strategy: a recurring WP-Cron event clears `pods_items_<pod>` for every Pod
 * the grids care about. Two-hour cadence is a tunable balance between cache
 * hit rate and staleness ceiling.
 *
 * Caveat: WP-Cron fires only on inbound traffic. On a low-traffic site the
 * cadence drifts. If the 2hr cadence matters operationally, replace WP-Cron
 * with a real system cron hitting wp-cron.php from the OS.
 */

if (!defined('ABSPATH')) exit;

/**
 * Pods covered by the periodic refresh. Add new CPTs here when they're added
 * to the schema and consumed by grid/Admin-Columns layers that read through
 * Pods's cache.
 *
 * @return array<int,string>
 */
function scoop_cron_pods_to_refresh(): array {
  return [
    'tub', 'batch', 'flavor', 'slot', 'cabinet', 'closeout',
    'location', 'use', 'nightly_sales', 'inventory_change',
  ];
}

/**
 * Register a custom 2-hour cron slot. WP ships hourly/twicedaily/daily but
 * not 2hr, so we add our own.
 */
add_filter('cron_schedules', function($schedules) {
  if (!isset($schedules['scoop_every_2_hours'])) {
    $schedules['scoop_every_2_hours'] = [
      'interval' => 2 * HOUR_IN_SECONDS,
      'display'  => 'Every 2 hours (Scoop)',
    ];
  }
  return $schedules;
});

/**
 * Ensure the event is scheduled on every load. Cheap when it already exists;
 * resilient to plugin deactivation/reactivation cycles or DB rollbacks that
 * could otherwise lose the schedule.
 */
add_action('init', 'scoop_cron_ensure_scheduled');

function scoop_cron_ensure_scheduled(): void {
  if (!wp_next_scheduled('scoop_periodic_cache_refresh')) {
    // Start 60s out to avoid double-firing during plugin upgrades.
    wp_schedule_event(time() + 60, 'scoop_every_2_hours', 'scoop_periodic_cache_refresh');
  }
}

/**
 * Unschedule on deactivation so we don't orphan a cron entry pointing at a
 * function that no longer exists.
 */
if (defined('SCOOP_REST_FILE')) {
  register_deactivation_hook(SCOOP_REST_FILE, function() {
    $ts = wp_next_scheduled('scoop_periodic_cache_refresh');
    if ($ts) wp_unschedule_event($ts, 'scoop_periodic_cache_refresh');
  });
}

/**
 * Wire the callback to the cron event.
 */
add_action('scoop_periodic_cache_refresh', 'scoop_run_periodic_cache_refresh');

/**
 * The actual cache-flush callback. Invoked by:
 *   - The scheduled cron event (every ~2 hours)
 *   - The `wp scoop cache-refresh` WP-CLI command (manual trigger)
 *
 * Clears Pods's per-item cache for each Pod listed in
 * scoop_cron_pods_to_refresh(), then bumps the bundle cache version.
 *
 * @return array{cleared:array<int,string>, bumped_bundle:bool}
 */
function scoop_run_periodic_cache_refresh(): array {
  $cleared = [];

  if (function_exists('pods_cache_clear')) {
    foreach (scoop_cron_pods_to_refresh() as $pod) {
      pods_cache_clear(false, "pods_items_{$pod}");
      $cleared[] = $pod;
    }
  }

  $bumped_bundle = false;
  if (function_exists('scoop_cache_bust')) {
    scoop_cache_bust();
    $bumped_bundle = true;
  }

  if (function_exists('scoop_debug_log')) {
    scoop_debug_log(
      'scoop_run_periodic_cache_refresh: cleared ' . count($cleared)
      . ' pod caches (' . implode(',', $cleared) . ')'
      . ($bumped_bundle ? '; bumped bundle cache' : '')
    );
  }

  return [
    'cleared'       => $cleared,
    'bumped_bundle' => $bumped_bundle,
  ];
}
