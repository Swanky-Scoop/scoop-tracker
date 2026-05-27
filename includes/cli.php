<?php
/**
 * WP-CLI commands for the Scoop Rest plugin.
 *
 * Registered only when running under WP-CLI; zero overhead in normal HTTP/REST
 * requests because the early-return below short-circuits before any class is
 * defined.
 *
 * Commands:
 *   wp scoop audit  — Runs schema-integrity checks documented in README
 *                     ("The integrity rule, restated" and "Integrity audit
 *                     queries"). Reports orphan tub posts and bidirectional
 *                     drift between batches/flavors/locations and their tubs.
 *                     Exits non-zero if any issues are found, so it's safe to
 *                     wire into CI / cron / post-deploy verification.
 */

if (!defined('ABSPATH')) exit;
if (!defined('WP_CLI') || !WP_CLI) return;

class Scoop_CLI_Audit {

  /**
   * Verify the Pods-schema invariants documented in README.
   *
   * Two checks run, both expected to return zero rows on a healthy install:
   *   1. Orphan tub posts: `track_posts` rows with `post_type='tub'` that have
   *      no companion row in `track_pods_tub`. Such posts are invisible to
   *      every Pods consumer.
   *   2. Bidirectional drift: batches / flavors / locations whose count of
   *      forward podsrel rows (tub→owner) differs from the count of reverse
   *      rows (owner→tubs). Pods stores every bidirectional relationship as
   *      two rows; an asymmetric count means one side will display empty.
   *
   * Field IDs are resolved at runtime via the Pods config, so this command
   * survives any reinstall where Pods renumbers field IDs.
   *
   * ## OPTIONS
   *
   * [--skip-orphans]
   * : Skip the orphan-tub check.
   *
   * [--skip-bidir]
   * : Skip the bidirectional drift check.
   *
   * [--limit=<n>]
   * : Maximum rows to report per check. Default: 100.
   *
   * ## EXAMPLES
   *
   *     wp scoop audit
   *     wp scoop audit --skip-bidir
   *     wp scoop audit --limit=20
   *
   * @when after_wp_load
   */
  public function __invoke($args, $assoc_args) {
    global $wpdb;

    $limit = (int)\WP_CLI\Utils\get_flag_value($assoc_args, 'limit', 100);
    if ($limit < 1) $limit = 100;

    $skip_orphans = !empty($assoc_args['skip-orphans']);
    $skip_bidir   = !empty($assoc_args['skip-bidir']);

    $had_issue = false;

    if (!$skip_orphans) {
      \WP_CLI::log('');
      \WP_CLI::log('[1] Orphan tubs — track_posts.tub rows without a track_pods_tub companion');
      $orphans = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_title
         FROM {$wpdb->prefix}posts p
         LEFT JOIN {$wpdb->prefix}pods_tub t ON t.id = p.ID
         WHERE p.post_type = 'tub' AND t.id IS NULL
         LIMIT %d",
        $limit
      ), ARRAY_A);

