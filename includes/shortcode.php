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
        // Batch-only: when truthy, the JS mounts a read-only BatchHistory
        // listing right inside the Batch widget, its <form> placed
        // immediately after Batch's own — see
        // ScoopAPI._mountEmbeddedBatchHistory() in assets/data/scoop-api.js.
        'history'        => null,
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
    <?php if (!empty($atts['history']) && !in_array(strtolower((string)$atts['history']), ['0', 'false', 'no', 'off'], true)) : ?>
    data-history="1"
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

/**
 * Shortcode: [scoop_dock] ... nested [scoop_grid]/[scoop_tile] ... [/scoop_dock]
 *
 * Enclosing wrapper, not a data host itself. Emits an .in-dock container
 * with: an empty .action-target and an empty <aside> — two "slot" targets a
 * control's whole host can be moved into instead of staying in .canvas (see
 * List.dockToggle() in assets/ui/_list.js), each holding at most one open
 * control at a time (List._closeSlotSiblings()) — which slot a given
 * control uses is just its model's `dockTarget` value ('action' | 'aside'),
 * read from that type's `target` entry in scoop_routes_config(); an empty
 * .toolbar (each control's .gridToggle button gets moved in here once it
 * mounts); a .dock-resizer drag handle (see assets/ui/dock-resizer.js) for
 * resizing <aside> against .canvas; and .canvas itself, holding the nested
 * shortcodes' own rendered output, unchanged, for every control that isn't
 * in a slot. .action-target/<aside> are peers of .toolbar and .canvas, not
 * children of either — see the CSS docking section in assets/css.css for
 * why (a `transform` on a slot that CONTAINS a docked Batch, which is
 * itself position:fixed, would hijack Batch's viewport-relative
 * positioning). Controls inside .canvas don't know they're docked; the
 * docking hook is purely "does this control's host have an .in-dock
 * ancestor".
 */
add_shortcode('scoop_dock', function ($atts, $content = null) {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this.</p>';
    }

    $inner = do_shortcode((string)$content);

    ob_start();
    ?>
    <div class="in-dock">
      <div class="action-target"></div>
      <div class="toolbar">
        <a href="../wp-admin/edit.php?post_type=tub" class="gridToggle wp"><i class="ab-icon"></i><span class="dockTitle">WP Admin</span></a>
        <div class="PAGE-STATUS">
            <h3>Status</h3>
            <em></em>
            <ul></ul>
        </div>
      </div>
      <div class="canvas"><?php echo $inner; ?></div>
      <div class="dock-resizer"></div>
      <aside></aside>
    </div>
    <?php
    return ob_get_clean();
});
