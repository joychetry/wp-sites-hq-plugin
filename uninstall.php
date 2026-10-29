<?php
/**
 * Runs when the plugin is deleted from wp-admin. Removes everything the plugin stored.
 *
 * @package MonksCentral
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'monks_central_settings' );
