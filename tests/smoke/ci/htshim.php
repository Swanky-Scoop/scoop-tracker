<?php
/**
 * php -S router shim — emulates the Apache behavior the Local mirror relies on:
 *  1. PHP_AUTH_USER/PHP_AUTH_PW populated from the HTTP Authorization header.
 *  2. Existing .php files (wp-login.php, wp-cron.php, plugin php endpoints) are
 *     EXECUTED directly, not routed through the WP front controller — routing
 *     them through index.php makes WP treat them as permalinks and
 *     wp_redirect_admin_locations() 302-loops them.
 *  3. Static assets are served MANUALLY (readfile + mime type) — this box's
 *     php -S ignores the router's `return true` (built-in static handler) and
 *     falls through to index.php, so assets must be streamed by the router.
 *  4. Everything else (permalinks, /wp-json/, /dock/) -> WP front controller.
 */
if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $h = $_SERVER['HTTP_AUTHORIZATION'];
    if (stripos($h, 'Basic ') === 0) {
        $dec = base64_decode(substr($h, 6), true);
        if ($dec !== false && strpos($dec, ':') !== false) {
            [$u, $p] = explode(':', $dec, 2);
            $_SERVER['PHP_AUTH_USER'] = $u;
            $_SERVER['PHP_AUTH_PW'] = $p;
        }
    }
}
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = $_SERVER['DOCUMENT_ROOT'] . $path;
    if ($path !== '/' && file_exists($file)) {
        if (is_dir($file)) {
            $index = rtrim($file, '/') . '/index.php';
            if (file_exists($index)) { chdir(dirname($index)); require $index; return; }
        } elseif (substr($path, -4) === '.php') {
            chdir(dirname($file));
            require $file;
            return;
        } else {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $mimes = [
              'css' => 'text/css', 'js' => 'application/javascript', 'mjs' => 'application/javascript',
              'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
              'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'woff' => 'font/woff',
              'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'json' => 'application/json',
              'txt' => 'text/plain', 'html' => 'text/html', 'map' => 'application/json',
            ];
            header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
            header('Content-Length: ' . filesize($file));
            readfile($file);
            return true;
        }
    }
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
