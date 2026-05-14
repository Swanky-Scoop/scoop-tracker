<?php

/**
 * Shortcode: [scoop_grid type="Cabinet" location="935"]
 */

add_shortcode('scoop_grid', function ($atts) {
    $raw_atts = is_array($atts) ? $atts : [];

    $atts = shortcode_atts([
        'type'     => 'Cabinet', // Cabinet | tub | etc
        'location'       => null,
        'days'           => null,
        'date_filters'   => null,
        'modified_range' => null,
    ], $raw_atts, 'scoop_grid');

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this.</p>';
    }

    $id = 'scoop-grid-' . uniqid();

    $normalize_filter_key = function ($key) {
        $key = strtolower(trim((string)$key));
        $key = str_replace('-', '_', $key);
        return preg_replace('/[^a-z0-9_]/', '', $key);
    };

    $date_filters = [];
    if (!empty($atts['date_filters'])) {
        foreach (explode(',', (string)$atts['date_filters']) as $key) {
            $key = $normalize_filter_key($key);
            if ($key !== '' && !in_array($key, $date_filters, true)) {
                $date_filters[] = $key;
            }
        }
    }

    $date_filter_value_atts = [];
    foreach ($raw_atts as $key => $value) {
        $key = (string)$key;
        if (strpos($key, 'filter_') === 0) {
            $filter_key = $normalize_filter_key(substr($key, 7));
        } elseif (strpos($key, 'filter-') === 0) {
            $filter_key = $normalize_filter_key(substr($key, 7));
        } else {
            continue;
        }

        if ($filter_key !== '') {
            $date_filter_value_atts[$filter_key] = $value;
        }
    }

    // Backward compatibility for existing DateActivity shortcodes.
    if (!empty($atts['modified_range']) && empty($date_filter_value_atts['activity'])) {
        $date_filter_value_atts['activity'] = $atts['modified_range'];
    }
    
    ob_start();
    ?>
    <div
    id="<?php echo esc_attr($id); ?>"
    class="scoop-grid <?php echo esc_attr($atts['type']); ?>"
    data-grid-type="<?php echo esc_attr($atts['type']); ?>"
    data-location="<?php echo esc_attr($atts['location']); ?>"
    <?php if (!empty($atts['days'])) : ?>
    data-days="<?php echo esc_attr($atts['days']); ?>"
    <?php endif; ?>
    <?php if (!empty($date_filters)) : ?>
    data-date-filters="<?php echo esc_attr(implode(',', $date_filters)); ?>"
    <?php endif; ?>
    <?php foreach ($date_filter_value_atts as $filter_key => $filter_value) : ?>
    data-filter-<?php echo esc_attr(str_replace('_', '-', $filter_key)); ?>="<?php echo esc_attr($filter_value); ?>"
    <?php endforeach; ?>
    <?php if (!empty($atts['modified_range'])) : ?>
    data-modified-range="<?php echo esc_attr($atts['modified_range']); ?>"
    <?php endif; ?>
    ></div>
    <?php
    $output = ob_get_clean();
    
    return $output;
});
