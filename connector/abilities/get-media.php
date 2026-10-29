<?php
/**
 * Get Media Ability - WordPress Abilities API
 *
 * Lists media library items with filtering options.
 *
 * @package ClassicMonks
 * @subpackage AI/Abilities
 * @since 1.2.0
 * @requires WordPress 6.9+ (Abilities API)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register the get-media ability.
 *
 * Returns media library items with filtering.
 *
 * @since 1.2.0
 */
add_action( 'wp_abilities_api_init', 'cm_register_get_media_ability' );
function cm_register_get_media_ability() {
    wp_register_ability( 'classic-monks/get-media', array(
        'label'       => __( 'Get Media', 'classic-monks' ),
        'description' => __( 
            "Lists media library items with filtering and pagination. Use this ability to retrieve images, videos, documents, and other attachments. \n\n## Optional Parameters\n- **mime_type**: Filter by MIME type (image, video, audio, application, or specific like image/jpeg)\n- **limit**: Number of items to return (1-100, default 20)\n- **offset**: Number of items to skip (for pagination)\n- **search**: Search term to filter by title\n- **orderby**: Order by date, title, or modified (default date)\n- **order**: ASC or DESC (default DESC)\n- **id**: One attachment by ID\n- **month**: Uploaded in this month, YYYY-MM\n- **usage**: Also count the posts that use each item (slower)\n\n## Returns\nArray of media items with: id, title, url, mime_type, alt_text, caption, description, file_name, thumbnail, dimensions, file_size, author, date and usage (null unless asked). Also the total count for pagination, the file types the site accepts (allowed_types, extension => MIME type) and the upload limit in bytes.",
            'classic-monks' 
        ),
        'category'    => 'media',
        'input_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'mime_type' => array(
                    'type'        => 'string',
                    'enum'        => array( 'image', 'video', 'audio', 'application', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', '' ),
                    'default'     => '',
                    'description' => 'MIME type filter. Use category (image, video) or specific type (image/jpeg). Empty for all.',
                ),
                'limit'     => array(
                    'type'        => 'integer',
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => 100,
                    'description' => 'Number of items to return (1-100).',
                ),
                'offset'    => array(
                    'type'        => 'integer',
                    'default'     => 0,
                    'minimum'     => 0,
                    'description' => 'Number of items to skip (for pagination).',
                ),
                'search'    => array(
                    'type'        => 'string',
                    'description' => 'Search term to filter by title.',
                ),
                'orderby'   => array(
                    'type'        => 'string',
                    'enum'        => array( 'date', 'title', 'modified' ),
                    'default'     => 'date',
                    'description' => 'Field to order by.',
                ),
                'order'     => array(
                    'type'        => 'string',
                    'enum'        => array( 'ASC', 'DESC' ),
                    'default'     => 'DESC',
                    'description' => 'Sort direction.',
                ),
                'id'        => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Only this attachment.',
                ),
                'month'     => array(
                    'type'        => 'string',
                    'pattern'     => '^[0-9]{4}-[0-9]{2}$',
                    'description' => 'Uploaded in this month, YYYY-MM.',
                ),
                'usage'     => array(
                    'type'        => 'boolean',
                    'default'     => false,
                    'description' => 'Count the posts that use each item (featured image or in the content).',
                ),
            ),
            'additionalProperties' => false,
        ),
        'output_schema' => array(
            'type'       => 'object',
            'properties' => array(
                'media' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'        => array( 'type' => 'integer' ),
                            'title'     => array( 'type' => 'string' ),
                            'url'       => array( 'type' => 'string', 'format' => 'uri' ),
                            'mime_type' => array( 'type' => 'string' ),
                            'alt_text'  => array( 'type' => 'string' ),
                            'thumbnail' => array( 'type' => array( 'string', 'null' ), 'format' => 'uri', 'nullable' => true ),
                            'width'     => array( 'type' => 'integer' ),
                            'height'    => array( 'type' => 'integer' ),
                            'file_size' => array( 'type' => 'integer' ),
                            'date'      => array( 'type' => 'string', 'format' => 'date-time' ),
                            'caption'     => array( 'type' => 'string' ),
                            'description' => array( 'type' => 'string' ),
                            'file_name'   => array( 'type' => 'string' ),
                            'author_id'   => array( 'type' => 'integer' ),
                            'author'      => array( 'type' => 'string' ),
                            'usage'       => array( 'type' => array( 'integer', 'null' ), 'description' => 'Posts using it; null unless usage was asked.' ),
                        ),
                    ),
                ),
                'total' => array(
                    'type'        => 'integer',
                    'description' => 'Total number of matching items.',
                ),
                'allowed_types' => array(
                    'type'        => 'object',
                    'description' => 'File types this user may upload: extensions => MIME type.',
                ),
                'upload_limit'  => array(
                    'type'        => 'integer',
                    'description' => 'Largest upload the site accepts, in bytes.',
                ),
            ),
        ),
        'execute_callback'    => 'cm_execute_get_media',
        'permission_callback' => function() {
            return current_user_can( 'upload_files' );
        },
        'meta' => array(
            'annotations' => array(
                'readonly'     => true,
                'destructive'  => false,
                'idempotent'   => true,
                'instructions' => 'Retrieve media library items. Use mime_type to filter by type (image, video, etc.).',
            ),
            'show_in_rest' => true,
        ),
    ) );
}

