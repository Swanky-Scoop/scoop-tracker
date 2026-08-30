<?php
// Proof harness: run the REAL scoop_parse_debt_requests() against the EXACT
// payload shape the browser's List autosave produces for a Debt Wanted edit
// (TextIt input name Debt[cells][<rowId>][demand], built from the cell's
// colKey 'demand' — see assets/ui/text-it.js + _list.js _buildDirtyPayload).
// Nothing client-side renames the field: ScoopAPI.postJson only wraps the
// envelope ({ Debt: {cells…} }).
define('ABSPATH', '/tmp/fake/');
require '/home/user/repo/includes/rest.php';

function try_body($label, $payload) {
  [$ops, $errors] = scoop_parse_debt_requests($payload);
  printf("%-28s ops=%d errors=%s\n", $label, count($ops), $errors ? json_encode($errors) : '-');
}

// What the real UI posts (colKey 'demand'):
try_body('UI shape: [demand]=3', ['cells' => ['101000600' => ['demand' => 3]]]);
// What the parser was written against (colKey 'wanted'):
try_body('spec shape: [wanted]=3', ['cells' => ['101000600' => ['wanted' => 3]]]);
