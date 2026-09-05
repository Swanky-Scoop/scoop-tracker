<?php
/**
 * /tubs save-chain diagnostics — per-step breadcrumbs + shutdown capture.
 *
 * WHY THIS EXISTS (cabinet-500 investigation, Sep 2026):
 * Production 500s on POST /wp-json/scoop/v1/tubs (4 observed: Sep 3 01:23:06 EDT,
 * Sep 4 03:49:41 / 03:56:50 / 03:59:37 EDT) leave NO PHP trace at all — no fatal,
 * no warning, and the failing requests also left no inventory_change audit line,
 * while neighboring identical POSTs logged one. That brackets the death inside the
 * Pods save/hook phase (scoop_pods_api_save → tub/slot pre_save + post_save hook
 * chain → the reconcile/mark_moving/sync_flavor_request/topup chain), before the
 * audit write, in a way PHP does not log: either a process kill at the server
 * layer (this box runs Imunify360) or Pods' own REST error path, which terminates
 * the request via wp_send_json(..., 500) + die() — an uncatchable death that
 * writes no error_log line (see scoop_flavor_request_schema_ready()'s comment in
 * hooks/cabinet-slot.php). error_get_last() + try/catch can never see either; only
 * a breadcrumb trail written (and flushed) step-by-step survives.
 *
 * WHAT IT DOES
 * - scoop_diag($step, $ctx): appends ONE line per save-chain phase boundary to
 *   wp-content/scoop-diag/scoop-diag.log (never a public URL — the directory is
 *   shipped with a deny-all .htaccess for LiteSpeed/Apache; this estate is
 *   LiteSpeed). file_put_contents opens/writes/closes per call, so every line
 *   reaches the OS immediately and survives even a segfault / kill -9 / OOM kill
 *   of the worker mid-request.
 * - register_shutdown_function captures error_get_last() + the last few
 *   breadcrumbs at request end. The shutdown hook runs on normal exit AND on
 *   die()/exit AND on PHP fatals — but NOT on a SIGKILL/segfault — so the trail
 *   shape itself is the diagnosis:
 *       full trail + "shutdown" line        → request completed (or died via
 *                                             die()/wp_send_json; the recorded
 *                                             response_code tells which: a 500
 *                                             with no PHP error = Pods' json
 *                                             error path)
 *       trail that just STOPS, no "shutdown" line
 *                                           → process killed at the OS layer
 *                                             (segfault / OOM / external kill)
 *   That dichotomy is the diagnosis: the last line written names the exact
 *   hook/save call that was in flight.
 *
 * OPERATION
 * - Always-on by default (the point is to catch the next real failure without
 *   anyone remembering to flip a flag). To disable: define('SCOOP_DIAG', false);
 *   in wp-config.php. It also disables itself for the rest of a request if the
 *   log directory can't be created/written (fail-safe, never breaks a save).
 * - Volume is bounded: an overflow cap stops logging after 500 lines in one
 *   request (re-entrant reconcile chains can nest), and the log rotates at 5 MB
 *   to scoop-diag.log.1.
 * - Removal: delete this file and the scoop_diag(...) calls (all marked with
 *   `// SCOOP-DIAG`), plus the one scoop_require line in scoop_rest.php.
 */

if (!defined('ABSPATH')) exit;

if (!defined('SCOOP_DIAG')) {
  define('SCOOP_DIAG', true); // flip to false in wp-config.php to disable
}

/**
 * One breadcrumb line. $ctx values must be scalar-ish (ids, field-name arrays,
 * short strings) — they are json-encoded per line. Never logs user content.
 */
function scoop_diag(string $step, array $ctx = []): void {
  static $lines_this_request = 0;
  static $rotated = false;

  if (!scoop_diag_enabled()) return;

  // Overflow cap: never let a runaway chain write unbounded log lines.
  if (++$lines_this_request > 500) {
    if ($lines_this_request === 501) {
      @file_put_contents(
        scoop_diag_path(),
        sprintf("%s|%s|%s|{\"note\":\"breadcrumb cap reached; further lines suppressed this request\"}||shutdown|%sM\n",
          gmdate('Y-m-d\TH:i:s') . 'Z', scoop_diag_reqid(), 'diag_overflow',
          number_format(memory_get_peak_usage(true) / 1048576, 1)),
        FILE_APPEND | LOCK_EX
      );
    }
    return;
  }

  if (!$rotated) {
    $rotated = true;
    $log = scoop_diag_path();
    $size = @filesize($log);
    if ($size !== false && $size > 5 * 1024 * 1024) @rename($log, $log . '.1');
  }

  $t = microtime(true);
  $ts = gmdate('Y-m-d\TH:i:s', (int) $t) . sprintf('.%03d', (int) (($t - floor($t)) * 1000)) . 'Z';

  $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
  $caller = isset($bt[1]['file'])
    ? basename($bt[1]['file']) . ':' . ($bt[1]['line'] ?? '?')
    : '';

  $GLOBALS['scoop_diag_recent'][] = $step . ($caller ? " @$caller" : '');
  if (count($GLOBALS['scoop_diag_recent']) > 3) array_shift($GLOBALS['scoop_diag_recent']);

  $json = json_encode($ctx, JSON_PARTIAL_OUTPUT_ON_ERROR);
  if ($json === false) $json = '"(unencodable)"';

  @file_put_contents(
    scoop_diag_path(),
    sprintf(
      "%s|%s|%s|%s|%s|%sM\n",
      $ts,
      scoop_diag_reqid(),
      $step,
      $json,
      $caller,
      number_format(memory_get_peak_usage(true) / 1048576, 1)
    ),
    FILE_APPEND | LOCK_EX
  );
}

