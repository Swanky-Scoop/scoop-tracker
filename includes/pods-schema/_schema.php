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
        'parent' => 14420,
        'group' => 'flavors_changes',
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
        'parent' => 14420,
        'group' => 'supplies_low',
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
        'parent' => 14420,
        'group' => 'end_of_day',
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
        'parent' => 14420,
        'group' => 'who_are_you',
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
        'parent' => 14420,
        'group' => 'more_fields',
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
        'parent' => 14420,
        'group' => 'supplies_low',
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
        'parent' => 14420,
        'group' => 'who_are_you',
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
        'parent' => 14420,
        'group' => 'more_fields',
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
        'parent' => 14420,
        'group' => 'flavors_changes',
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
        'parent' => 14420,
        'group' => 'more_fields',
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
        'parent' => 14420,
        'group' => 'supplies_low',
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
        'parent' => 14420,
        'group' => 'who_are_you',
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
        'parent' => 14420,
        'group' => 'flavors_changes',
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
        'parent' => 14420,
        'group' => 'more_fields',
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
        'parent' => 14425,
        'group' => 'more_fields',
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
        'parent' => 14425,
        'group' => 'more_fields',
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
        'parent' => 14432,
        'group' => 'more_fields',
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
        'parent' => 14432,
        'group' => 'more_fields',
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
        'parent' => 14432,
        'group' => 'more_fields',
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
        'parent' => 14432,
        'group' => 'more_fields',
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
);
}
