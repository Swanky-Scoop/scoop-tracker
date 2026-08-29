<?php
/**
 * End-to-end harness for scoop_log_post's TubSplit wiring (rest.php).
 * Asserts the TASK ACCEPTANCE CRITERIA directly: a TubSplit create must
 * produce an audit record whose phase != 'unknown', whose tubs/flavors
 * relations are populated, and whose title is not "created 0 tub of ...".
 * Also proves convert-in-place records as an update with phase 'converted',
 * and that non-tub create routes (Batch) are 100% untouched.
 */
namespace scope {
  $TUBS = []; $TITLES = []; $ADD_CAPTURE = null; $CURRENT_USER = 'gus';

  function pods(string $pod, $id = null) {
    global $TUBS, $ADD_CAPTURE;
    if ($pod === 'inventory_change') {
      return new class($ADD_CAPTURE) {
        public $cap; public function __construct(&$cap) { $this->cap = &$cap; }
        public function add(array $data) { $this->cap = $data; return 4242; }
      };
    }
    if ($pod === 'tub' && $id !== null && isset($TUBS[(int)$id])) {
      $tub = $TUBS[(int)$id];
      return new class($tub) {
        private $tub; public function __construct($t) { $this->tub = $t; }
        public function exists(): bool { return true; }
        public function field(string $f) { return $this->tub[$f] ?? null; }
      };
    }
    return null;
  }
  function get_the_title($id) { global $TITLES; return $TITLES[(int)$id] ?? ''; }
  function scoop_rel_id($v) { return is_numeric($v) ? (int)$v : 0; }
  function wp_get_current_user() {
    return new class { public $user_login = 'gus'; };
  }
  function wp_kses($s, $a) { return $s; }
  function is_wp_error($t) { return false; }
}

namespace {
  require __DIR__ . '/../../includes/rest.php';

  function pods(string $pod, $id = null) { return \scope\pods($pod, $id); }
  function get_the_title($id) { return \scope\get_the_title($id); }
  function scoop_rel_id($v) { return \scope\scoop_rel_id($v); }
  function wp_get_current_user() { return \scope\wp_get_current_user(); }
  function wp_kses($s, $a) { return \scope\wp_kses($s, $a); }

  global $TUBS, $TITLES, $ADD_CAPTURE;
  $TODAY = date('D m/d');
  $TUBS = [
    101 => ['flavor' => 55, 'amount' => 2.0],
  ];
  $TITLES = [
    101 => 'Chocolate 5gal', 201 => 'Chocolate 5gal/Grab-and-go',
    55 => 'Chocolate', 10 => 'Grab-and-go',
  ];