/** Latest breadcrumbs in order — shutdown records these so a hard kill is
 *  distinguishable from a clean finish even though the trail itself survives. */
function scoop_diag_recent(): array {
  return array_slice($GLOBALS['scoop_diag_recent'] ?? [], -3);
}

function scoop_diag_path(): string {
  return scoop_diag_dir() . '/scoop-diag.log';
}

function scoop_diag_dir(): string {
  // wp-content sibling dir — inside the install but never a public URL on this
  // estate (LiteSpeed honors the .htaccess scoop_diag_enabled() writes there).
  if (defined('WP_CONTENT_DIR')) return WP_CONTENT_DIR . '/scoop-diag';
  return sys_get_temp_dir() . '/scoop-diag';
}

function scoop_diag_reqid(): string {
  static $id = null;
  if ($id !== null) return $id;
  try { $r = bin2hex(random_bytes(3)); } catch (\Throwable $e) { $r = dechex(mt_rand()); }
  $pid = function_exists('getmypid') ? (int) getmypid() : 0;
  return $id = $r . '-' . $pid;
}

/**
 * Enabled + writable probe, latched per request (fail-safe: if the directory
 * can't be prepared, logging silently stays off for that request instead of
 * spewing warnings — this must never be able to break a real save).
 */
  // Enabled + writable probe, latched per request (fail-safe: if the directory
  // can't be prepared, logging silently stays off for that request instead of
  // spewing warnings — this must never be able to break a real save).
function scoop_diag_enabled(): bool {
    static $ok = null;
    if ($ok !== null) return $ok;

    if (!SCOOP_DIAG) return $ok = false;

    $dir = scoop_diag_dir();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (!is_dir($dir) || !is_writable($dir)) return $ok = false;

    // Keep the directory out of the web (LiteSpeed/Apache honor these).
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\n");
    $ix = $dir . '/index.html';
    if (!file_exists($ix)) @file_put_contents($ix, '');

    // Pre-create the log with an APPEND probe write: confirms writability AND
    // removes the first-write filesize() stat warning from error_get_last()
    // (which the shutdown hook records — a probe artifact must never masquerade
    // as the request's last error). APPEND, never truncate: trails must
    // accumulate across requests, and concurrent workers share this file.
    if (@file_put_contents(scoop_diag_path(), '', FILE_APPEND | LOCK_EX) === false) return $ok = false;
    error_clear_last();

    $GLOBALS['scoop_diag_recent'] = [];
    return $ok = true;
  }

/**
 * Runs at the very end of EVERY request that got this far — normal completion,
 * die()/exit (including Pods' wp_send_json+die error path), and PHP fatals; NOT
 * on a SIGKILL/segfault. What it records separates the two failure families:
 * error_get_last() set → PHP fatal; response_code already 500 with no PHP error
 * → the json-error/die() path; no shutdown line at all → OS-level kill.
 */
function scoop_diag_shutdown(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  if (!scoop_diag_enabled()) return;

  $err = error_get_last();
  $recent = $GLOBALS['scoop_diag_recent'] ?? [];
  $ctx = [
    'last'    => $recent ? $recent[count($recent) - 1] : '(none)',
    'trail'   => $recent,
    'code'    => http_response_code(),
    'aborted' => connection_aborted(),
    'mem'     => memory_get_peak_usage(true),
  ];
  if ($err) $ctx['error'] = $err; // ['type','message','file','line']

  @file_put_contents(
    scoop_diag_path(),
    sprintf(
      "%s|%s|%s|%s|shutdown|%sM\n",
      gmdate('Y-m-d\TH:i:s') . 'Z',
      scoop_diag_reqid(),
      'shutdown',
      json_encode($ctx, JSON_PARTIAL_OUTPUT_ON_ERROR),
      number_format(memory_get_peak_usage(true) / 1048576, 1)
    ),
    FILE_APPEND | LOCK_EX
  );
}

register_shutdown_function('scoop_diag_shutdown');
