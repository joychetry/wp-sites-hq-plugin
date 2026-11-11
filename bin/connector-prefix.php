<?php
/**
 * Renames the connector library's global symbols from the cm_ / CM_ and monks_central_ / Monks_Central_ /
 * MONKS_CENTRAL_ development-build prefixes to wpshq_ / WPSHQ_, with PHP's tokenizer,
 * so stored and wire names (options, meta, tables, REST routes, ability names, cron hooks, nonces, error codes) that
 * share the old prefix keep their exact text.
 *
 * Run: php bin/connector-prefix.php map <connector-dir> <map.json>    collect the symbols the connector declares and
 *                                                                     the hooks it fires; write the old => new map
 *                                                                     (entries already in map.json are kept)
 *      php bin/connector-prefix.php apply <map.json> <path>...        rename them in every .php, .sh, .js and .md
 *                                                                     file under each path; prints what changed
 *      php bin/connector-prefix.php --self-test
 *
 * In PHP files only code tokens change: function, class and constant names (not methods, properties or class
 * constants), string literals that name a renamed function or class (callbacks, class_exists()), the hook argument of
 * the hook functions when it names a renamed hook, and comments. In other files (shell suites that run PHP through
 * wp eval, docs) whole-word matches change, except the names listed in the map's "ambiguous" list, which are also
 * stored names and need a look by hand.
 *
 * @package WPSitesHQ
 */

// Hook functions => zero-based position of the hook name argument.
const WPSHQ_PREFIX_HOOK_ARG = array(
	'add_action'               => 0,
	'add_filter'               => 0,
	'remove_action'            => 0,
	'remove_filter'            => 0,
	'remove_all_actions'       => 0,
	'remove_all_filters'       => 0,
	'has_action'               => 0,
	'has_filter'               => 0,
	'do_action'                => 0,
	'do_action_ref_array'      => 0,
	'do_action_deprecated'     => 0,
	'apply_filters'            => 0,
	'apply_filters_ref_array'  => 0,
	'apply_filters_deprecated' => 0,
	'did_action'               => 0,
	'did_filter'               => 0,
	'doing_action'             => 0,
	'doing_filter'             => 0,
	'wp_next_scheduled'        => 0,
	'wp_clear_scheduled_hook'  => 0,
	'wp_unschedule_hook'       => 0,
	'wp_schedule_single_event' => 1,
	'wp_unschedule_event'      => 1,
	'wp_schedule_event'        => 2,
);

const WPSHQ_PREFIX_FIRES = array( 'do_action', 'do_action_ref_array', 'apply_filters', 'apply_filters_ref_array' );

// Old prefixes, matched case-insensitively.
const WPSHQ_PREFIX_OLD = '/^(cm_|monks_central_)/i';

/**
 * New name for an old one: cm_x / monks_central_x => wpshq_x, CM_X / Monks_Central_X / MONKS_CENTRAL_X => WPSHQ_X.
 *
 * @param string $old Old name.
 * @return string
 */
function wpshq_prefix_new_name( string $old ): string {
	preg_match( WPSHQ_PREFIX_OLD, $old, $m );
	return ( ctype_upper( $old[0] ) ? 'WPSHQ_' : 'wpshq_' ) . substr( $old, strlen( $m[0] ) );
}

/**
 * Index of the next (+1) or previous (-1) token that is not whitespace or a comment.
 *
 * @param array $t   Tokens.
 * @param int   $i   Start index.
 * @param int   $dir Direction.
 * @return int -1 when there is none.
 */
