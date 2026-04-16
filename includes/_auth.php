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
 * Validate Basic Auth credentials and return the user object
 * Does NOT set current user - just validates and returns
 * 
 * @return WP_User|false The authenticated user, or false if invalid
 */
function scoop_validate_basic_auth() {
    $auth_header = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    
    if (strpos($auth_header, 'Basic ') !== 0) {
        return false;
    }
    
    $credentials = base64_decode(substr($auth_header, 6));
    if (!$credentials || strpos($credentials, ':') === false) {
        return false;
    }
    
    list($username, $password) = explode(':', $credentials, 2);
    
    $user = wp_authenticate($username, $password);
    
    if (is_wp_error($user)) {
        error_log('SCOOP Basic Auth failed for user: ' . $username);
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
    // Try Basic Auth first (read-only operations only)
    $basic_user = scoop_validate_basic_auth();
    if ($basic_user) {
        wp_set_current_user($basic_user->ID);
        return true;
    }
    
    // Fall back to session/cookie auth
    if (is_user_logged_in()) return true;
    
    // Log failed attempts
    $u = wp_get_current_user();
    
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