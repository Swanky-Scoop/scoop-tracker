<?php
/**
 * Generate the full pod surface from the plugin's own scoop_entity_specs().
 * Run via: php wp-cli.phar eval-file gen-pods.php --allow-root
 * Idempotent: skips existing pods, tops up missing fields.
 */
function scoop_gen_field($api, $pod, $fname, $fdef, &$errors) {
  if (is_string($fdef)) $fdef = ['data_type' => $fdef];
  if (!is_array($fdef)) return;
  $dtype = $fdef['data_type'] ?? 'string';
  $titleMap = $fdef['titleMap'] ?? null;
  $map = [
    'string'   => ['type'=>'text'],
    'float'    => ['type'=>'number','number_format'=>'i18n','number_decimals'=>'2'],
    'int'      => ['type'=>'number','number_format'=>'9999.99','number_decimals'=>'0'],
    'bool'     => ['type'=>'boolean'],
    'datetime' => ['type'=>'datetime'],
    'file'     => ['type'=>'file'],
    'ids'      => ['type'=>'pick','pick_object'=>'post_type','pick_val'=>$titleMap,'pick_format_type'=>'multi','pick_format_multi'=>'autocomplete'],
    'post_names' => ['type'=>'pick','pick_object'=>'post_type','pick_val'=>'allergen','pick_format_type'=>'multi','pick_format_multi'=>'autocomplete'],
  ];
  $p = $map[$dtype] ?? ['type'=>'text'];
  if ($dtype === 'int' && $titleMap) {
    $p = ['type'=>'pick','pick_object'=>'post_type','pick_val'=>$titleMap,'pick_format_type'=>'single','pick_format_single'=>'dropdown'];
  }
  try {
    $fid = $api->save_field(array_merge([
      'pod'=>$pod, 'name'=>$fname, 'label'=>ucwords(str_replace('_',' ',$fname)), 'required'=>0,
    ], $p));
    if (is_wp_error($fid)) $errors[] = "field $pod.$fname: ".$fid->get_error_message();
  } catch (\Throwable $e) { $errors[] = "field $pod.$fname: ".$e->getMessage(); }
}

$specs = scoop_entity_specs();
$api = pods_api();
$created = []; $skipped = []; $errors = [];

foreach ($specs as $key => $spec) {
  $pod = $spec['pod'];
  if ($api->load_pod(['name' => $pod])) {
    $skipped[] = $pod;
    $live = $api->load_pod(['name' => $pod]);
    $liveFields = is_array($live['fields'] ?? null) ? array_keys($live['fields']) : [];
    foreach (($spec['fields'] ?? []) as $fname => $fdef) {
      if (in_array($fname, $liveFields, true)) continue;
      scoop_gen_field($api, $pod, $fname, $fdef, $errors);
    }
    continue;
  }

  $params = [
    'name'   => $pod,
    'label'  => ucfirst($pod),
    'type'   => 'post_type',
    'storage' => 'table',
    'object_type' => 'post_type',
    'public' => 0,
    'hierarchical' => 0,
    'supports_title' => 1,
    'supports_editor' => 0,
    'rewrite' => 0,
    'queryable' => 0,
    'show_ui' => 1,
    'menu_position' => 60,
  ];
  try {
    $id = $api->save_pod($params);
  } catch (\Throwable $e) { $errors[] = "pod $pod: ".$e->getMessage(); continue; }
  if (is_wp_error($id)) { $errors[] = "pod $pod: ".$id->get_error_message(); continue; }
  $created[] = $pod;

  foreach (($spec['fields'] ?? []) as $fname => $fdef) {
    scoop_gen_field($api, $pod, $fname, $fdef, $errors);
  }
}
echo "CREATED: ".implode(',',$created)."\nSKIPPED: ".implode(',',$skipped)."\nERRORS: ".count($errors)."\n";
foreach (array_slice($errors,0,20) as $e) echo "  ! $e\n";
