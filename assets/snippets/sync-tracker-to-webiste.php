<?php
/**
 * Sync Flavor Availability from Tracker to Public Site Categories
 * 
 * Pulls data from the tracking site's bundle endpoint and syncs to public site categories
 * 
 * Paste this into WP Code Snippets as a "Run Everywhere" snippet
 */

if (!is_admin() && !defined('DOING_CRON')) {
    return;
}

echo '<div style="background: #f5f5f5; padding: 20px; margin: 20px 0; border-left: 4px solid #0073aa; font-family: monospace; max-width: 900px;">';
echo '<h3>🔄 Flavor Availability Sync from Tracking Site</h3>';

// Category IDs on public site
$categories = array(
    'in-shop-dairy'    => 22,
    'in-shop-vegan'    => 23,
    'next-up-dairy'    => 26,
    'next-up-vegan'    => 27,
    'not-avail-dairy'  => 20,
    'not-avail-vegan'  => 21,
);

// Fetch the bundle from tracking site
echo '<p>Fetching slot, cabinet, and flavor data from tracking site...</p>';

// Note: Adjust the 'types' parameter if needed to match your tracking site's endpoint
// Original: types=Batch%2CCabinet%2CFlavorTub
// You may need to include whatever type corresponds to the 'flavor' data in your bundle
$tracker_url = 'https://ops.swankyscoop.net/wp-json/scoop/v1/bundle?types=Cabinet%2CSlot&_ts=' . time();

$response = wp_remote_get($tracker_url, array(
    'timeout' => 30,
    'headers' => array(
        'Authorization' => 'Basic ' . base64_encode('website:xxxxxxx'),
    ),
));

if (is_wp_error($response)) {
    echo '<strong style="color: red;">✗ ERROR: Could not connect to tracking site</strong><br>';
    echo 'Details: ' . $response->get_error_message() . '<br>';
    echo '</div>';
    return;
}

$body = wp_remote_retrieve_body($response);
$data = json_decode($body, true);

if (!$data || !isset($data['data'])) {
    echo '<strong style="color: red;">✗ ERROR: Invalid response from tracking site</strong><br>';
    echo '</div>';
    return;
}

// Build lookup tables
$web_id_map = array(); // tracking_flavor_id => web_id
$slots_by_id = array();
$cabinets_by_id = array();

// Extract flavor web_id mappings
if (isset($data['data']['flavor'])) {
    foreach ($data['data']['flavor'] as $flavor) {
        if (isset($flavor['id']) && isset($flavor['web_id'])) {
            $web_id_map[$flavor['id']] = $flavor['web_id'];
        }
    }
}

// Extract slots and cabinets
if (isset($data['data']['slot'])) {
    foreach ($data['data']['slot'] as $slot) {
        $slots_by_id[$slot['id']] = $slot;
    }
}

if (isset($data['data']['cabinet'])) {
    foreach ($data['data']['cabinet'] as $cabinet) {
        $cabinets_by_id[$cabinet['id']] = $cabinet;
    }
}

echo '✓ Loaded ' . count($web_id_map) . ' flavor-to-post mappings<br>';
echo '✓ Loaded ' . count($slots_by_id) . ' slots and ' . count($cabinets_by_id) . ' cabinets<br>';
echo '<hr>';

$sync_count = 0;
$error_count = 0;
$not_available = 0;

// Process each flavor
foreach ($web_id_map as $tracking_flavor_id => $public_post_id) {
    
    // Check if the public post exists
    $post = get_post($public_post_id);
    if (!$post) {
        echo "⊘ Tracking flavor #{$tracking_flavor_id}: Public post #{$public_post_id} not found<br>";
        continue;
    }
    
    // Find all slots that contain this flavor
    $current_slots = array();
    $immediate_slots = array();
    
    foreach ($slots_by_id as $slot) {
        if ($slot['current_flavor'] == $tracking_flavor_id) {
            $current_slots[] = $slot;
        }
        if ($slot['immediate_flavor'] == $tracking_flavor_id) {
            $immediate_slots[] = $slot;
        }
    }
    
    // Determine dairy/vegan status from slots
    $has_current_dairy = false;
    $has_current_vegan = false;
    $has_immediate_dairy = false;
    $has_immediate_vegan = false;
    
    foreach ($current_slots as $slot) {
        $cabinet = $cabinets_by_id[$slot['cabinet']] ?? null;
        if (!$cabinet) continue;
        
        // Extract dairy/vegan from cabinet title (format: "Location_type_capacity")
        $parts = explode('_', $cabinet['_title']);
        if (count($parts) >= 2) {
            if (stripos($parts[1], 'vegan') !== false) {
                $has_current_vegan = true;
            } else if (stripos($parts[1], 'dairy') !== false) {
                $has_current_dairy = true;
            }
        }
    }
    
    foreach ($immediate_slots as $slot) {
        $cabinet = $cabinets_by_id[$slot['cabinet']] ?? null;
        if (!$cabinet) continue;
        
        $parts = explode('_', $cabinet['_title']);
        if (count($parts) >= 2) {
            if (stripos($parts[1], 'vegan') !== false) {
                $has_immediate_vegan = true;
            } else if (stripos($parts[1], 'dairy') !== false) {
                $has_immediate_dairy = true;
            }
        }
    }
    
    // Build category list
    $categories_to_assign = array();
    
    // Dairy logic: current > immediate > not available
    if ($has_current_dairy) {
        $categories_to_assign[] = $categories['in-shop-dairy'];
    } elseif ($has_immediate_dairy) {
        $categories_to_assign[] = $categories['next-up-dairy'];
    } else {
        $categories_to_assign[] = $categories['not-avail-dairy'];
    }
    
    // Vegan logic: current > immediate > not available
    if ($has_current_vegan) {
        $categories_to_assign[] = $categories['in-shop-vegan'];
    } elseif ($has_immediate_vegan) {
        $categories_to_assign[] = $categories['next-up-vegan'];
    } else {
        $categories_to_assign[] = $categories['not-avail-vegan'];
    }
    
    // Update the public site post with these categories
    $result = wp_set_post_categories($public_post_id, $categories_to_assign, false);
    
    if ($result !== false) {
        $cat_names = array();
        foreach ($categories_to_assign as $cat_id) {
            $cat = get_category($cat_id);
            if ($cat) {
                $cat_names[] = $cat->name;
            }
        }
        echo "✓ " . $post->post_title . " (#{$public_post_id}): " . implode(', ', $cat_names) . "<br>";
        $sync_count++;
    } else {
        echo "✗ " . $post->post_title . " (#{$public_post_id}): Failed to update<br>";
        $error_count++;
    }
}

echo '<hr>';
echo '<strong>Sync Summary:</strong><br>';
echo 'Updated: <strong style="color: green;">' . $sync_count . '</strong><br>';
echo 'Errors: <strong style="color: red;">' . $error_count . '</strong><br>';
echo 'Total: <strong>' . count($web_id_map) . '</strong><br>';

echo '<br><strong style="color: #666;">NEXT STEPS:</strong><br>';
echo '1. Check the public site categories to verify they updated correctly<br>';
echo '2. Verify a few flavors are in the right categories (In Shop vs Next Up vs Not Available)<br>';
echo '3. Once satisfied, DEACTIVATE this snippet in Code Snippets<br>';
echo '4. You can reactivate anytime to manually resync<br>';

echo '</div>';
