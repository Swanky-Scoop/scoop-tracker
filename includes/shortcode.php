<?php

/**
 * Shortcodes: [scoop_grid type="Cabinet" location="935"]
 *             [scoop_tile type="InstockFlavor" group="cabinet" filter="tubs"]
 *
 * Both produce the same bundle-driven host div (same data-* contract); only
 * data-view differs ("grid" vs "tile"), which assets/data/scoop-api.js reads
 * to decide whether to mount a Grid (table) or Tile (card) renderer against
 * the same model. Host keeps the "scoop-grid" class either way so the
 * existing `.scoop-grid[data-grid-type]` host discovery doesn't need to change.
 */

function scoop_render_grid_host($raw_atts, string $view) {
    $atts = shortcode_atts([
        'type'     => 'Cabinet', // Cabinet | tub | etc
        'location'       => null,
        'days'           => null,
        'date_filters'   => null,
        'modified_range' => null,
        // Row grouping (e.g. "cabinet") and row filtering (e.g. "tubs" — only
        // rows with active tubs). Currently consumed by InstockFlavorGridModel;
        // unrelated to date_filters/filter_* below, which are a different,
        // date-range-specific mechanism used by DateActivity/BatchHistory.
        'group'          => null,
        'filter'         => null,
    ], $raw_atts, 'scoop_' . $view);

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

    $extra_class = $view === 'tile' ? 'scoop-tile' : '';

    ob_start();
    ?>
    <div
    id="<?php echo esc_attr($id); ?>"
    class="scoop-grid <?php echo esc_attr($extra_class); ?> <?php echo esc_attr($atts['type']); ?>"
    data-grid-type="<?php echo esc_attr($atts['type']); ?>"
    data-view="<?php echo esc_attr($view); ?>"
    data-location="<?php echo esc_attr($atts['location']); ?>"
    <?php if (!empty($atts['days'])) : ?>
    data-days="<?php echo esc_attr($atts['days']); ?>"
    <?php endif; ?>
    <?php if (!empty($atts['group'])) : ?>
    data-group="<?php echo esc_attr($atts['group']); ?>"
    <?php endif; ?>
    <?php if (!empty($atts['filter'])) : ?>
    data-filter="<?php echo esc_attr($atts['filter']); ?>"
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
    return ob_get_clean();
}

add_shortcode('scoop_grid', function ($atts) {
    return scoop_render_grid_host(is_array($atts) ? $atts : [], 'grid');
});

add_shortcode('scoop_tile', function ($atts) {
    return scoop_render_grid_host(is_array($atts) ? $atts : [], 'tile');
});