/**
 * Execute the get-media ability.
 *
 * @since 1.2.0
 * @param array $input Input parameters.
 * @return array Media items and total count.
 */
function cm_execute_get_media( $input = array() ) {
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => min( absint( isset( $input['limit'] ) ? $input['limit'] : 20 ), 100 ),
        'offset'         => absint( isset( $input['offset'] ) ? $input['offset'] : 0 ),
        'orderby'        => sanitize_key( isset( $input['orderby'] ) ? $input['orderby'] : 'date' ),
        'order'          => ( isset( $input['order'] ) && 'ASC' === strtoupper( $input['order'] ) ) ? 'ASC' : 'DESC',
    );

    // MIME type filter
    if ( ! empty( $input['mime_type'] ) ) {
        $args['post_mime_type'] = sanitize_text_field( $input['mime_type'] );
    }

    // Search filter
    if ( ! empty( $input['search'] ) ) {
        $args['s'] = sanitize_text_field( $input['search'] );
    }

    if ( ! empty( $input['id'] ) ) {
        $args['p'] = absint( $input['id'] );
    }
    if ( ! empty( $input['month'] ) ) {
        $args['m'] = str_replace( '-', '', $input['month'] );
    }

    $query = new WP_Query( $args );
    $media = array();

    foreach ( $query->posts as $post ) {
        $metadata  = wp_get_attachment_metadata( $post->ID );
        $file_path = get_attached_file( $post->ID );

        $item = array(
            'id'        => $post->ID,
            'title'     => $post->post_title,
            'url'       => wp_get_attachment_url( $post->ID ),
            'mime_type' => $post->post_mime_type,
            'alt_text'  => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ) ?: '',
            'date'      => $post->post_date,
        );

        // Thumbnail (for images)
        $thumbnail = wp_get_attachment_image_src( $post->ID, 'thumbnail' );
        $item['thumbnail'] = $thumbnail ? $thumbnail[0] : null;

        // Dimensions (for images)
        if ( isset( $metadata['width'] ) && isset( $metadata['height'] ) ) {
            $item['width']  = (int) $metadata['width'];
            $item['height'] = (int) $metadata['height'];
        } else {
            $item['width']  = 0;
            $item['height'] = 0;
        }

        // File size
        $item['file_size'] = $file_path && file_exists( $file_path ) ? (int) filesize( $file_path ) : 0;

        $item['caption']     = $post->post_excerpt;
        $item['description'] = $post->post_content;
        $item['file_name']   = $file_path ? wp_basename( $file_path ) : '';
        $item['author_id']   = (int) $post->post_author;
        $item['author']      = (string) get_the_author_meta( 'display_name', $post->post_author );
        $item['usage']       = empty( $input['usage'] ) ? null : cm_media_usage_count( $post->ID, $metadata );

        $media[] = $item;
    }

    return array(
        'media'         => $media,
        'total'         => (int) $query->found_posts,
        'allowed_types' => (object) get_allowed_mime_types(),
        'upload_limit'  => (int) wp_max_upload_size(),
    );
}

/**
 * Posts, pages and other entries (not revisions) that use an attachment: as their featured image, or in the
 * content by its wp-image-ID class, block id attribute or file name (any size). Page builders' own data is not read.
 *
 * @param int         $id       Attachment ID.
 * @param array|false $metadata Its attachment metadata.
 * @return int
 */
function cm_media_usage_count( $id, $metadata ) {
    global $wpdb;
    $file  = (string) get_post_meta( $id, '_wp_attached_file', true );
    $names = array( wp_basename( $file ) );
    foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
        $names[] = $size['file'] ?? '';
    }
    if ( ! empty( $metadata['original_image'] ) ) {
        $names[] = $metadata['original_image'];
    }
    $dir   = '/' . ltrim( dirname( $file ), './' );
    $likes = array( '%wp-image-' . $id . '"%', '%wp-image-' . $id . ' %', '%"id":' . $id . ',%', '%"id":' . $id . '}%' );
    foreach ( array_filter( array_unique( $names ) ) as $name ) {
        $likes[] = '%' . $wpdb->esc_like( rtrim( $dir, '/' ) . '/' . $name ) . '%';
    }
    $content = implode( ' OR ', array_fill( 0, count( $likes ), 'p.post_content LIKE %s' ) );
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- $content is only placeholders; a live count, not cached
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
        WHERE p.post_type NOT IN ('revision', 'attachment') AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')
        AND ( p.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s ) OR {$content} )",
        array_merge( array( (string) $id ), $likes )
    ) );
    // phpcs:enable
    return $count;
}