  $fails = 0;
  function check(string $name, bool $ok, $got = null, $want = null): void {
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? "PASS" : "FAIL") . " {$name}" . (!$ok ? (" got=" . var_export($got, true) . " want=" . var_export($want, true)) : '') . "\n";
  }

  $cfg_tubsplit = [
    'envelope_key' => 'TubSplit', 'post_type' => 'tub', 'pod_name' => 'tub', 'mode' => 'create',
  ];

  // A minimal WP_REST_Request stand-in: log_post only calls get_param().
  class WP_REST_Request {
    private $params;
    public function __construct(array $p = []) { $this->params = $p; }
    public function get_param($k) { return $this->params[$k] ?? null; }
  }

  // ---- 1. TRUE SPLIT: the exact client shape, new tub 201 --------------
  // At log time scoop_create_pod_item's second write has already reduced
  // the origin 2.0 -> 0.5; mirror that in the fixture, restore for convert.
  $TUBS[101]['amount'] = 0.5;
  $ADD_CAPTURE = null;
  scoop_log_post(
    new WP_REST_Request(['TubSplit' => ['cells' => ['0' => ['use' => 10, 'amount' => 1.5, 'origin_tub_id' => 101]]]]),
    $cfg_tubsplit,
    ['use' => 10, 'amount' => 1.5, 'origin_tub_id' => 101],   // flat row, as create dispatch passes it
    [],                                                        // no errors
    201                                                        // $created_id = the NEW tub
  );
  $d = $ADD_CAPTURE;
  check('split: record written', is_array($d), $d);
  check('split: AC phase != unknown', is_array($d) && $d['phase'] !== 'unknown' && $d['phase'] === 'created', $d['phase'] ?? null, 'created');
  check('split: AC title not "created 0 tub of"', is_array($d) && strpos($d['title'], 'created 0 tub of') === false, $d['title'] ?? null);
  check('split: AC tubs populated', is_array($d) && $d['tubs'] === [201, 101], $d['tubs'] ?? null, '[201,101]');
  check('split: AC flavors populated', is_array($d) && $d['flavors'] === [55], $d['flavors'] ?? null, '[55]');
  check('split: mode create', is_array($d) && $d['mode'] === 'create', $d['mode'] ?? null);
  check('split: title meaningful', is_array($d) && $d['title'] === "gus split 1.5 off Chocolate 5gal of Chocolate for Grab-and-go on $TODAY", $d['title'] ?? null);
  check('split: details name new tub + origin', is_array($d) && strpos($d['details'], '<strong>Chocolate 5gal/Grab-and-go</strong>') !== false && strpos($d['details'], '<strong>Chocolate 5gal</strong>') !== false, $d['details'] ?? null);
  check('split: details show use/amount/origin (ids resolve to titles, as every audit row renders)', is_array($d) && strpos($d['details'], 'use => Grab-and-go') !== false && strpos($d['details'], 'amount => 1.5') !== false && strpos($d['details'], 'origin_tub_id => Chocolate 5gal') !== false, $d['details'] ?? null);
  check('split: details show origin post-split amount', is_array($d) && preg_match('/<strong>Chocolate 5gal<\/strong><br \/>amount => 0\.5/', $d['details']) === 1, $d['details'] ?? null);
  check('split: count 2', is_array($d) && $d['change_count'] === 2, $d['change_count'] ?? null, 2);
  check('split: envelope preserved', is_array($d) && $d['envelope'] === 'TubSplit', $d['envelope'] ?? null);
  check('split: source tub', is_array($d) && $d['source'] === 'tub', $d['source'] ?? null);

  // ---- 2. CONVERT-IN-PLACE: created_id == origin ------------------------
  $TUBS[101]['amount'] = 2.0; // convert leaves amount untouched
  $ADD_CAPTURE = null;
  scoop_log_post(
    new WP_REST_Request(['TubSplit' => ['cells' => ['0' => ['use' => 10, 'amount' => 2.0, 'origin_tub_id' => 101]]]]),
    $cfg_tubsplit,
    ['use' => 10, 'amount' => 2.0, 'origin_tub_id' => 101],
    [],
    101
  );
  $d2 = $ADD_CAPTURE;
  check('convert: record written', is_array($d2), $d2);
  check('convert: mode update', is_array($d2) && $d2['mode'] === 'update', $d2['mode'] ?? null, 'update');
  check('convert: phase converted', is_array($d2) && $d2['phase'] === 'converted', $d2['phase'] ?? null, 'converted');
  check('convert: title', is_array($d2) && $d2['title'] === "gus converted Chocolate 5gal of Chocolate to Grab-and-go on $TODAY", $d2['title'] ?? null);
  check('convert: tubs [101]', is_array($d2) && $d2['tubs'] === [101], $d2['tubs'] ?? null);
  check('convert: details = fields written', is_array($d2) && strpos($d2['details'], 'use => Grab-and-go') !== false && strpos($d2['details'], 'state => Emptied') !== false, $d2['details'] ?? null);
  check('convert: count 1', is_array($d2) && $d2['change_count'] === 1, $d2['change_count'] ?? null);

  // ---- 3. FAILED create: generic error path untouched -------------------
  $ADD_CAPTURE = null;
  scoop_log_post(
    new WP_REST_Request(['TubSplit' => ['cells' => ['0' => ['use' => 10, 'amount' => 1.5, 'origin_tub_id' => 101]]]]),
    $cfg_tubsplit,
    ['use' => 10, 'amount' => 1.5, 'origin_tub_id' => 101],
    [['field' => 'use', 'error' => 'tub_split_missing_use']],
    0
  );
  $d3 = $ADD_CAPTURE;
  check('error: generic title (no override; count from cells)', is_array($d3) && $d3['title'] === "gus created 1 tub on $TODAY", $d3['title'] ?? null);
  check('error: problem flag set', is_array($d3) && $d3['problem'] === 'error', $d3['problem'] ?? null);

  // ---- 4. BATCH create: completely untouched ---------------------------
  $ADD_CAPTURE = null;
  scoop_log_post(
    new WP_REST_Request(['Batch' => ['cells' => ['0' => ['flavor' => 55, 'count' => 3]]]]),
    ['envelope_key' => 'Batch', 'post_type' => 'batch', 'pod_name' => 'batch', 'mode' => 'create'],
    ['flavor' => 55, 'count' => 3],
    [],
    301
  );
  $d4 = $ADD_CAPTURE;
  check('batch: title unchanged shape (legacy create title, no user prefix)', is_array($d4) && $d4['title'] === "created 3 batch of Chocolates on $TODAY", $d4['title'] ?? null);
  check('batch: entity batch', is_array($d4) && $d4['entity'] === 'batch', $d4['entity'] ?? null);
  check('batch: tubs from batch relation', is_array($d4) && $d4['tubs'] === [] || is_array($d4) && $d4['tubs'] === [301], $d4['tubs'] ?? null, '[] or [301]');

  echo $fails === 0 ? "ALL PASS\n" : "{$fails} FAILURES\n";
  exit($fails === 0 ? 0 : 1);
}
