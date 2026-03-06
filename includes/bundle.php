<?php
// includes/bundle.php

function scoop_parse_types_param($raw): array {
  if (is_array($raw)) return array_values(array_filter(array_map('trim', $raw)));
  $raw = (string)$raw;
  if ($raw === '') return [];
  return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function scoop_bundle_get(\WP_REST_Request $req) {
  $types = scoop_parse_types_param($req->get_param('types'));

  $specs = scoop_bundle_specs();

  if (!$types) {
    return new \WP_REST_Response([
      'ok' => false,
      'error' => 'Missing types param. Example: ?types=Cabinet,FlavorTub',
      'known' => array_keys($specs),
    ], 400);
  }

  $unknown = [];
  $needs = [];

  foreach ($types as $t) {
    if (!isset($specs[$t])) { $unknown[] = $t; continue; }
    foreach (($specs[$t]['needs'] ?? []) as $needType) {
      $needs[$needType] = true;
    }
  }

  if ($unknown) {
    return new \WP_REST_Response([
      'ok' => false,
      'error' => 'Unknown grid type(s)',
      'unknown' => $unknown,
      'known' => array_keys($specs),
      'types' => $types,
    ], 400);
  }

  $needTypes = array_keys($needs);

  $data = [];
  foreach ($needTypes as $needType) {
      $data[$needType] = scoop_bundle_fetch_type($needType, $req, ['requesting_types' => $types]);
  }

  return new \WP_REST_Response([
    'ok' => true,
    'types' => $types,
    'needs' => $needTypes,
    'data' => $data,
  ], 200);
}