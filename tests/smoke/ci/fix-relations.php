<?php
$api = pods_api();
$fix = [
  'slot' => ['cabinet'=>'cabinet','location'=>'location'],
  'cabinet' => ['location'=>'location'],
  'tub' => ['use'=>'use','flavor'=>'flavor','location'=>'location','batch'=>'batch','closeout'=>'closeout','slot'=>'slot','moving_to'=>'location','flavor_request'=>'flavor_request'],
  'batch' => ['flavor'=>'flavor','task'=>'task'],
  'flavor_request' => ['location'=>'location','flavor'=>'flavor'],
  'closeout' => ['flavor'=>'flavor','use'=>'use','location'=>'location'],
  'prep' => ['ingredient'=>'ingredient','units'=>'unit','task'=>'task'],
  'recipe_count' => ['recipe'=>'recipe','task'=>'task'],
  'task' => ['target'=>'user'],
];
$multi = [
  'flavor' => ['tubs'=>'tub','current_slots'=>'slot','allergens'=>'allergen'],
  'cabinet' => ['prohibited_allergens'=>'allergen'],
  'inventory_change' => ['tubs'=>'tub','flavors'=>'flavor'],
];
foreach ($fix as $pod => $fields) {
  $p = $api->load_pod(['name'=>$pod]);
  if (!$p) continue;
  foreach ($fields as $f => $val) {
    $def = $p['fields'][$f] ?? null;
    if (!$def) continue;
    $api->save_field(['pod'=>$pod,'id'=>$def['id'],'name'=>$f,'label'=>$def['label'],'type'=>'pick','pick_object'=>'post_type','pick_val'=>$val,'pick_format_type'=>'single','pick_format_single'=>'dropdown','required'=>0]);
  }
}
foreach ($multi as $pod => $fields) {
  $p = $api->load_pod(['name'=>$pod]);
  if (!$p) continue;
  foreach ($fields as $f => $val) {
    $def = $p['fields'][$f] ?? null;
    if (!$def) continue;
    $api->save_field(['pod'=>$pod,'id'=>$def['id'],'name'=>$f,'label'=>$def['label'],'type'=>'pick','pick_object'=>'post_type','pick_val'=>$val,'pick_format_type'=>'multi','pick_format_multi'=>'autocomplete','required'=>0]);
  }
}
// mirror-parity reverse fields the direct writer expects
$b = $api->load_pod(['name'=>'batch']);
if ($b && !isset($b['fields']['tubs'])) $api->save_field(['pod'=>'batch','name'=>'tubs','label'=>'Tubs','type'=>'pick','pick_object'=>'post_type','pick_val'=>'tub','pick_format_type'=>'multi','pick_format_multi'=>'autocomplete','required'=>0]);
$l = $api->load_pod(['name'=>'location']);
if ($l && !isset($l['fields']['tubs'])) $api->save_field(['pod'=>'location','name'=>'tubs','label'=>'Tubs','type'=>'pick','pick_object'=>'post_type','pick_val'=>'tub','pick_format_type'=>'multi','pick_format_multi'=>'autocomplete','required'=>0]);

// Bidirectional sister pair: tub.slot <-> slot.tub (Pods-native 1:1 sync;
// see _specs.php's comments and change-tub.md). Pods needs the two pick
// fields cross-referenced so writing one side updates the other's podsrel.
$tubPod = $api->load_pod(['name'=>'tub']);
$slotPod = $api->load_pod(['name'=>'slot']);
$tubSlot = $tubPod['fields']['slot'] ?? null;
$slotTub = $slotPod['fields']['tub'] ?? null;
if ($tubSlot && $slotTub) {
  $api->save_field(['pod'=>'tub','id'=>$tubSlot['id'],'name'=>'slot','label'=>$tubSlot['label'],'type'=>'pick','pick_object'=>'post_type','pick_val'=>'slot','pick_format_type'=>'single','pick_format_single'=>'dropdown','pick_bidirectional'=>'1','sister_id'=>$slotTub['id'],'required'=>0]);
  $api->save_field(['pod'=>'slot','id'=>$slotTub['id'],'name'=>'tub','label'=>$slotTub['label'],'type'=>'pick','pick_object'=>'post_type','pick_val'=>'tub','pick_format_type'=>'single','pick_format_single'=>'dropdown','pick_bidirectional'=>'1','sister_id'=>$tubSlot['id'],'required'=>0]);
  echo "bidirectional sisters linked\n";
}
echo "relations fixed\n";
