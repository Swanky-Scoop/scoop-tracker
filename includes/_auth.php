<?php
/**
 * Authentication helpers for Scoop Rest
 * 
 * Supports two auth methods:
 * - Session/Cookie auth (required for write operations)
 * - Basic Auth (allowed for read-only operations only)
 */

if (!defined('ABSPATH')) exit;

/**
 * Read Basic Auth credentials from the request/server environment.
 * 
 * @return array{0:string,1:string}|false
 */
function scoop_get_basic_auth_credentials(\WP_REST_Request $req = null) {
    if (!empty($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
        return [
            (string) $_SERVER['PHP_AUTH_USER'],
            (string) $_SERVER['PHP_AUTH_PW'],
        ];
    }

    $auth_header = $req ? (string) $req->get_header('authorization') : '';

    if ('' === $auth_header) {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'Authorization'] as $key) {
            if (!empty($_SERVER[$key])) {
                $auth_header = (string) $_SERVER[$key];
                break;
            }
        }
    }

    if ('' === $auth_header && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $auth_header = (string) $value;
                break;
            }
        }
    }

    if (stripos($auth_header, 'Basic ') !== 0) {
        return false;
    }
    
    $credentials = base64_decode(substr($auth_header, 6), true);
    if (!$credentials || strpos($credentials, ':') === false) {
        return false;
    }
    
    return explode(':', $credentials, 2);
}

/**
 * Validate Basic Auth credentials and return the user object.
 * Does not set current user; just validates and returns.
 * 
 * @return WP_User|false The authenticated user, or false if invalid
 */
function scoop_validate_basic_auth(\WP_REST_Request $req = null) {
    $credentials = scoop_get_basic_auth_credentials($req);
    if (!$credentials) {
        return false;
    }

    list($username, $password) = $credentials;
    
    $user = wp_authenticate($username, $password);
    
    if (is_wp_error($user)) {
        error_log(sprintf(
            'SCOOP Basic Auth failed for user "%s" with code(s): %s',
            sanitize_user($username),
            implode(',', $user->get_error_codes())
        ));
        return false;
    }
    
    return $user;
}

/**
 * Permission callback for READ-ONLY endpoints
 * Allows both session auth (cookie) and Basic Auth
 * 
 * Sets wp_set_current_user() if basic auth is used
 * 
 * @param WP_REST_Request $req
 * @return bool
 */
function scoop_require_authenticated_user_read_only(\WP_REST_Request $req) {
    // WordPress core handles valid Application Passwords before the permission callback.
    if (is_user_logged_in()) return true;

    // Fall back to manual Basic Auth parsing for hosts that do not map the header.
    $basic_user = scoop_validate_basic_auth($req);
    if ($basic_user) {
        wp_set_current_user($basic_user->ID);
        return true;
    }

    error_log('SCOOP read-only REST auth failed: no valid session or Basic Auth credentials.');

    return false;
}

/**
 * Factory function for write permission callbacks
 * Returns a closure that checks session auth and optional route-specific permissions
 * 
 * @param string $route_key The route identifier for logging
 * @param string|callable $route_auth_callback (optional) Authorization check function, defaults to 'scoop_user_can_route'
 * @return Closure
 */
function scoop_write_permission($route_key, $route_auth_callback = 'scoop_user_can_route') {
    return function(\WP_REST_Request $req) use ($route_key, $route_auth_callback) {
        // Session/cookie auth only - no Basic Auth for writes
        if (!is_user_logged_in()) return false;

        $user = wp_get_current_user();
        $method = $req->get_method();

        // If additional auth callback provided, use it
        $allowed = is_callable($route_auth_callback)
            ? call_user_func($route_auth_callback, $user, $route_key, $method)
            : true;

        return $allowed;
    };
}

/**
 * The homepage carries the [scoop_grid ...] shell for a logged-out visitor —
 * previously that just rendered shortcode.php's "You must be logged in"
 * paragraph in place. Send them to wp-login instead, same as an expired
 * session gets bounced there client-side (see ScoopAPI._redirectToLogin in
 * assets/data/scoop-api.js).
 */
add_action('template_redirect', 'scoop_require_login_for_homepage');
function scoop_require_login_for_homepage() {
    if (!is_front_page()) return;
    if (is_user_logged_in()) return;

    $current_url = home_url(esc_url_raw($_SERVER['REQUEST_URI'] ?? '/'));
    wp_safe_redirect(wp_login_url($current_url));
    exit;
}

/**
 * Landing page per role after wp-login.php — ice_cream_maker only ever works
 * the Batch/Cabinet docks (see _policy.php's role reference comment: "Makes
 * tubs, doesn't touch them afterwards"), so the default wp-admin destination
 * is useless to them. Unconditional, not just a fallback for an empty
 * $requested_redirect_to — an expired-session bounce-back
 * (ScoopAPI._redirectToLogin) already lands them back on a dock page anyway,
 * so overriding it doesn't cost anything for this role.
 */
add_filter('login_redirect', 'scoop_role_login_redirect', 10, 3);
function scoop_role_login_redirect($redirect_to, $requested_redirect_to, $user) {
    if (!($user instanceof WP_User)) return $redirect_to;

    $role_redirects = [
        'ice_cream_maker' => home_url('/dock/#dock=Batch,Cabinet'),
    ];

    foreach ($role_redirects as $role => $url) {
        if (in_array($role, (array) $user->roles, true)) return $url;
    }

    return $redirect_to;
}
