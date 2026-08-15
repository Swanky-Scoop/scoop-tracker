<?php

/**
 * Minimal HTML shell for [scoop_dock] pages — no theme header/footer/nav.
 * Loaded via the template_include filter in includes/dock-template.php.
 *
 * `<main>`/`.entry-content` are kept (not theme markup, just the two
 * wrapper classnames) because assets/css.css scopes its docked-view layout
 * to them directly — e.g. `html body main .entry-content > .in-dock`
 * (css.css:1177) and the `html body { & main ... & .entry-content {...} }`
 * spacing reset (css.css:218-314). Without these two wrappers those rules
 * never match and .in-dock loses its fullscreen layout.
 */
global $post;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(get_the_title($post)); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class('scoop-dock-shell'); ?>>
<main>
<div class="entry-content">
<?php echo apply_filters('the_content', $post->post_content); ?>
</div>
</main>
<?php wp_footer(); ?>
</body>
</html>
