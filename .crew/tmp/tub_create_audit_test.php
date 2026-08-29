<?php
/**
 * Behavior harness for scoop_inventory_change_tub_create_audit (rest.php).
 * Stubs the WP/Pods surface the helper touches, then exercises all three
 * branches + the detail-rendering shape. Scoped WP function definitions —
 * the pod is scoped, so the includes' declarations can't collide with ours.
 */
namespace scope {
  error_reporting(E_ALL & ~E_DEPRECATED);
  $TUBS = [];      // tub_id => ['title'=>..,'flavor'=>..,'amount'=>..,'use'=>..]
  $TITLES = [];    // id => title (tubs + flavors + uses)

  function pods(string $pod, $id = null) {
    global $TUBS;
    if ($pod !== 'tub' || $id === null || !isset($TUBS[(int)$id])) return null;
    $tub = $TUBS[(int)$id];
    return new class($tub) {
      private $tub;
      public function __construct($tub) { $this->tub = $tub; }
      public function exists(): bool { return true; }
      public function field(string $f) { return $this->tub[$f] ?? null; }
    };
  }
  function get_the_title($id) { global $TITLES; return $TITLES[(int)$id] ?? ''; }
  function scoop_rel_id($v) { return is_numeric($v) ? (int)$v : 0; }
}

namespace {
  require __DIR__ . '/../../includes/rest.php';

  // Root-namespace wrappers: rest.php's code looks these up unqualified.
  // (scoop_rel_id + scoop_inventory_change_tub_flavor_id are real in rest.php;
  // only pods()/get_the_title() are WP/Pods surface needing stubs.)
  function pods(string $pod, $id = null) { return \scope\pods($pod, $id); }
  function get_the_title($id) { return \scope\get_the_title($id); }
  function scoop_rel_id($v) { return \scope\scoop_rel_id($v); }

  // ---- fixtures -------------------------------------------------------
  global $TUBS, $TITLES;
  $TUBS = [
    101 => ['title' => 'Chocolate 5gal', 'flavor' => 55, 'amount' => 2.0, 'use' => 9],
    102 => ['title' => 'Vanilla 3gal',  'flavor' => 56, 'amount' => 1.5, 'use' => 9],
    202 => ['title' => 'New split tub', 'flavor' => 55, 'amount' => 1.0, 'use' => 9],
  ];
  $TITLES = [
    101 => 'Chocolate 5gal', 102 => 'Vanilla 3gal',
    55 => 'Chocolate', 56 => 'Vanilla',
    9 => 'Front-of-house', 10 => 'Grab-and-go',
  ];

  $USER = 'gus';
  $DATE = 'Mon 08/31';
  $fails = 0;
  function check(string $name, bool $ok, $got = null, $want = null): void {
    global $fails;
    if (!$ok) { $fails++; }
    echo ($ok ? "PASS" : "FAIL") . " {$name}" . (!$ok ? (" got=" . var_export($got, true) . " want=" . var_export($want, true)) : '') . "\n";
  }

  // ---- 1. true split --------------------------------------------------
  $r = scoop_inventory_change_tub_create_audit(
    ['use' => 10, 'amount' => 1.5, 'origin_tub_id' => 101],
    201,   // new tub id, != origin
    $USER, $DATE
  );
  check('split: returns slice', is_array($r), $r, 'array');
  check('split: mode create', $r['mode'] === 'create', $r['mode'] ?? null, 'create');
  check('split: phase created', $r['phase'] === 'created', $r['phase'] ?? null, 'created');
  check('split: count 2', $r['count'] === 2, $r['count'] ?? null, 2);
  check('split: title', $r['title'] === 'gus split 1.5 off Chocolate 5gal of Chocolate for Grab-and-go on Mon 08/31', $r['title'] ?? null);
  check('split: tubs both', $r['tubs'] === [201, 101], $r['tubs'] ?? null, '[201,101]');
  check('split: flavors', $r['flavors'] === [55], $r['flavors'] ?? null, '[55]');
  check('split: new-tub detail row', $r['detail_rows'][201] === ['use' => 10, 'amount' => 1.5, 'origin_tub_id' => 101], $r['detail_rows'][201] ?? null);
  check('split: origin amount row present', isset($r['detail_rows'][101]) && array_key_exists('amount', $r['detail_rows'][101]), $r['detail_rows'][101] ?? null);

  // ---- 2. convert-in-place -------------------------------------------
  $r2 = scoop_inventory_change_tub_create_audit(
    ['use' => 10, 'amount' => 2.0, 'origin_tub_id' => 101],
    101,   // == origin: convert branch returned the origin id
    $USER, $DATE
  );
  check('convert: returns slice', is_array($r2), $r2, 'array');
  check('convert: mode update', $r2['mode'] === 'update', $r2['mode'] ?? null, 'update');
  check('convert: phase converted', $r2['phase'] === 'converted', $r2['phase'] ?? null, 'converted');
  check('convert: count 1', $r2['count'] === 1, $r2['count'] ?? null, 1);
  check('convert: title', $r2['title'] === 'gus converted Chocolate 5gal of Chocolate to Grab-and-go on Mon 08/31', $r2['title'] ?? null);
  check('convert: tubs origin only', $r2['tubs'] === [101], $r2['tubs'] ?? null, '[101]');
  check('convert: details are what was written', $r2['detail_rows'][101] === ['use' => 10, 'state' => 'Emptied'], $r2['detail_rows'][101] ?? null);

  // ---- 3. plain tub create (no origin) --------------------------------
  // 202 has a flavor fixture: title carries the "of <flavor>" clause.
  $r3 = scoop_inventory_change_tub_create_audit(['use' => 9, 'amount' => 1.0], 202, $USER, $DATE);
  check('plain: returns slice', is_array($r3), $r3, 'array');
  check('plain: phase created', $r3['phase'] === 'created', $r3['phase'] ?? null);
  check('plain: title', $r3['title'] === 'gus created 1 tub of Chocolate on Mon 08/31', $r3['title'] ?? null);
  check('plain: tubs [202]', $r3['tubs'] === [202], $r3['tubs'] ?? null);

  // Unknown created tub (no flavor resolvable): "of" clause degrades to
  // nothing instead of junk.
  $r4 = scoop_inventory_change_tub_create_audit(['use' => 9, 'amount' => 1.0], 203, $USER, $DATE);
  check('plain-noflavor: title degrades cleanly', $r4['title'] === 'gus created 1 tub on Mon 08/31', $r4['title'] ?? null);
  check('plain-noflavor: flavors empty', $r4['flavors'] === [], $r4['flavors'] ?? null);

  // ---- 4. failed create ----------------------------------------------
  check('failed: null', scoop_inventory_change_tub_create_audit([], 0, $USER, $DATE) === null);

  // ---- 5. detail rendering (same loop as scoop_log_post) -------------
  // The convert record's details must not be empty after the real loop.
  $details = '';
  foreach ($r2['detail_rows'] as $row_id => $fields) {
    if (!is_array($fields)) continue;
    $details .= '<strong>' . (get_the_title((int)$row_id) ?: "Item {$row_id}") . '</strong><br />';
    foreach ($fields as $field => $value) {
      $details .= $field . ' => ' . (get_the_title((int)$value) ?: $value) . '<br />';
    }
  }
  check('convert: details non-empty', strpos($details, '<strong>Chocolate 5gal</strong>') !== false, $details);
  check('convert: details has state', strpos($details, 'state => Emptied') !== false, $details);

  echo $fails === 0 ? "ALL PASS\n" : "{$fails} FAILURES\n";
  exit($fails === 0 ? 0 : 1);
}