function wpshq_prefix_sig( array $t, int $i, int $dir ): int {
	for ( $i += $dir; $i >= 0 && $i < count( $t ); $i += $dir ) {
		if ( ! is_array( $t[ $i ] ) || ! in_array( $t[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			return $i;
		}
	}
	return -1;
}

/**
 * The call a token is an argument of: [function name, argument index], or null.
 *
 * @param array $t Tokens.
 * @param int   $i Token index.
 * @return array|null
 */
function wpshq_prefix_enclosing_call( array $t, int $i ) {
	$depth = 0;
	$arg   = 0;
	for ( $j = $i - 1; $j >= 0; $j-- ) {
		$c = is_array( $t[ $j ] ) ? $t[ $j ][1] : $t[ $j ];
		if ( in_array( $c, array( ')', ']', '}' ), true ) ) {
			++$depth;
		} elseif ( in_array( $c, array( '[', '{' ), true ) || ( is_array( $t[ $j ] ) && in_array( $t[ $j ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
			if ( 0 === $depth ) {
				return null;
			}
			--$depth;
		} elseif ( '(' === $c ) {
			if ( 0 === $depth ) {
				$p = wpshq_prefix_sig( $t, $j, -1 );
				if ( $p >= 0 && is_array( $t[ $p ] ) && in_array( $t[ $p ][0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
					return array( strtolower( ltrim( $t[ $p ][1], '\\' ) ), $arg );
				}
				return null;
			}
			--$depth;
		} elseif ( ',' === $c && 0 === $depth ) {
			++$arg;
		} elseif ( ';' === $c ) {
			return null;
		}
	}
	return null;
}

/**
 * Whether a string is the key of $GLOBALS[ '...' ].
 *
 * @param array $t Tokens.
 * @param int   $i Token index of the string.
 * @return bool
 */
function wpshq_prefix_is_globals_key( array $t, int $i ): bool {
	$p = wpshq_prefix_sig( $t, $i, -1 );
	$q = $p >= 0 && '[' === $t[ $p ] ? wpshq_prefix_sig( $t, $p, -1 ) : -1;
	return $q >= 0 && is_array( $t[ $q ] ) && T_VARIABLE === $t[ $q ][0] && '$GLOBALS' === $t[ $q ][1];
}

/**
 * Whether a string is the method half of an array( Class, 'method' ) callable.
 *
 * @param array $t Tokens.
 * @param int   $i Token index of the string.
 * @return bool
 */
function wpshq_prefix_is_method_name( array $t, int $i ): bool {
	$p = wpshq_prefix_sig( $t, $i, -1 );
	if ( $p < 0 || ',' !== $t[ $p ] ) {
		return false;
	}
	$q = wpshq_prefix_sig( $t, $p, -1 );
	if ( $q < 0 || ! is_array( $t[ $q ] ) ) {
		return false;
	}
	list( $id, $text ) = $t[ $q ];
	return T_CLASS_C === $id || '$this' === $text || ( T_STRING === $id && 'class' === strtolower( $text ) ) || ( T_CONSTANT_ENCAPSED_STRING === $id && preg_match( '/^.[A-Z]/', $text ) );
}

/**
 * Walk a file's tokens, tracking whether each one sits inside a class body.
 *
 * @param array    $t     Tokens.
 * @param callable $visit fn( int $i, bool $in_class ).
 */
function wpshq_prefix_walk( array $t, callable $visit ) {
	$depth   = 0;
	$classes = array(); // Brace depths at which class bodies close.
	$pending = false;
	foreach ( $t as $i => $tok ) {
		$c = is_array( $tok ) ? $tok[1] : $tok;
		if ( is_array( $tok ) && in_array( $tok[0], array( T_CLASS, T_INTERFACE, T_TRAIT, defined( 'T_ENUM' ) ? T_ENUM : -1 ), true ) ) {
			$p = wpshq_prefix_sig( $t, $i, -1 );
			// Foo::class is not a declaration.
			if ( $p < 0 || ! is_array( $t[ $p ] ) || T_DOUBLE_COLON !== $t[ $p ][0] ) {
				$pending = true;
			}
		}
		if ( '{' === $c || ( is_array( $tok ) && in_array( $tok[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
			if ( $pending ) {
				$classes[] = $depth;
				$pending   = false;
			}
			++$depth;
		} elseif ( '}' === $c ) {
			--$depth;
			if ( $classes && end( $classes ) === $depth ) {
				array_pop( $classes );
			}
		}
		$visit( $i, (bool) $classes );
	}
}

/**
 * Collect the old-prefixed symbols a set of files declares, and the hooks they fire.
 *
 * @param string[] $files PHP files.
 * @return array
 */
function wpshq_prefix_build_map( array $files ): array {
	$map  = array(
		'functions' => array(),
		'classes'   => array(),
		'constants' => array(),
		'globals'   => array(),
		'hooks'     => array(),
	);
	$kept = array();
	foreach ( $files as $file ) {
		$t = token_get_all( (string) file_get_contents( $file ) );
		wpshq_prefix_walk(
			$t,
			function ( $i, $in_class ) use ( $t, &$map, &$kept ) {
				$tok = $t[ $i ];
				if ( ! is_array( $tok ) ) {
					return;
				}
				$n    = wpshq_prefix_sig( $t, $i, 1 );
				$next = $n >= 0 && is_array( $t[ $n ] ) ? $t[ $n ] : null;
				if ( T_FUNCTION === $tok[0] && ! $in_class && $next && T_STRING === $next[0] ) {
					$type = 'functions';
				} elseif ( in_array( $tok[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) && $next && T_STRING === $next[0] ) {
					$type = 'classes';
				} elseif ( T_CONST === $tok[0] && ! $in_class && $next && T_STRING === $next[0] ) {
					$type = 'constants';
				} elseif ( T_GLOBAL === $tok[0] && $next && T_VARIABLE === $next[0] ) {
					$type = 'globals';
				} elseif ( T_CONSTANT_ENCAPSED_STRING === $tok[0] ) {
					$call = wpshq_prefix_enclosing_call( $t, $i );
					$name = substr( $tok[1], 1, -1 );
					if ( wpshq_prefix_is_globals_key( $t, $i ) ) {
						$next = array( T_VARIABLE, $name );
						$type = 'globals';
					} elseif ( $call && 'define' === $call[0] && 0 === $call[1] ) {
						$next = array( T_STRING, $name );
						$type = 'constants';
					} elseif ( $call && in_array( $call[0], WPSHQ_PREFIX_FIRES, true ) && 0 === $call[1] && wpshq_prefix_sig( $t, $i, 1 ) >= 0 && in_array( $t[ wpshq_prefix_sig( $t, $i, 1 ) ], array( ',', ')' ), true ) ) {
						$next = array( T_STRING, $name );
						$type = 'hooks';
					} else {
						return;
					}
				} else {
					return;
				}
				$name = 'globals' === $type ? ltrim( $next[1], '$' ) : $next[1];
				if ( preg_match( WPSHQ_PREFIX_OLD, $name ) ) {
					$map[ $type ][ $name ] = wpshq_prefix_new_name( $name );
				} elseif ( 'hooks' !== $type && ! preg_match( '/^wpshq_/i', $name ) ) {
					$kept[ $name ] = $type; // Already prefixed (or not ours): listed so a person can check.
				}
			}
		);
	}
	foreach ( $map as &$names ) {
		ksort( $names );
	}
	ksort( $kept );
	$map['kept_prefixed'] = $kept;
	return $map;
}

/**
 * Rename the map's symbols in PHP source.
 *
 * @param string $code PHP source.
 * @param array  $map  Map from wpshq_prefix_build_map().
 * @param array  $log  Collects [line, kind, old, context] per change.
 * @return string
 */
function wpshq_prefix_apply_php( string $code, array $map, array &$log ): string {
	$symbols = $map['functions'] + $map['classes'] + $map['constants'];
	$words   = $symbols + $map['hooks'];
	$t       = token_get_all( $code );
	$out     = array();
	wpshq_prefix_walk(
		$t,
		function ( $i, $in_class ) use ( $t, $map, $symbols, $words, &$out, &$log ) {
			$tok = $t[ $i ];
			if ( ! is_array( $tok ) ) {
				$out[] = $tok;
				return;
			}
			list( $id, $text, $line ) = $tok;
			switch ( $id ) {
				case T_STRING:
				case T_NAME_FULLY_QUALIFIED:
					$bare = ltrim( $text, '\\' );
					if ( ! isset( $symbols[ $bare ] ) ) {
						break;
					}
					$p    = wpshq_prefix_sig( $t, $i, -1 );
					$prev = $p >= 0 && is_array( $t[ $p ] ) ? $t[ $p ][0] : null;
					// Methods, properties, class constants and members declared in a class keep their names.
					if ( in_array( $prev, array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) || ( $in_class && in_array( $prev, array( T_FUNCTION, T_CONST ), true ) ) ) {
						break;
					}
					$text  = str_replace( $bare, $symbols[ $bare ], $text );
					$log[] = array( $line, 'code', $bare, '' );
					break;
				case T_VARIABLE:
					if ( isset( $map['globals'][ substr( $text, 1 ) ] ) ) {
						$log[] = array( $line, 'global', $text, '' );
						$text  = '$' . $map['globals'][ substr( $text, 1 ) ];
					}
					break;
				case T_CONSTANT_ENCAPSED_STRING:
					$q     = $text[0];
					$value = substr( $text, 1, -1 );
					$call  = wpshq_prefix_enclosing_call( $t, $i );
					$class = strstr( $value, '::', true );
					if ( wpshq_prefix_is_globals_key( $t, $i ) ) {
						if ( isset( $map['globals'][ $value ] ) ) {
							$text  = $q . $map['globals'][ $value ] . $q;
							$log[] = array( $line, 'global', $value, '$GLOBALS' );
						}
					} elseif ( $call && isset( WPSHQ_PREFIX_HOOK_ARG[ $call[0] ] ) && WPSHQ_PREFIX_HOOK_ARG[ $call[0] ] === $call[1] ) {
						if ( isset( $map['hooks'][ $value ] ) ) {
							$text  = $q . $map['hooks'][ $value ] . $q;
							$log[] = array( $line, 'hook', $value, $call[0] );
						}
					} elseif ( $call && in_array( $call[0], array( 'define', 'defined', 'constant' ), true ) && 0 === $call[1] && isset( $map['constants'][ $value ] ) ) {
						$text  = $q . $map['constants'][ $value ] . $q;
						$log[] = array( $line, 'string', $value, $call[0] . '#0' );
					} elseif ( ! $call && wpshq_prefix_is_method_name( $t, $i ) ) {
						break; // array( Class, 'method' ): a method keeps its name.
					} elseif ( isset( $map['functions'][ $value ] ) || isset( $map['classes'][ $value ] ) ) {
						$text  = $q . $symbols[ $value ] . $q;
						$log[] = array( $line, 'string', $value, $call ? $call[0] . '#' . $call[1] : '' );
					} elseif ( false !== $class && isset( $map['classes'][ $class ] ) ) {
						$text  = $q . $map['classes'][ $class ] . substr( $value, strlen( $class ) ) . $q;
						$log[] = array( $line, 'string', $value, $call ? $call[0] . '#' . $call[1] : '' );
					}
					break;
				case T_COMMENT:
				case T_DOC_COMMENT:
					$text = wpshq_prefix_apply_words( $text, $words, $log, $line, 'comment' );
					break;
				case T_ENCAPSED_AND_WHITESPACE:
				case T_INLINE_HTML:
					if ( wpshq_prefix_apply_words( $text, $words, $unused, $line, '' ) !== $text ) {
						$log[] = array( $line, 'REVIEW', trim( substr( $text, 0, 80 ) ), 'name inside an interpolated string or HTML: not changed' );
					}
					break;
			}
			$out[] = $text;
		}
	);
	return implode( '', $out );
}

/**
 * Whole-word rename in free text.
 *
 * @param string $text  Text.
 * @param array  $words Old => new.
 * @param array  $log   Change log.
 * @param int    $line  Line for the log.
 * @param string $kind  Log kind; '' logs nothing.
 * @return string
 */
function wpshq_prefix_apply_words( string $text, array $words, &$log, int $line, string $kind ): string {
	if ( ! $words ) {
		return $text;
	}
	$names = array_keys( $words );
	usort(
		$names,
		function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);
	$re = '/(?<![A-Za-z0-9_$\\\\-])(' . implode( '|', array_map( 'preg_quote', $names ) ) . ')(?![A-Za-z0-9_-])/';
	return preg_replace_callback(
		$re,
		function ( $m ) use ( $words, &$log, $line, $kind ) {
			if ( $kind ) {
				$log[] = array( $line, $kind, $m[1], '' );
			}
			return $words[ $m[1] ];
		},
		$text
	);
}

/**
 * Files to process under a path.
 *
 * @param string $path File or directory.
 * @return string[]
 */
function wpshq_prefix_files( string $path ): array {
	if ( is_file( $path ) ) {
		return array( $path );
	}
	$files = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( preg_match( '/\.(php|sh|js|md)$/', $f->getFilename() ) && ! preg_match( '#/(vendor|node_modules|build|dist|\.git)/#', $f->getPathname() ) ) {
			$files[] = $f->getPathname();
		}
	}
	sort( $files );
	return $files;
}

/**
 * Self-test on a small sample.
 */
function wpshq_prefix_self_test() {
	$src = <<<'PHP'
<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- old reason.
const CM_TTL = 5;
// Calls cm_go() for CM_Thing.
function cm_go() { return CM_TTL; }
class CM_Thing { const CM_TTL = 1; public function cm_go() { return self::CM_TTL; } }
add_action( 'cm_go', 'cm_go' );
wp_schedule_single_event( time(), 'cm_go' );
add_filter( 'cm_hook', array( 'CM_Thing', 'cm_go' ) );
$x = apply_filters( 'cm_hook', 'CM_Thing::cm_go' );
update_option( 'cm_hook', 1 );
$y = new CM_Thing(); $y->cm_go(); \cm_go(); class_exists( 'CM_Thing' ); defined( 'CM_TTL' ); defined( 'CM_OTHER' );
$GLOBALS['cm_level'] = 1; global $cm_list; $cm_list = $GLOBALS['cm_level']; $cm_local = 2;
define( 'MONKS_CENTRAL_X_DIR', 1 ); interface Monks_Central_X_UI {} function monks_central_x_go() { do_action( 'monks_central_x_ready', MONKS_CENTRAL_X_DIR ); }
add_action( 'monks_central_x_ready', 'monks_central_x_go' ); is_subclass_of( 'A', 'Monks_Central_X_UI' ); class B implements \Monks_Central_X_UI {}
PHP;
	$want = <<<'PHP'
<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- old reason.
const WPSHQ_TTL = 5;
// Calls wpshq_go() for WPSHQ_Thing.
function wpshq_go() { return WPSHQ_TTL; }
class WPSHQ_Thing { const CM_TTL = 1; public function cm_go() { return self::CM_TTL; } }
add_action( 'cm_go', 'wpshq_go' );
wp_schedule_single_event( time(), 'cm_go' );
add_filter( 'wpshq_hook', array( 'WPSHQ_Thing', 'cm_go' ) );
$x = apply_filters( 'wpshq_hook', 'WPSHQ_Thing::cm_go' );
update_option( 'cm_hook', 1 );
$y = new WPSHQ_Thing(); $y->cm_go(); \wpshq_go(); class_exists( 'WPSHQ_Thing' ); defined( 'WPSHQ_TTL' ); defined( 'CM_OTHER' );
$GLOBALS['wpshq_level'] = 1; global $wpshq_list; $wpshq_list = $GLOBALS['wpshq_level']; $cm_local = 2;
define( 'WPSHQ_X_DIR', 1 ); interface WPSHQ_X_UI {} function wpshq_x_go() { do_action( 'wpshq_x_ready', WPSHQ_X_DIR ); }
add_action( 'wpshq_x_ready', 'wpshq_x_go' ); is_subclass_of( 'A', 'WPSHQ_X_UI' ); class B implements \WPSHQ_X_UI {}
PHP;
	$tmp = tempnam( sys_get_temp_dir(), 'wpshq' );
	file_put_contents( $tmp, $src );
	$map = wpshq_prefix_build_map( array( $tmp ) );
	unlink( $tmp );
	$log = array();
	$got = wpshq_prefix_apply_php( $src, $map, $log );
	if ( $got !== $want ) {
		fwrite( STDERR, "self-test failed:\n$got\n" );
		exit( 1 );
	}
	echo "self-test ok\n";
}

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
$cmd = $argv[1] ?? '';
if ( '--self-test' === $cmd ) {
	wpshq_prefix_self_test();
	exit( 0 );
}
if ( 'map' === $cmd && isset( $argv[2], $argv[3] ) ) {
	$php = array_values( array_filter( wpshq_prefix_files( $argv[2] ), fn( $f ) => '.php' === substr( $f, -4 ) && false === strpos( $f, '/tests/' ) ) );
	$map = wpshq_prefix_build_map( $php );
	// Keep what an earlier run mapped (the source no longer declares those old names).
	$prev = is_file( $argv[3] ) ? (array) json_decode( (string) file_get_contents( $argv[3] ), true ) : array();
	foreach ( array( 'functions', 'classes', 'constants', 'globals', 'hooks' ) as $type ) {
		$map[ $type ] += (array) ( $prev[ $type ] ?? array() );
		ksort( $map[ $type ] );
	}
	// Function names that are also cron hooks or other stored names: renamed in PHP code, left alone in free text.
	$stored = array();
	foreach ( $php as $f ) {
		$t = token_get_all( (string) file_get_contents( $f ) );
		foreach ( $t as $i => $tok ) {
			if ( is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0] ) {
				$v    = substr( $tok[1], 1, -1 );
				$call = wpshq_prefix_enclosing_call( $t, $i );
				if ( ( isset( $map['functions'][ $v ] ) || isset( $map['classes'][ $v ] ) ) && $call && isset( WPSHQ_PREFIX_HOOK_ARG[ $call[0] ] ) && WPSHQ_PREFIX_HOOK_ARG[ $call[0] ] === $call[1] && ! isset( $map['hooks'][ $v ] ) ) {
					$stored[ $v ] = $call[0];
				}
			}
		}
	}
	$stored += (array) ( $prev['ambiguous'] ?? array() );
	ksort( $stored );
	$map['ambiguous'] = $stored;
	file_put_contents( $argv[3], json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	foreach ( $map as $type => $names ) {
		printf( "%-14s %d\n", $type, count( $names ) );
	}
	exit( 0 );
}
if ( 'apply' === $cmd && isset( $argv[2], $argv[3] ) ) {
	$map   = json_decode( (string) file_get_contents( $argv[2] ), true );
	$words = array_diff_key( $map['functions'] + $map['classes'] + $map['constants'] + $map['hooks'], $map['ambiguous'] );
	foreach ( array_slice( $argv, 3 ) as $path ) {
		foreach ( wpshq_prefix_files( $path ) as $f ) {
			$code = (string) file_get_contents( $f );
			$log  = array();
			if ( '.php' === substr( $f, -4 ) ) {
				$new = wpshq_prefix_apply_php( $code, $map, $log );
			} else {
				$new = '';
				foreach ( preg_split( '/(?<=\n)/', $code ) as $n => $l ) {
					$new .= wpshq_prefix_apply_words( $l, $words, $log, $n + 1, 'text' );
				}
				foreach ( array_keys( $map['ambiguous'] ) as $name ) {
					if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\b/', $new ) ) {
						$log[] = array( 0, 'REVIEW', $name, 'function and stored name: not changed in free text' );
					}
				}
			}
			if ( $new !== $code ) {
				file_put_contents( $f, $new );
			}
			foreach ( $log as $e ) {
				printf( "%s:%d\t%s\t%s\t%s\n", $f, $e[0], $e[1], $e[2], $e[3] );
			}
		}
	}
	exit( 0 );
}
fwrite( STDERR, "Run: php bin/connector-prefix.php map <dir> <map.json> | apply <map.json> <path>... | --self-test\n" );
exit( 2 );
