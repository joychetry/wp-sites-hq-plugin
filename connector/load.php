<?php
/**
 * Monks Central connector: version-wins loader.
 *
 * Every plugin that bundles the connector requires this file at load time and
 * registers its copy with monks_central_connector_register(). On plugins_loaded
 * the highest version boots and the other copies stay asleep, so any mix of
 * plugins runs exactly one connector. The first copy loaded defines these
 * functions for all of them, so their signatures never change.
 *
 * @package MonksCentral\Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'monks_central_connector_register' ) ) {

	/**
	 * Register a bundled copy of the connector.
	 *
	 * @param array $host {
	 *     The plugin that bundles the copy.
	 *
	 *     @type string $dir     Absolute path of its connector/ folder.
	 *     @type string $file    Its main plugin file.
	 *     @type string $name    Its name, shown to users.
	 *     @type string $version Its own version.
	 *     @type string $ui      Class implementing Monks_Central_Connector_UI, which renders its screens.
	 * }
	 * The copy's library version (version.php) is stored as 'connector'.
	 */
	function monks_central_connector_register( array $host ) {
		$dir               = rtrim( (string) $host['dir'], '/\\' );
		$host['dir']       = $dir;
		$host['connector'] = (string) include $dir . '/version.php';
		monks_central_connector_copies( $host );
	}

	/**
	 * Every registered copy, in registration order.
	 *
	 * @param array|null $add A copy to add.
	 * @return array[]
	 */
	function monks_central_connector_copies( $add = null ) {
		static $copies = array();
		if ( null !== $add ) {
			$copies[ $add['dir'] ] = $add;
		}
		return array_values( $copies );
	}

	/**
	 * The copy that boots: the highest version, the first registered on a tie.
	 *
	 * @param array[] $copies Registered copies.
	 * @return array|null
	 */
	function monks_central_connector_pick( array $copies ) {
		$winner = null;
		foreach ( $copies as $copy ) {
			if ( null === $winner || version_compare( $copy['connector'], $winner['connector'], '>' ) ) {
				$winner = $copy;
			}
		}
		return $winner;
	}

	/**
	 * Boot the winning copy. Runs on plugins_loaded, after every plugin registered its copy.
	 */
	function monks_central_connector_boot() {
		$copy = monks_central_connector_pick( monks_central_connector_copies() );
		if ( null === $copy || defined( 'MONKS_CENTRAL_CONNECTOR_VERSION' ) ) {
			return;
		}
		define( 'MONKS_CENTRAL_CONNECTOR_VERSION', $copy['connector'] );
		define( 'MONKS_CENTRAL_CONNECTOR_DIR', $copy['dir'] . '/' );
		require $copy['dir'] . '/connector.php';
		Monks_Central_Connector::boot( $copy );
	}
	add_action( 'plugins_loaded', 'monks_central_connector_boot', 1 );
}
