<?php

/**
 * Any page built from [scoop_dock] renders through a minimal, theme-free
 * HTML shell instead of the active theme's page template — skips
 * get_header()/get_footer() (nav menu, widgets, sidebar DOM) entirely.
 * Still a normal WP Page under the hood (editable slug/content in
 * wp-admin, full WP bootstrap for auth/nonces/REST) — only the rendering
 * path changes. Matched by shortcode presence, the same check
 * scoop_enqueue_assets() already uses, so it doesn't depend on a specific
 * page slug and picks up any future [scoop_dock] page automatically.
 */
add_filter('template_include', function ($template) {
  if (!is_singular()) return $template;

  global $post;
  if (!$post || !has_shortcode($post->post_content, 'scoop_dock')) return $template;

  return SCOOP_REST_DIR . 'includes/dock-shell.php';
});
