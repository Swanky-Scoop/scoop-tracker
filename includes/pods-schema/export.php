<?php
if (!defined('ABSPATH')) exit;

/**
 * Read-only export of this environment's live Pods config, shaped to match
 * scoop_schema_definition()'s format — for bootstrapping or refreshing the
 * hand-authored schema file. Never writes to disk (see ui.php for why: the
 * same code runs on TEST/OPS where a wp-admin action writing plugin source
 * files would be a standing backdoor-shaped capability). Always renders as
 * text for manual copy/paste into includes/pods-schema/_schema.php.
 */

/** Keys that are internal/per-environment and never worth asserting. */
function scoop_schema_volatile_keys(): array {
  return ['id', 'sister_id', 'weight', 'created', 'modified'];
}

function scoop_schema_strip_volatile(array $arr): array {
  $volatile = array_flip(scoop_schema_volatile_keys());
  $out = [];
  foreach ($arr as $key => $value) {
    if (isset($volatile[$key])) continue;
    $out[$key] = is_array($value) ? scoop_schema_strip_volatile($value) : $value;
  }
  return $out;
}

/** Every live pod, shaped like scoop_schema_definition(), volatile keys stripped. */
function scoop_schema_export_live(): array {
  $out = [];
  foreach (scoop_schema_live_pod_names() as $pod_name) {
    $pod = scoop_schema_load_live_pod($pod_name);
    if ($pod === null) continue;

    $fields = [];
    foreach ($pod['fields'] ?? [] as $field_name => $field_def) {
      $fields[$field_name] = scoop_schema_strip_volatile($field_def);
    }

    $pod_attrs = $pod;
    unset($pod_attrs['fields']);
    $pod_attrs = scoop_schema_strip_volatile($pod_attrs);
    $pod_attrs['fields'] = $fields;

    $out[$pod_name] = $pod_attrs;
  }
  return $out;
}

/**
 * Full, valid replacement for includes/pods-schema/_schema.php — including
 * the function wrapper, not just the array — so both the download and the
 * direct-save path always produce a file that still defines
 * scoop_schema_definition(). (A bare `return [...]` with no function here
 * previously nuked that function on save and took the site down with a
 * fatal error on the very next request.)
 */
function scoop_schema_export_live_php_source(): string {
  $data = scoop_schema_export_live();
  $body = var_export($data, true);
  return "<?php\n"
    . "if (!defined('ABSPATH')) exit;\n\n"
    . "/**\n"
    . " * Exported from a live environment via Schema Sync's Export tool. Trim this\n"
    . " * down to only the pods/fields/attrs you actually want enforced before\n"
    . " * relying on it as source of truth.\n"
    . " */\n"
    . "function scoop_schema_definition(): array {\n"
    . "  return {$body};\n"
    . "}\n";
}

/**
 * Writes the export straight to includes/pods-schema/_schema.php. Path is
 * hardcoded (never user input). Not environment-gated: this file is inert
 * data read only by the Schema Sync tool itself (nothing else in the plugin
 * calls scoop_schema_definition()) — writing it on TEST/OPS just edits that
 * server's working copy, it can't reach source control or another
 * environment until someone deliberately commits and deploys it.
 */
function scoop_schema_export_save_to_file(): array {
  $path = SCOOP_REST_DIR . 'includes/pods-schema/_schema.php';
  $source = scoop_schema_export_live_php_source();

  $bytes = @file_put_contents($path, $source);
  if ($bytes === false) {
    return ['ok' => false, 'message' => "Failed to write {$path}."];
  }

  return ['ok' => true, 'message' => "Wrote {$bytes} bytes to {$path}."];
}
