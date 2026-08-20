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
        // Disambiguates two hosts of the same `type` on one page (e.g. two
        // FlavorTub grids for different locations) for the location hash's
        // per-control key — see ScoopAPI._controlId(). Optional; most pages
        // only ever have one host per type and don't need this.
        'slug'           => null,
        // Hardcoded assignee filter for TaskHistory (see
        // assets/models/task-history-grid-model.js) — a WP user id, or
        // "all"/omitted for no filter. Generic passthrough like group/filter
        // above; only TaskHistory reads it today.
        'user'           => null,
    ], $raw_atts, 'scoop_' . $view);

    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this.</p>';
    }

    // "Iframe topic" types (e.g. 'ProductionPlan' — see _config.php's
    // iframe_url + scoop_client_metadata()'s iframeUrl field) are gated per
    // role via the same scoop_user_can_route() matrix every write route
    // already uses (see _policy.php), just checked here instead of a REST
    // permission_callback since this type has no route of its own. Scoped
    // to only types that declare iframe_url — every other type's host
    // renders exactly as before, no new gate introduced for them. Returns
    // nothing at all (not an error message) so the button/control is
    // genuinely absent for a role that shouldn't see it, not just refused.
    $route_cfg = scoop_routes_config((string)$atts['type']);
    if (!empty($route_cfg['iframe_url']) && !scoop_user_can_route(wp_get_current_user(), (string)$atts['type'], 'GET')) {
        return '';
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
    <?php if (!empty($atts['slug'])) : ?>
    data-slug="<?php echo esc_attr($atts['slug']); ?>"
    <?php endif; ?>
    <?php if (!empty($atts['days'])) : ?>
    data-days="<?php echo esc_attr($atts['days']); ?>"
    <?php endif; ?>
    <?php if (!empty($atts['group'])) : ?>
    data-group="<?php echo esc_attr($atts['group']); ?>"
    <?php endif; ?>
    <?php if (!empty($atts['filter'])) : ?>
    data-filter="<?php echo esc_attr($atts['filter']); ?>"
    <?php endif; ?>
    <?php if (!empty($atts['user'])) : ?>
    data-user="<?php echo esc_attr($atts['user']); ?>"
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
 * Shortcode: [scoop_iframe title="..." url="..." slug="..." icon="..."]
 *
 * A dockable host for an arbitrary third-party iframe embed (e.g. a
 * published Google Doc) — see assets/ui/iframe-panel.js. Unlike
 * [scoop_grid]/[scoop_tile], this host carries no bundle-entity contract:
 * title/url/icon are passed straight through as data attributes and the
 * client renders them with zero server round-trip, no Pods entity, no REST
 * route. General-purpose by design — every attribute is per-instance, so a
 * page can embed as many different URLs as it wants just by writing more of
 * this shortcode. Fixed at 'half-nostack' canvas sizing (see
 * IframePanel's constructor) — not configurable from the shortcode.
 *
 * `icon` accepts the same shapes List._buildToggleButton() (_dockable.js)
 * understands everywhere else — an "if:<name>" icon-font marker, inline
 * "<svg ...>" markup, an image path, or a literal glyph — falling back to
 * IframePanel's own "🖼" default when omitted.
 */
add_shortcode('scoop_iframe', function ($raw_atts) {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this.</p>';
    }

    $atts = shortcode_atts([
        'title' => 'Embed',
        'url'   => '',
        // Disambiguates two [scoop_iframe] hosts on one page for the
        // #dock= hash — same convention as [scoop_grid]'s own `slug` (see
        // scoop_render_grid_host above and ScoopAPI._controlId()).
        'slug'  => null,
        'icon'  => null,
    ], is_array($raw_atts) ? $raw_atts : [], 'scoop_iframe');

    if (empty($atts['url'])) {
        return '<p>scoop_iframe: missing required "url" attribute.</p>';
    }

    $id = 'scoop-grid-' . uniqid();

    ob_start();
    ?>
    <div
    id="<?php echo esc_attr($id); ?>"
    class="scoop-grid Iframe"
    data-grid-type="Iframe"
    data-title="<?php echo esc_attr($atts['title']); ?>"
    data-url="<?php echo esc_url($atts['url']); ?>"
    <?php if (!empty($atts['slug'])) : ?>
    data-slug="<?php echo esc_attr($atts['slug']); ?>"
    <?php endif; ?>
    <?php if (!empty($atts['icon'])) : ?>
    data-icon="<?php echo esc_attr($atts['icon']); ?>"
    <?php endif; ?>
    ></div>
    <?php
    return ob_get_clean();
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
 *
 * [scoop_dock location="935"] sets a page-wide default location for any
 * nested [scoop_grid]/[scoop_tile] that doesn't specify its own `location`
 * attribute — read as `data-default-location` off .in-dock. This is the
 * lowest-priority tier in ScoopAPI._resolveLocation()'s cascade (see
 * DOCKING.md's "State model"): a per-control #loc.<id>= hash value beats a
 * general #location= hash value beats a shortcode's own `location=` attr
 * beats this dock default beats the hardcoded 935 fallback.
 */
add_shortcode('scoop_dock', function ($raw_atts, $content = null) {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view this.</p>';
    }

    $atts = shortcode_atts(['location' => null], is_array($raw_atts) ? $raw_atts : [], 'scoop_dock');

    $inner = do_shortcode((string)$content);

    ob_start();
    ?>
    <div class="in-dock"<?php if (!empty($atts['location'])) : ?> data-default-location="<?php echo esc_attr($atts['location']); ?>"<?php endif; ?>>
      <div class="action-target"></div>
      <div class="toolbar">
        <?php if (current_user_can('manage_options')) : ?>
        <a href="../wp-admin/edit.php?post_type=tub" class="gridToggle wp"><i class="ab-icon"></i><span class="dockTitle">WP Admin</span></a>
        <?php endif; ?>
        <?php
        // Same destination the JS lands people on after an expired-session
        // bounce or the 6h idle timeout (ScoopAPI._redirectToLogin) —
        // wp_logout_url() clears the auth cookie (nonce-verified, core WP),
        // then sends them to wp-login.php pre-filled with a redirect_to back
        // to this exact page so logging back in returns them here.
        $current_url = home_url(esc_url_raw($_SERVER['REQUEST_URI'] ?? '/'));
        $logout_url  = wp_logout_url(wp_login_url($current_url));
        ?>
        <a href="<?php echo esc_url($logout_url); ?>" class="gridToggle logout"><i class="dockIcon si-lock"></i><span class="dockTitle">Log out</span></a>
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
