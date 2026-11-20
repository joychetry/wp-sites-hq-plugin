<?php
/**
 * Test fixture, copied to wp-content/object-cache.php by oauth-objcache.sh only.
 * Claims an external object cache but keeps data in memory for the current request, so transients
 * set in one request (wp-admin) are gone in the next (the REST token endpoint). Like a drop-in that
 * skips wp-admin or loses its Redis/Memcached connection.
 *
 * @package WPSitesHQ\Tests
 */

require_once ABSPATH . WPINC . '/cache.php';
