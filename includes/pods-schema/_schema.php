<?php
if (!defined('ABSPATH')) exit;

/**
 * Exported from a live environment via Schema Sync's Export tool. Trim this
 * down to only the pods/fields/attrs you actually want enforced before
 * relying on it as source of truth.
 *
 * Currently tracks: shift_report + its two new related pods (supply,
 * cake_order), added together on local 2026-08-11. shift_report has pick
 * fields relating to both, so all three need to land on an environment
 * together or the relationship fields point at pods that don't exist yet.
 *
 *
 * Field 'group' values are group SLUGS (e.g. 'who_are_you'), not the
 * numeric local group id scoop_schema_export_live() actually exports -
 * hand-corrected 2026-08-16 after a real OPS Apply run failed 21/21 field
 * creates with "Group (Slug: X) not found": Pods' save_field() takes
 * 'group' literally (unlike 'parent', which it ignores in favor of the
 * explicit 'pod' name param) and doesn't resolve a stale numeric id
 * against a different environment - only a real slug travels correctly.
 * Pod-level 'groups' arrays are removed entirely, not just corrected: once
 * a pod's groups already exist somewhere (as they now do, from the first
 * Apply run's pod-creation step), re-sending that array on a later Apply
 * has no 'id' to match against (stripped as volatile) and could create
 * duplicate groups rather than recognizing the existing ones. Not needed
 * for fields to resolve their group by slug either way, so simplest safe
 * fix is just not shipping it once a pod is past first creation.
 *
 * shift_report/supply/cake_order's promotion to every environment is still
 * in progress (see WHITEBOARD-INGESTION.md) - they exist on local and OPS
 * but not everywhere, so Apply against a brand-new environment (no prior
 * shift_report at all) will hit "Group (Slug: X) not found" on every field,
 * since there are no groups yet for those slugs to resolve against. Not a
 * bug to fix reflexively - confirm the environment actually needs this
 * feature promoted before creating the groups (by hand or by restoring the
 * pod-level 'groups' array above) rather than working around the error.
 *
 * 'group' slug resolution is not just "use a slug, not a numeric id" - the
 * slug itself isn't unique across the install either. Confirmed on local
 * 2026-08-29 applying tub.moving_to (group 'more_fields'): 25+ different
 * pods each have their own "More Fields" group, ALL sharing the literal
 * slug 'more_fields' (Pods' own default name for a pod's catch-all group),
 * so save_field()'s slug lookup is ambiguous whenever a field targets one
 * of these generic default-named groups - it can land on some OTHER pod's
 * same-slugged group transiently. Pods' own admin Repair tool (Tools ->
 * Pods Admin, or wherever it surfaces on a given WP version - "Reassigned
 * fields with invalid groups") caught and reassigned it correctly here,
 * but that's Pods self-healing after the fact, not this tool getting it
 * right the first time - there's no guarantee a future environment's Apply
 * self-heals the same way. After Applying any field whose group isn't a
 * distinctively-named one (shift_report's who_are_you/flavors_changes/
 * supplies_low/end_of_day are fine - those slugs are unique), check the
 * field actually landed on the right pod's group, or just run Pods'
 * Repair, before trusting the Apply succeeded silently.
 */
