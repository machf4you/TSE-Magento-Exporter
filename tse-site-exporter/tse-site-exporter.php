<?php
/**
 * Plugin Name: TSE Site Exporter
 * Description: Adds a Tools page with a single button to export all public pages, posts, products and custom post types as a structured JSON file inside a downloadable ZIP archive.
 * Version:     1.0.0
 * Author:      TSE
 * License:     GPL-2.0-or-later
 * Text Domain: tse-site-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TSE_SITE_EXPORTER_VERSION', '1.0.0' );
define( 'TSE_SITE_EXPORTER_NONCE',   'tse_site_exporter_export' );

/**
 * Register the admin page under Tools.
 */
function tse_site_exporter_register_menu() {
    add_management_page(
        __( 'TSE Site Exporter', 'tse-site-exporter' ),
        __( 'TSE Site Exporter', 'tse-site-exporter' ),
        'manage_options',
        'tse-site-exporter',
        'tse_site_exporter_render_admin_page'
    );
}
add_action( 'admin_menu', 'tse_site_exporter_register_menu' );

/**
 * Render the admin page UI.
 */
function tse_site_exporter_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'tse-site-exporter' ) );
    }

    $action_url = admin_url( 'admin-post.php' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'TSE Site Exporter', 'tse-site-exporter' ); ?></h1>
        <p><?php echo esc_html__( 'Export all public pages, posts, products and custom post types into a structured JSON file packaged as a ZIP.', 'tse-site-exporter' ); ?></p>

        <form method="post" action="<?php echo esc_url( $action_url ); ?>">
            <input type="hidden" name="action" value="tse_site_exporter_export" />
            <?php wp_nonce_field( TSE_SITE_EXPORTER_NONCE, 'tse_site_exporter_nonce' ); ?>
            <p>
                <button type="submit"
                        class="button button-primary button-hero"
                        id="tse-site-exporter-button"
                        data-testid="tse-export-site-data-button">
                    <?php echo esc_html__( 'Export Site Data', 'tse-site-exporter' ); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Handle the export action.
 */
function tse_site_exporter_handle_export() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( TSE_SITE_EXPORTER_NONCE, 'tse_site_exporter_nonce' );

    if ( ! class_exists( 'ZipArchive' ) ) {
        wp_die( esc_html__( 'PHP ZipArchive extension is not available on this server. Please enable the PHP zip extension.', 'tse-site-exporter' ) );
    }

    @set_time_limit( 0 );

    $data = tse_site_exporter_collect_data();

    $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    if ( false === $json ) {
        wp_die( esc_html__( 'Failed to encode export data as JSON.', 'tse-site-exporter' ) );
    }

    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'tse-site-exporter';
    if ( ! file_exists( $tmp_dir ) ) {
        wp_mkdir_p( $tmp_dir );
    }

    $timestamp = gmdate( 'Ymd-His' );
    $site_slug = sanitize_title( get_bloginfo( 'name' ) );
    if ( empty( $site_slug ) ) {
        $site_slug = 'site';
    }

    $base_name = 'tse-site-export-' . $site_slug . '-' . $timestamp;
    $zip_path  = trailingslashit( $tmp_dir ) . $base_name . '.zip';
    $json_name = $base_name . '.json';

    $zip = new ZipArchive();
    if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
        wp_die( esc_html__( 'Could not create ZIP archive.', 'tse-site-exporter' ) );
    }
    $zip->addFromString( $json_name, $json );
    $zip->close();

    if ( ! file_exists( $zip_path ) ) {
        wp_die( esc_html__( 'ZIP archive was not generated.', 'tse-site-exporter' ) );
    }

    while ( ob_get_level() ) {
        ob_end_clean();
    }

    nocache_headers();
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . $base_name . '.zip"' );
    header( 'Content-Length: ' . filesize( $zip_path ) );
    header( 'X-Content-Type-Options: nosniff' );

    readfile( $zip_path );

    @unlink( $zip_path );
    exit;
}
add_action( 'admin_post_tse_site_exporter_export', 'tse_site_exporter_handle_export' );

/**
 * Collect all exportable site data.
 *
 * @return array
 */
