<?php
/**
 * Plugin settings, stored in one non-autoloaded option.
 *
 * @package MonksCentral
 */

namespace MonksCentral;

/**
 * Reads, sanitizes and saves the `monks_central_settings` option.
 *
 * The fields() list is the single source of truth: defaults, sanitizing and the REST schema all come from it.
 * To add a setting, add a field here and a matching control on the admin screen.
 */
final class Settings {

	public const OPTION = 'monks_central_settings';

	/**
	 * Setting fields as JSON Schema. Supported types: boolean, integer, string, and the
	 * `modules` object (module id => enabled).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		return array(
			'modules' => array(
				'type'                 => 'object',
				'description'          => 'Enabled state per module id.',
				// Modules listed here are on by default.
				'default'              => array(),
				'additionalProperties' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * Per-request cache of the merged settings, shared by every instance.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Default values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array_map( static fn( array $field ) => $field['default'], self::fields() );
	}

	/**
	 * All settings, stored values over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * A string setting.
	 *
	 * @param string $key Field name.
	 */
	public function get_string( string $key ): string {
		$value = $this->all()[ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * An integer setting.
	 *
	 * @param string $key Field name.
	 */
	public function get_int( string $key ): int {
		$value = $this->all()[ $key ] ?? 0;
		return is_int( $value ) ? $value : 0;
	}

	/**
	 * A boolean setting.
	 *
	 * @param string $key Field name.
	 */
	public function get_bool( string $key ): bool {
		return true === ( $this->all()[ $key ] ?? false );
	}

	/**
	 * Whether a module is switched on in settings.
	 *
	 * @param string $id Module id.
	 */
	public function module_enabled( string $id ): bool {
		$modules = $this->all()['modules'];
		return is_array( $modules ) && true === ( $modules[ $id ] ?? false );
	}

	/**
	 * Merge a partial update into the stored settings and save.
	 *
	 * @param array<string, mixed> $input Raw values, any subset of fields().
	 * @return array<string, mixed> The saved settings.
	 */
	public function update( array $input ): array {
		$current = $this->all();
		if ( isset( $input['modules'] ) && is_array( $input['modules'] ) && is_array( $current['modules'] ) ) {
			$input['modules'] += $current['modules'];
		}
		$clean = self::sanitize( $input + $current );
		update_option( self::OPTION, $clean, false );
		self::$cache = $clean;
		return $clean;
	}

	/**
	 * Clean raw input into a complete, valid settings array. Unknown keys are dropped,
	 * invalid values fall back to the default, numbers are clamped to their range.
	 *
	 * @param array<mixed> $input Raw values.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$clean = array();
		foreach ( self::fields() as $key => $field ) {
			$value         = $input[ $key ] ?? null;
			$clean[ $key ] = match ( $field['type'] ) {
				'object'  => is_array( $value ) ? self::sanitize_modules( $value ) : $field['default'],
				'boolean' => is_bool( $value ) ? $value : $field['default'],
				'integer' => is_numeric( $value )
					? max( $field['minimum'] ?? PHP_INT_MIN, min( $field['maximum'] ?? PHP_INT_MAX, (int) $value ) )
					: $field['default'],
				'string'  => is_string( $value )
					? mb_substr( sanitize_text_field( $value ), 0, $field['maxLength'] ?? 1000 )
					: $field['default'],
				default   => $field['default'],
			};
		}
		return $clean;
	}

	/**
	 * Forget the cached settings. For tests and code that writes the option directly.
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Keep only `module_id => bool` pairs.
	 *
	 * @param array<mixed> $value Raw modules map.
	 * @return array<string, bool>
	 */
	private static function sanitize_modules( array $value ): array {
		$modules = array();
		foreach ( $value as $id => $enabled ) {
			$id = sanitize_key( (string) $id );
			if ( '' !== $id ) {
				$modules[ $id ] = (bool) $enabled;
			}
		}
		return $modules;
	}
}