function scoop_schema_definition(): array {
  return array (
  'shift_report' => 
  array (
    'object_type' => 'pod',
    'object_storage_type' => 'post_type',
    'name' => 'shift_report',
    'label' => 'Shift Reports',
    'description' => '',
    'type' => 'post_type',
    'storage' => 'table',
    'label_singular' => 'Shift Report',
    'public' => '1',
    'show_ui' => '1',
    'publicly_queryable' => '0',
    'dynamic_features_allow' => 'inherit',
    'rest_enable' => '1',
    'supports_title' => '1',
    'supports_editor' => '1',
    '_migrated_28' => '1',
    'fields' => 
    array (
      'cake_orders' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'cake_orders',
        'group' => ['name' => 'flavors_changes', 'pod' => 'shift_report'],
        'label' => 'Any cake orders?',
        'description' => '',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_format_type' => 'multi',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '1',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'pick_custom' => '?',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'pick_val' => 'cake_order',
        'pick_add_new_label' => 'Enter Order',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'supplies_low' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'supplies_low',
        'group' => ['name' => 'supplies_low', 'pod' => 'shift_report'],
        'label' => 'Are we running out of stuff?',
        'description' => '',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_format_type' => 'multi',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'checkbox',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '1',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'pick_custom' => '?',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'pick_val' => 'supply',
        'pick_add_new_label' => 'Other',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'final_tasks' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'final_tasks',
        'group' => ['name' => 'end_of_day', 'pod' => 'shift_report'],
        'label' => 'Final tasks',
        'description' => '',
        'type' => 'pick',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'pick_object' => 'custom-simple',
        'pick_format_type' => 'multi',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'checkbox',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '1',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'pick_custom' => 'Confirmed tempering cabinet contents match whiteboards
Cashbox in Safe
Oven off
Dishwasher off
Dipping cabinets on (except Sundays)
Conservewells turned off',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'shift_lead' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'shift_lead',
        'group' => ['name' => 'who_are_you', 'pod' => 'shift_report'],
        'label' => 'Shift lead',
        'description' => 'Who are you?',
        'type' => 'text',
        'text_allowed_html_tags' => 'strong em a ul ol li b i',
        'text_max_length' => '255',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'staffing_level' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'staffing_level',
        'group' => ['name' => 'more_fields', 'pod' => 'shift_report'],
        'label' => 'Staffing level',
        'description' => '',
        'type' => 'pick',
        'pick_object' => 'custom-simple',
        'pick_format_type' => 'single',
        'pick_format_single' => 'radio',
        'pick_format_multi' => 'list',
        'pick_display_format_multi' => 'default',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '1',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'pick_custom' => 'Correctly staffed.
Busy but the team handled it no problem.
Very busy, could have used more help for sure.
Too many scoopers, not enough customers or tasks.
None of these fit.',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'change_low' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'change_low',
        'group' => ['name' => 'supplies_low', 'pod' => 'shift_report'],
        'label' => 'Are we running out of money?',
        'description' => '',
        'type' => 'pick',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'pick_object' => 'custom-simple',
        'pick_format_type' => 'multi',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'checkbox',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '1',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'pick_custom' => 'Pennies
Nickles
Dimes
Quarters
Dollars
Fives
Tens
Twenties',
        'pick_select_text' => 'None',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'location' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'location',
        'group' => ['name' => 'who_are_you', 'pod' => 'shift_report'],
        'label' => 'Location',
        'description' => '',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_val' => 'location',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'list',
        'pick_display_format_multi' => 'default',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'positive_feedback' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'positive_feedback',
        'group' => ['name' => 'more_fields', 'pod' => 'shift_report'],
        'label' => 'Positive Feedback',
        'description' => 'What nice things did you say?',
        'type' => 'paragraph',
        'paragraph_allowed_html_tags' => 'strong em a ul ol li b i',
        'paragraph_max_length' => '-1',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'flavors_changed' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'flavors_changed',
        'group' => ['name' => 'flavors_changes', 'pod' => 'shift_report'],
        'label' => 'What flavors changed?',
        'description' => '',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_val' => 'flavor',
        'pick_format_type' => 'multi',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'customer_issues' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'customer_issues',
        'group' => ['name' => 'more_fields', 'pod' => 'shift_report'],
        'label' => 'Customer Issues',
        'description' => '',
        'type' => 'paragraph',
        'paragraph_allowed_html_tags' => 'strong em a ul ol li b i',
        'paragraph_max_length' => '-1',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'cash_discrepancy' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'cash_discrepancy',
        'group' => ['name' => 'supplies_low', 'pod' => 'shift_report'],
        'label' => 'Is the money messed up (if so, say by how much, negative if under)?',
        'description' => '',
        'type' => 'currency',
        'currency_format_type' => 'number',
        'currency_format_sign' => 'usd',
        'currency_format_placement' => 'before',
        'currency_format' => 'i18n',
        'currency_decimals' => '2',
        'currency_decimal_handling' => 'none',
        'currency_step' => '1',
        'currency_min' => '0',
        'currency_max' => '1000',
        'currency_max_length' => '12',
        'currency_html5' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'shift' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'shift',
        'group' => ['name' => 'who_are_you', 'pod' => 'shift_report'],
        'label' => 'Shift time',
        'description' => '',
        'type' => 'boolean',
        'boolean_format_type' => 'radio',
        'boolean_yes_label' => 'Early',
        'boolean_no_label' => 'Late',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'tempering_cabinet_photo' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'tempering_cabinet_photo',
        'group' => ['name' => 'flavors_changes', 'pod' => 'shift_report'],
        'label' => 'Tempering Cabinet Photo',
        'description' => '',
        'type' => 'file',
        'file_format_type' => 'single',
        'file_uploader' => 'attachment',
        'file_type' => 'images',
        'file_attachment_tab' => 'upload',
        'file_attachment_current_post_only' => '0',
        'file_upload_dir' => 'wp',
        'file_edit_title' => '1',
        'file_show_edit_link' => '0',
        'file_linked' => '0',
        'file_limit' => '0',
        'file_field_template' => 'rows',
        'file_add_button' => 'Add File',
        'file_modal_title' => 'Attach a file',
        'file_modal_add_button' => 'Add File',
        'file_wp_gallery_link' => 'file',
        'file_wp_gallery_columns' => '3',
        'file_wp_gallery_size' => 'thumbnail',
        'file_auto_set_featured_image' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '1',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'notes_for_tomorrow' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'notes_for_tomorrow',
        'group' => ['name' => 'more_fields', 'pod' => 'shift_report'],
        'label' => 'Notes for Tomorrow',
        'description' => '',
        'type' => 'paragraph',
        'paragraph_allowed_html_tags' => 'strong em a ul ol li b i',
        'paragraph_max_length' => '-1',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
    ),
  ),
  'supply' => 
  array (
    'object_type' => 'pod',
    'object_storage_type' => 'post_type',
    'name' => 'supply',
    'label' => 'Supplies',
    'description' => '',
    'type' => 'post_type',
    'storage' => 'table',
    'label_singular' => 'Supply',
    'public' => '1',
    'show_ui' => '1',
    'publicly_queryable' => '0',
    'dynamic_features_allow' => 'inherit',
    'rest_enable' => '1',
    'supports_title' => '1',
    'supports_editor' => '1',
    '_migrated_28' => '1',
    'fields' => 
    array (
      'group' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'group',
        'group' => ['name' => 'more_fields', 'pod' => 'supply'],
        'label' => 'Group',
        'description' => '',
        'type' => 'pick',
        'pick_object' => 'custom-simple',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'list',
        'pick_display_format_multi' => 'default',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '1',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'pick_custom' => 'Cups
Lids
Gloves
Paper goods
Bags & to-go
Cleaning & bathroom
Spoons & straws
Dairy/milk
Cones
Cookies/frozen dough
Toppings & sauces
Beverages
Misc/facilities',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'last_purchase' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'last_purchase',
        'group' => ['name' => 'more_fields', 'pod' => 'supply'],
        'label' => 'Last Purchase',
        'description' => '',
        'type' => 'date',
        'date_type' => 'format',
        'date_format' => 'mdy',
        'date_allow_empty' => '1',
        'date_html5' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
    ),
  ),
  // Ingredient-tracking counter-pod system (task / prep / recipe_count).
  // Hand-authored, NOT exported from a live environment: the code that uses
  // these pods (Task/TaskEdit/Prep/RecipeCount routes in _config.php, the
  // task/prep/recipe_count entity specs in _specs.php, hooks/task-titles.php,
  // hooks/task-state.php, the Tasks grid + Task form) landed on main directly
  // between 2026-08-16 and 2026-08-20 (34ad070, f19dcf7, 2dbc95e, 3354549) and
  // has been running against pods created by hand on the local dev site. Only
  // the declarations were missing — so Schema Sync reported nothing missing on
  // local (pods exist) while TEST/OPS had no way to grow them. Field slugs,
  // types, and relations below mirror scoop_tasks_allowed_fields() /
  // scoop_preps_allowed_fields() / scoop_recipe_counts_allowed_fields()
  // (_write_fields.php) and the entity specs — they are the write contract.
  // Pick attrs follow the hand-authored tub.moving_to field; pod attrs follow
  // shift_report. 'group' => 'more_fields' reuses the same live group the
  // other pods use (resolved by slug, see the shift_report note above).
  // See INGREDIENT-TRACKING.md for the full state of this workstream.
  'task' =>
  array (
    'object_type' => 'pod',
    'object_storage_type' => 'post_type',
    'name' => 'task',
    'label' => 'Tasks',
    'description' => 'Production task that batch, prep, and recipe_count rows attach to (ingredient-tracking counter-pod system; see INGREDIENT-TRACKING.md).',
    'type' => 'post_type',
    'storage' => 'table',
    'label_singular' => 'Task',
    'public' => '1',
    'show_ui' => '1',
    'publicly_queryable' => '0',
    'dynamic_features_allow' => 'inherit',
    'rest_enable' => '1',
    'supports_title' => '1',
    'supports_editor' => '1',
    'fields' =>
    array (
      'other' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'other',
        'group' => ['name' => 'more_fields', 'pod' => 'task'],
        'label' => 'Other',
        'description' => 'The task description. Feeds the auto-generated task title (first 8 words) and shows in the Tasks grid and Details panel.',
        'type' => 'textarea',
        'textarea_rows' => '4',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'target' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'target',
        'group' => ['name' => 'more_fields', 'pod' => 'task'],
        'label' => 'Assigned to',
        'description' => 'Staff member responsible (WP User relation). Shown as the Tasks grid Assigned column via target_name.',
        'type' => 'pick',
        'pick_object' => 'user',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'done' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'done',
        'group' => ['name' => 'more_fields', 'pod' => 'task'],
        'label' => 'Done',
        'description' => 'Checked when the task is complete. Drives the system-stamped completed timestamp (see includes/hooks/task-state.php).',
        'type' => 'boolean',
        'boolean_format_type' => 'checkbox',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'completed' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'completed',
        'group' => ['name' => 'more_fields', 'pod' => 'task'],
        'label' => 'Completed',
        'description' => 'System-stamped by includes/hooks/task-state.php when done flips true; cleared if done reverts. Never client-writable.',
        'type' => 'datetime',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
    ),
  ),
  'prep' =>
  array (
    'object_type' => 'pod',
    'object_storage_type' => 'post_type',
    'name' => 'prep',
    'label' => 'Preps',
    'description' => 'Ingredient prep line attached to a task (ingredient-tracking counter-pod system; see INGREDIENT-TRACKING.md).',
    'type' => 'post_type',
    'storage' => 'table',
    'label_singular' => 'Prep',
    'public' => '1',
    'show_ui' => '1',
    'publicly_queryable' => '0',
    'dynamic_features_allow' => 'inherit',
    'rest_enable' => '1',
    'supports_title' => '1',
    'supports_editor' => '1',
    'fields' =>
    array (
      'count' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'count',
        'group' => ['name' => 'more_fields', 'pod' => 'prep'],
        'label' => 'Count',
        'description' => 'How much of the ingredient to prep.',
        'type' => 'number',
        'number_format' => '9999.99',
        'number_decimals' => '2',
        'number_max_length' => '12',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'ingredient' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'ingredient',
        'group' => ['name' => 'more_fields', 'pod' => 'prep'],
        'label' => 'Ingredient',
        'description' => 'The ingredient being prepped (ingredient pod relation).',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_val' => 'ingredient',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'units' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'units',
        'group' => ['name' => 'more_fields', 'pod' => 'prep'],
        'label' => 'Units',
        'description' => 'Unit of measure for the count (unit pod relation).',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_val' => 'unit',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'other' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'other',
        'group' => ['name' => 'more_fields', 'pod' => 'prep'],
        'label' => 'Other',
        'description' => 'Free-text fallback when the prep line is not tied to a catalog ingredient.',
        'type' => 'text',
        'text_allowed_html_tags' => 'strong em a ul ol li b i',
        'text_max_length' => '255',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'task' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'task',
        'group' => ['name' => 'more_fields', 'pod' => 'prep'],
        'label' => 'Task',
        'description' => 'The production task this prep line belongs to (set by the Task form create-line).',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_val' => 'task',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'done' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'done',
        'group' => ['name' => 'more_fields', 'pod' => 'prep'],
        'label' => 'Done',
        'description' => 'Checked when this prep line is complete.',
        'type' => 'boolean',
        'boolean_format_type' => 'checkbox',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
    ),
  ),
  'recipe_count' =>
  array (
    'object_type' => 'pod',
    'object_storage_type' => 'post_type',
    'name' => 'recipe_count',
    'label' => 'Recipe Counts',
    'description' => 'Recipe production count line attached to a task (ingredient-tracking counter-pod system; see INGREDIENT-TRACKING.md).',
    'type' => 'post_type',
    'storage' => 'table',
    'label_singular' => 'Recipe Count',
    'public' => '1',
    'show_ui' => '1',
    'publicly_queryable' => '0',
    'dynamic_features_allow' => 'inherit',
    'rest_enable' => '1',
    'supports_title' => '1',
    'supports_editor' => '1',
    'fields' =>
    array (
      'count' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'count',
        'group' => ['name' => 'more_fields', 'pod' => 'recipe_count'],
        'label' => 'Count',
        'description' => 'How many of the recipe to produce.',
        'type' => 'number',
        'number_format' => '9999.99',
        'number_decimals' => '2',
        'number_max_length' => '12',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'recipe' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'recipe',
        'group' => ['name' => 'more_fields', 'pod' => 'recipe_count'],
        'label' => 'Recipe',
        'description' => 'The recipe being counted (recipe pod relation).',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_val' => 'recipe',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'task' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'task',
        'group' => ['name' => 'more_fields', 'pod' => 'recipe_count'],
        'label' => 'Task',
        'description' => 'The production task this recipe count line belongs to (set by the Task form create-line).',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_val' => 'task',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
      'done' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'done',
        'group' => ['name' => 'more_fields', 'pod' => 'recipe_count'],
        'label' => 'Done',
        'description' => 'Checked when this recipe count line is complete.',
        'type' => 'boolean',
        'boolean_format_type' => 'checkbox',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
    ),
  ),
  'cake_order' => 
  array (
    'object_type' => 'pod',
    'object_storage_type' => 'post_type',
    'name' => 'cake_order',
    'label' => 'Cake Orders',
    'description' => '',
    'type' => 'post_type',
    'storage' => 'table',
    'label_singular' => 'Cake Order',
    'public' => '1',
    'show_ui' => '1',
    'publicly_queryable' => '0',
    'dynamic_features_allow' => 'inherit',
    'rest_enable' => '1',
    'supports_title' => '1',
    'supports_editor' => '1',
    '_migrated_28' => '1',
    'fields' => 
    array (
      'order_name' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'order_name',
        'group' => ['name' => 'more_fields', 'pod' => 'cake_order'],
        'label' => 'Order name',
        'description' => 'Usually the person\'s name who is ordering',
        'type' => 'text',
        'text_allowed_html_tags' => 'strong em a ul ol li b i',
        'text_max_length' => '255',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'cake_pie_flavor' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'cake_pie_flavor',
        'group' => ['name' => 'more_fields', 'pod' => 'cake_order'],
        'label' => 'Cake/Pie Flavor',
        'description' => '',
        'type' => 'pick',
        'pick_object' => 'custom-simple',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'list',
        'pick_display_format_multi' => 'default',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '1',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'pick_custom' => 'Singature
Nom Nom Nom Cake
Other',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'pickup_date' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'pickup_date',
        'group' => ['name' => 'more_fields', 'pod' => 'cake_order'],
        'label' => 'Pickup date',
        'description' => '',
        'type' => 'date',
        'date_type' => 'format',
        'date_format' => 'mdy',
        'date_allow_empty' => '1',
        'date_html5' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' => 
        array (
        ),
        'fields' => 
        array (
        ),
      ),
      'details' => 
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'details',
        'group' => ['name' => 'more_fields', 'pod' => 'cake_order'],
        'label' => 'Details',
        'description' => '',
        'type' => 'paragraph',
        'paragraph_allowed_html_tags' => 'strong em a ul ol li b i',
        'paragraph_max_length' => '-1',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
        'groups' =>
        array (
        ),
        'fields' =>
        array (
        ),
      ),
    ),
  ),
  // Tub-moving feature (worktree-tub-moving) — hand-authored single new
  // field on the EXISTING 'tub' pod, not exported from a live environment
  // (same pattern as the removed tub.alt_uses field, see git history —
  // f0502f7 "Remove unused alt_uses field"). Only 'fields' is listed, no
  // pod-level attrs: 'tub' already exists on every environment, so Apply's
  // missing_fields path (not missing_pods) handles this — nothing at the
  // pod level needs asserting. 'group' => 'more_fields' reuses the same
  // live group alt_uses used to (confirmed present on every environment
  // that's ever had a hand-authored tub field), resolved by slug per the
  // 2026-08-16 group-slug fix documented on shift_report above.
  'flavor_request' =>
  array (
    'object_type' => 'pod',
    'object_storage_type' => 'post_type',
    'name' => 'flavor_request',
    'label' => 'Flavor Requests',
    'description' => 'Explicit per-(location, flavor) demand overrides for the Debt board (worktree-tub-moving). One row per (location, flavor) pair; wanted replaces-not-adds the slot-implied demand (a location can request more tubs of a flavor than its slots currently imply, and the request persists even after the slot plan changes). wanted=0 deletes the row — a missing row means "no override, slots rule". Managed by the Debt grid\'s Wanted column via the /debt-requests route (see includes/rest.php scoop_handle_debt_requests_post()); title is a derived "Location | Flavor" label written by that handler.',
    'type' => 'post_type',
    'storage' => 'table',
    'label_singular' => 'Flavor Request',
    'public' => '1',
    'show_ui' => '1',
    'publicly_queryable' => '0',
    'dynamic_features_allow' => 'inherit',
    'rest_enable' => '1',
    'supports_title' => '1',
    'supports_editor' => '0',
    'fields' =>
    array (
      'location' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'location',
        'group' => ['name' => 'more_fields', 'pod' => 'flavor_request'],
        'label' => 'Destination location',
        'description' => 'The location that wants the tubs. Half of the (location, flavor) upsert key — see scoop_handle_debt_requests_post().',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_val' => 'location',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '1',
        'required_help_boolean' => '0',
        'unique' => '0',
      ),
      'flavor' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'flavor',
        'group' => ['name' => 'more_fields', 'pod' => 'flavor_request'],
        'label' => 'Flavor',
        'description' => 'The flavor being requested. Other half of the (location, flavor) upsert key — see scoop_handle_debt_requests_post().',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_val' => 'flavor',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '1',
        'required_help_boolean' => '0',
        'unique' => '0',
      ),
      'wanted' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'wanted',
        'group' => ['name' => 'more_fields', 'pod' => 'flavor_request'],
        'label' => 'Tubs wanted',
        'description' => 'How many tubs of this flavor this location wants, total — replaces (never adds to) whatever the slot designations imply, per computeDebtRows(). The Debt grid\'s Wanted column writes this; the effective Wanted shown stays max(slot-implied, requested), so slots are the floor and a request can only raise demand, not lower it.',
        'type' => 'number',
        'number_format_type' => 'number',
        'number_decimals' => '0',
        'number_max' => '99',
        'number_min' => '0',
        'number_step' => '1',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '1',
        'required_help_boolean' => '0',
        'unique' => '0',
      ),
    ),
  ),
  'tub' =>
  array (
    'fields' =>
    array (
      'moving_to' =>
      array (
        'object_type' => 'field',
        'object_storage_type' => 'post_type',
        'name' => 'moving_to',
        'group' => ['name' => 'more_fields', 'pod' => 'tub'],
        'label' => 'Moving to',
        'description' => 'Destination location this tub is earmarked to move to. Set automatically when a slot at another location is scheduled (current_flavor/immediate_flavor) for this flavor with no local stock; can also be set by hand to pre-mark a tub. A tub with this set is excluded from CabinetWorkflow\'s promotion pools until it\'s cleared (see includes/hooks/cabinet-slot.php).',
        'type' => 'pick',
        'pick_object' => 'post_type',
        'pick_val' => 'location',
        'pick_format_type' => 'single',
        'pick_format_single' => 'dropdown',
        'pick_format_multi' => 'autocomplete',
        'pick_display_format_multi' => 'custom',
        'pick_display_format_separator' => ', ',
        'pick_allow_add_new' => '0',
        'pick_taggable' => '0',
        'pick_show_icon' => '1',
        'pick_show_edit_link' => '1',
        'pick_show_view_link' => '1',
        'pick_limit' => '0',
        'pick_user_role' => 'Administrator',
        'pick_sync_taxonomy' => '0',
        'pick_sync_taxonomy_hide_taxonomy_ui' => '0',
        'pick_post_status' => 'publish',
        'pick_post_author' => '0',
        'default_evaluate_tags' => '0',
        'default_empty_fields' => '0',
        'roles_allowed' => 'administrator',
        'enable_conditional_logic' => '0',
        'conditional_logic_save_value' => '0',
        'rest_pick_response' => 'array',
        'rest_pick_depth' => '1',
        'required' => '0',
        'required_help_boolean' => '0',
        'unique' => '0',
      ),
    ),
  ),
);
}