function tse_site_exporter_collect_data() {
    $post_types = tse_site_exporter_get_target_post_types();

    $export = array(
        'meta' => array(
            'plugin'         => 'TSE Site Exporter',
            'plugin_version' => TSE_SITE_EXPORTER_VERSION,
            'site_url'       => home_url(),
            'site_name'      => get_bloginfo( 'name' ),
            'wp_version'     => get_bloginfo( 'version' ),
            'exported_at'    => gmdate( 'c' ),
            'post_types'     => array_values( $post_types ),
            'status_filter'  => 'publish',
        ),
        'content' => array(),
    );

    foreach ( $post_types as $post_type ) {
        $export['content'][ $post_type ] = tse_site_exporter_collect_post_type( $post_type );
    }

    return $export;
}

/**
 * Determine which post types to export: public posts, pages, products and any other public CPT.
 *
 * @return array
 */
function tse_site_exporter_get_target_post_types() {
    $public_types = get_post_types( array( 'public' => true ), 'names' );

    // Drop attachments (media): they are not user-authored content posts.
    unset( $public_types['attachment'] );

    return array_values( $public_types );
}

/**
 * Collect posts of a given post type with full structured data.
 *
 * @param string $post_type
 * @return array
 */
function tse_site_exporter_collect_post_type( $post_type ) {
    $items   = array();
    $page    = 1;
    $per_page = 200;

    do {
        $query = new WP_Query( array(
            'post_type'              => $post_type,
            'post_status'            => 'publish',
            'posts_per_page'         => $per_page,
            'paged'                  => $page,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => false,
            'ignore_sticky_posts'    => true,
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
            'suppress_filters'       => true,
        ) );

        if ( ! $query->have_posts() ) {
            break;
        }

        foreach ( $query->posts as $post ) {
            $items[] = tse_site_exporter_format_post( $post );
        }

        $max_pages = (int) $query->max_num_pages;
        wp_reset_postdata();
        $page++;
    } while ( $page <= $max_pages );

    return $items;
}

/**
 * Build the structured array representation of a post.
 *
 * @param WP_Post $post
 * @return array
 */
function tse_site_exporter_format_post( $post ) {
    $author = get_userdata( $post->post_author );

    $thumb_id  = get_post_thumbnail_id( $post->ID );
    $thumb_url = $thumb_id ? wp_get_attachment_url( $thumb_id ) : null;

    $taxonomies = array();
    $tax_names  = get_object_taxonomies( $post->post_type, 'names' );
    foreach ( $tax_names as $tax ) {
        $terms = wp_get_post_terms( $post->ID, $tax );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            continue;
        }
        $taxonomies[ $tax ] = array_map( function ( $term ) {
            return array(
                'term_id' => (int) $term->term_id,
                'name'    => $term->name,
                'slug'    => $term->slug,
                'parent'  => (int) $term->parent,
            );
        }, $terms );
    }

    $raw_meta = get_post_meta( $post->ID );
    $meta     = array();
    foreach ( $raw_meta as $key => $values ) {
        // Skip protected/internal meta keys (those starting with _) except common useful ones.
        if ( '_' === substr( $key, 0, 1 ) && ! in_array( $key, array( '_thumbnail_id', '_wp_page_template' ), true ) ) {
            continue;
        }
        $unserialized = array_map( 'maybe_unserialize', $values );
        $meta[ $key ] = count( $unserialized ) === 1 ? $unserialized[0] : $unserialized;
    }

    return array(
        'id'             => (int) $post->ID,
        'post_type'      => $post->post_type,
        'slug'           => $post->post_name,
        'title'          => get_the_title( $post ),
        'status'         => $post->post_status,
        'permalink'      => get_permalink( $post ),
        'date_gmt'       => $post->post_date_gmt,
        'modified_gmt'   => $post->post_modified_gmt,
        'menu_order'     => (int) $post->menu_order,
        'parent'         => (int) $post->post_parent,
        'comment_status' => $post->comment_status,
        'ping_status'    => $post->ping_status,
        'author'         => $author ? array(
            'id'           => (int) $author->ID,
            'login'        => $author->user_login,
            'display_name' => $author->display_name,
        ) : null,
        'excerpt'        => $post->post_excerpt,
        'content'        => $post->post_content,
        'featured_image' => $thumb_url,
        'taxonomies'     => $taxonomies,
        'meta'           => $meta,
    );
}
