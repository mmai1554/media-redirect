<?php
/**
 * Plugin Name: Media 404 Redirect (Dynamic Upload Path)
 * Description: Redirects old upload root requests to dated folders dynamically using WordPress configuration.
 */
add_action('template_redirect', function () {

    if (!is_404()) {
        return;
    }

    // Get dynamic upload configuration
    $upload_dir = wp_get_upload_dir();
    $baseurl    = $upload_dir['baseurl'];  // e.g. https://domain/src/data/files
    $basedir    = $upload_dir['basedir'];  // physical path

    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    if (!$request_uri) {
        return;
    }

    $path = parse_url($request_uri, PHP_URL_PATH);
    if (!$path) {
        return;
    }

    // Only handle requests inside upload base URL
    $upload_path = parse_url($baseurl, PHP_URL_PATH);

    if (stripos($path, $upload_path) === false) {
        return;
    }

    // Ignore already dated paths (YYYY/MM/)
    if (preg_match('~/\d{4}/\d{2}/~', $path)) {
        return;
    }

    $basename = basename($path);
    if (!$basename) {
        return;
    }

    // Allow only typical media extensions
    if (!preg_match('/\.(jpg|jpeg|png|gif|webp|svg|pdf)$/i', $basename)) {
        return;
    }

    // Transient cache
    $cache_key = 'media_redirect_' . md5(strtolower($basename));
    $cached = get_transient($cache_key);

    if ($cached) {
        wp_redirect($cached, 301);
        exit;
    }

    global $wpdb;

    $like = '%' . $wpdb->esc_like('/' . $basename);

    $attachment_id = (int) $wpdb->get_var($wpdb->prepare("
        SELECT post_id
        FROM {$wpdb->postmeta}
        WHERE meta_key = '_wp_attached_file'
        AND meta_value LIKE %s
        ORDER BY post_id DESC
        LIMIT 1
    ", $like));

    if ($attachment_id) {
        $new_url = wp_get_attachment_url($attachment_id);
        if ($new_url) {

            set_transient($cache_key, $new_url, DAY_IN_SECONDS * 7);

            wp_redirect($new_url, 301);
            exit;
        }
    }

}, 0);