      if (empty($orphans)) {
        \WP_CLI::log('  OK — 0 orphans.');
      } else {
        \WP_CLI::warning(count($orphans) . ' orphan tub post(s):');
        \WP_CLI\Utils\format_items('table', $orphans, ['ID', 'post_title']);
        $had_issue = true;
      }
    }

    if (!$skip_bidir) {
      \WP_CLI::log('');
      \WP_CLI::log('[2] Bidirectional drift — owners whose forward/reverse podsrel counts differ');

      $pairs = [
        ['batch',    'tubs', 'tub', 'batch'],
        ['flavor',   'tubs', 'tub', 'flavor'],
        ['location', 'tubs', 'tub', 'location'],
      ];

      foreach ($pairs as [$owner_pod, $owner_field, $related_pod, $related_field]) {
        $label = "{$owner_pod}.{$owner_field}  ↔  {$related_pod}.{$related_field}";
        \WP_CLI::log("  - {$label}");
        $drift = $this->detect_drift($wpdb, $owner_pod, $owner_field, $related_pod, $related_field, $limit);

        if (is_string($drift)) {
          \WP_CLI::warning("    {$drift}");
          $had_issue = true;
        } elseif (empty($drift)) {
          \WP_CLI::log('    OK — 0 drift.');
        } else {
          \WP_CLI::warning('    ' . count($drift) . ' row(s) with drift:');
          \WP_CLI\Utils\format_items('table', $drift, ['owner_id', 'post_title', 'forward_to_owner', 'reverse_from_owner']);
          $had_issue = true;
        }
      }
    }

    \WP_CLI::log('');
    if ($had_issue) {
      \WP_CLI::error('Audit failed — see issues above.');
    } else {
      \WP_CLI::success('All audits passed.');
    }
  }

  /**
   * Count forward (tub→owner) vs reverse (owner→tubs) podsrel rows per owner,
   * return only the ones that disagree.
   *
   * @return array|string List of rows with drift, or an error string.
   */
  private function detect_drift($wpdb, $owner_pod, $owner_field, $related_pod, $related_field, $limit) {
    $forward_field_id = $this->pods_field_id($wpdb, $related_pod, $related_field);
    $reverse_field_id = $this->pods_field_id($wpdb, $owner_pod,   $owner_field);

    if (!$forward_field_id || !$reverse_field_id) {
      return "Could not resolve field IDs for {$owner_pod}.{$owner_field} / {$related_pod}.{$related_field}; skipped.";
    }

    return $wpdb->get_results($wpdb->prepare(
      "SELECT b.ID AS owner_id, b.post_title,
        (SELECT COUNT(*) FROM {$wpdb->prefix}podsrel WHERE related_item_id = b.ID AND field_id = %d) AS forward_to_owner,
        (SELECT COUNT(*) FROM {$wpdb->prefix}podsrel WHERE item_id         = b.ID AND field_id = %d) AS reverse_from_owner
       FROM {$wpdb->prefix}posts b
       WHERE b.post_type = %s
       HAVING forward_to_owner <> reverse_from_owner
       LIMIT %d",
      $forward_field_id, $reverse_field_id, $owner_pod, $limit
    ), ARRAY_A);
  }

  /**
   * Resolve a Pods field ID from `(pod_name, field_name)` using the Pods config
   * stored in track_posts. Returns 0 if the field can't be found.
   */
  private function pods_field_id($wpdb, $pod_name, $field_name) {
    return (int)$wpdb->get_var($wpdb->prepare(
      "SELECT f.ID FROM {$wpdb->prefix}posts f
       WHERE f.post_type = '_pods_field'
         AND f.post_name = %s
         AND f.post_parent = (
           SELECT pp.ID FROM {$wpdb->prefix}posts pp
           WHERE pp.post_type = '_pods_pod' AND pp.post_name = %s
         )",
      $field_name, $pod_name
    ));
  }
}

WP_CLI::add_command('scoop audit', 'Scoop_CLI_Audit');


class Scoop_CLI_Cache_Refresh {

  /**
   * Manually trigger the periodic Pods cache refresh.
   *
   * Runs the same callback as the `scoop_periodic_cache_refresh` WP-Cron
   * event (every 2 hours by default). Useful when:
   *   - Plugins like Admin Columns are showing stale relationship data after
   *     a direct-write batch creation.
   *   - You want to verify the cron handler works without waiting for the
   *     next scheduled tick.
   *   - You've just edited scoop_cron_pods_to_refresh() and want to confirm
   *     the new Pod is flushed.
   *
   * See includes/cron.php for the underlying function.
   *
   * ## EXAMPLES
   *
   *     wp scoop cache-refresh
   *
   * @when after_wp_load
   */
  public function __invoke($args, $assoc_args) {
    if (!function_exists('scoop_run_periodic_cache_refresh')) {
      \WP_CLI::error('scoop_run_periodic_cache_refresh() is not loaded. Is the plugin active?');
    }

    $result = scoop_run_periodic_cache_refresh();

    if (empty($result['cleared'])) {
      \WP_CLI::warning('No Pods caches were cleared (is the Pods plugin active?).');
    } else {
      \WP_CLI::log('Cleared Pods caches for: ' . implode(', ', $result['cleared']));
    }

    if (!empty($result['bumped_bundle'])) {
      \WP_CLI::log('Bumped scoop_cache_version (bundle + analytics cache invalidated).');
    }

    \WP_CLI::success('Cache refresh complete.');
  }
}

WP_CLI::add_command('scoop cache-refresh', 'Scoop_CLI_Cache_Refresh');
