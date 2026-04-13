<?php

/**
 * Shortcode: [scoop_grid type="Cabinet" location="935"]
 */

add_shortcode('scoop_grid', function ($atts) {
    
    $atts = shortcode_atts([
        'type'     => 'Cabinet', // Cabinet | tub | etc
        'location' => null,
    ], $atts, 'scoop_grid');

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this.</p>';
    }

    $id = 'scoop-grid-' . uniqid();
    
    ob_start();
    ?>
    <div
    id="<?php echo esc_attr($id); ?>"
    class="scoop-grid <?php echo esc_attr($atts['type']); ?>"
    data-grid-type="<?php echo esc_attr($atts['type']); ?>"
    data-location="<?php echo esc_attr($atts['location']); ?>"
    ></div>
    <?php
    $output = ob_get_clean();
    
    return $output;
});