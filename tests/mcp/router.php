<?php
// Router for PHP's built-in server in ci.sh, like Studio's: real files and directory indexes are served as is,
// everything else goes to WordPress. Without it PHP 7.4 404s paths with a dot (/.well-known/…) instead.
$cm_root = $_SERVER['DOCUMENT_ROOT'];
$cm_path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
if ( '/' !== $cm_path && ( is_file( $cm_root . $cm_path ) || is_file( rtrim( $cm_root . $cm_path, '/' ) . '/index.php' ) ) ) {
    return false;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $cm_root . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
require $cm_root . '/index.php';
