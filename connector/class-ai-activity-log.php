<?php
/**
 * AI Activity Log
 *
 * Records every classic-monks/* ability call, whichever path it took (MCP, direct
 * core REST, WP-CLI), plus other plugins' abilities run over MCP, from core's ability
 * execution hooks. Approval requests are rows here too. Shown on the Monks Central page.
 * Read-only abilities are skipped unless the cm_ai_log_reads filter says otherwise.
 *
 * @package ClassicMonks
 * @subpackage MonksCentral
 * @requires WordPress 7.1+ (wp_ability_invoked)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- cm_ is the plugin-wide prefix and these names match Classic Monks Pro, so a site keeps its connection when it moves between them.

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CM_AI_Activity_Log {

    const DB_VERSION         = '1.1.0';
    const DB_VERSION_OPTION  = 'cm_ai_log_db_version';
    const KEEP_ROWS          = 5000;
    const SUMMARY_MAX        = 500;

    /** @var array<int, array{0:string,1:int}> Rows opened by wp_ability_invoked and not yet finished. */
    private static $open = array();

    public static function init() {
        if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
            self::install();
        }
        add_action( 'wp_ability_invoked', array( __CLASS__, 'on_invoked' ), 10, 3 );
        add_action( 'wp_before_execute_ability', array( __CLASS__, 'on_before_execute' ), 10, 1 );
        add_filter( 'wp_ability_execute_result', array( __CLASS__, 'on_execute_result' ), 999, 2 );
        add_action( 'wp_after_execute_ability', array( __CLASS__, 'on_after_execute' ), 10, 3 );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cm_ai_log';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( 'CREATE TABLE ' . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source varchar(10) NOT NULL DEFAULT '',
            connection_uuid varchar(40) NOT NULL DEFAULT '',
            client_label varchar(190) NOT NULL DEFAULT '',
            ability varchar(190) NOT NULL DEFAULT '',
            input_json longtext,
            status varchar(10) NOT NULL DEFAULT '',
            result_summary text,
            undo_json longtext,
            approval_json longtext,
            PRIMARY KEY  (id),
            KEY connection_uuid (connection_uuid),
            KEY ability (ability)
        ) " . $wpdb->get_charset_collate() . ';' );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /* ------------------------------------------------------------------
     * Caller identity (D3)
     * ------------------------------------------------------------------ */

    /**
     * @return array{source:string, uuid:string, label:string}
     */
    public static function caller() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return array( 'source' => 'cli', 'uuid' => '', 'label' => 'WP-CLI' );
        }

        $oauth = class_exists( 'CM_MCP_OAuth' ) ? CM_MCP_OAuth::current() : null;
        if ( $oauth ) {
            return array( 'source' => 'mcp', 'uuid' => $oauth['id'], 'label' => (string) $oauth['label'] );
        }

        $uuid = rest_get_authenticated_app_password();
        if ( $uuid ) {
            $item = WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );
            $mcp  = $item && defined( 'CM_MCP_APP_ID' ) && CM_MCP_APP_ID === ( $item['app_id'] ?? '' );
            return array( 'source' => $mcp ? 'mcp' : 'rest', 'uuid' => (string) $uuid, 'label' => (string) ( $item['name'] ?? '' ) );
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST && is_user_logged_in() ) {
            return array( 'source' => 'rest', 'uuid' => '', 'label' => __( 'REST API', 'classic-monks' ) );
        }

        return array( 'source' => 'php', 'uuid' => '', 'label' => '' );
    }

    /* ------------------------------------------------------------------
     * Hooks
     * ------------------------------------------------------------------ */

    private static function should_log( $name, $ability ) {
        // Other plugins' abilities are recorded only when an MCP connection runs them; their own UIs are not ours to log.
        if ( 0 !== strpos( $name, 'classic-monks/' ) && ! ( function_exists( 'cm_mcp_is_mcp_credential' ) && cm_mcp_is_mcp_credential() ) ) {
            return false;
        }
        $annotations = class_exists( 'CM_MCP_Server' ) ? CM_MCP_Server::annotations( $ability ) : (array) $ability->get_meta_item( 'annotations', array() );
        /**
         * Whether read-only abilities are logged too. Off by default to keep the log about changes.
         */
        return empty( $annotations['readonly'] ) || apply_filters( 'cm_ai_log_reads', false );
    }

    public static function on_invoked( $name, $input, $ability ) {
        if ( ! self::should_log( $name, $ability ) ) {
            return;
        }

        global $wpdb;
        $caller = self::caller();

        // Pre-execution refusals (validation, permission) have no hook, so a row starts as an error.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
        $wpdb->insert( self::table(), array(
            'created_at'      => current_time( 'mysql', true ),
            'user_id'         => get_current_user_id(),
            'source'          => $caller['source'],
            'connection_uuid' => $caller['uuid'],
            'client_label'    => substr( $caller['label'], 0, 190 ),
            'ability'         => $name,
            'input_json'      => substr( (string) wp_json_encode( self::redact( $input ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 0, 8000 ),
            'status'          => 'error',
            'result_summary'  => __( 'Refused before running: invalid input or missing permission.', 'classic-monks' ),
        ) );

        self::$open[] = array( $name, (int) $wpdb->insert_id );

        // Random, not insert_id % 100: hosts with auto_increment_increment > 1 may never hit a multiple of 100.
        if ( 1 === wp_rand( 1, 100 ) ) {
            self::prune();
        }
    }

    /**
     * Record an event that is not an ability call, such as a login link being used.
     */
    public static function record( $user_id, $uuid, $label, $ability, $summary ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
        $wpdb->insert( self::table(), array(
            'created_at'      => current_time( 'mysql', true ),
            'user_id'         => (int) $user_id,
            'source'          => 'mcp',
            'connection_uuid' => (string) $uuid,
            'client_label'    => substr( (string) $label, 0, 190 ),
            'ability'         => $ability,
            'status'          => 'ok',
            'result_summary'  => self::truncate( (string) $summary ),
        ) );
    }

    /**
     * Past the gates, so the row can no longer say "refused". Core skips wp_after_execute_ability
     * when the output fails validation or the ability throws, and that text would then stay.
     */
    public static function on_before_execute( $name ) {
        global $wpdb;
        $id = self::current_id( $name );
        if ( $id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
            $wpdb->update( self::table(), array( 'result_summary' => __( 'Ran but did not finish cleanly (an error was thrown or the output failed validation). Changes may have been applied.', 'classic-monks' ) ), array( 'id' => $id ) );
        }
    }

    public static function on_execute_result( $result, $name ) {
        if ( is_wp_error( $result ) ) {
            self::finish( $name, 'error', $result->get_error_message() );
        }
        return $result;
    }

    public static function on_after_execute( $name, $input, $result ) {
        self::finish( $name, 'ok', self::summarize( $result ) );
    }

    /**
     * Record why a pre-execution gate (toggle, scope, approval) stopped the current call.
     */
    public static function mark( $name, $status, $summary ) {
        self::finish( $name, $status, $summary );
    }

    /**
     * Attach undo data to the current call's row. Free has no undo; the data is kept so
     * Classic Monks Pro can undo the change after an upgrade (same table).
     */
    public static function set_undo( $name, array $undo ) {
        global $wpdb;
        $id = self::current_id( $name );
        if ( $id ) {
            $wpdb->update( self::table(), array( 'undo_json' => wp_json_encode( $undo ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
        }
    }

    /**
     * Append a marker such as [trusted] to the current call's source label.
     */
    public static function tag( $name, $marker ) {
        global $wpdb;
        $id = self::current_id( $name );
        if ( $id ) {
            $label = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT client_label FROM %i WHERE id = %d', self::table(), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
            $wpdb->update( self::table(), array( 'client_label' => substr( trim( $label . ' ' . $marker ), 0, 190 ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
        }
    }

    /**
     * Turn the current call's row into a pending approval request.
     *
     * @return int Row ID (the op id).
     */
    public static function request_approval( $name, array $approval, $summary ) {
        global $wpdb;
        $id = self::current_id( $name );
        if ( $id ) {
            $wpdb->update( self::table(), array( 'approval_json' => wp_json_encode( $approval ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
            self::finish( $name, 'pending', $summary );
        }
        return $id;
    }

    /**
     * Record the current call on an earlier row (its approval request) instead of its own, so a request and
     * its approved retry are one entry. $id 0 drops the call's row without recording it anywhere.
     */
    public static function continue_on( $name, $id ) {
        global $wpdb;
        for ( $i = count( self::$open ) - 1; $i >= 0; $i-- ) {
            if ( self::$open[ $i ][0] !== $name ) {
                continue;
            }
            $wpdb->delete( self::table(), array( 'id' => self::$open[ $i ][1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
            if ( $id ) {
                self::$open[ $i ][1] = (int) $id;
            } else {
                array_splice( self::$open, $i, 1 );
            }
            return;
        }
    }

    private static function current_id( $name ) {
        for ( $i = count( self::$open ) - 1; $i >= 0; $i-- ) {
            if ( self::$open[ $i ][0] === $name ) {
                return self::$open[ $i ][1];
            }
        }
        return 0;
    }

    private static function finish( $name, $status, $summary ) {
        global $wpdb;

        for ( $i = count( self::$open ) - 1; $i >= 0; $i-- ) {
            if ( self::$open[ $i ][0] !== $name ) {
                continue;
            }
            $id = self::$open[ $i ][1];
            array_splice( self::$open, $i, 1 );

            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
                self::table(),
                array( 'status' => $status, 'result_summary' => self::truncate( (string) $summary ) ),
                array( 'id' => $id )
            );

            /**
             * Fires after an AI ability call is recorded.
             *
             * @param int    $id     Log row ID.
             * @param string $name   Ability name.
             * @param string $status ok|error|denied|pending.
             */
            do_action( 'cm_ai_operation_logged', $id, $name, $status );
            return;
        }
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * Mask secrets by key name or by well-known token shapes.
     */
    public static function redact( $data ) {
        if ( is_array( $data ) ) {
            foreach ( $data as $k => $v ) {
                // "auth" only as its own word (auth, auth_key, x-authorization), so author fields survive.
                $data[ $k ] = is_string( $k ) && preg_match( '/pass|secret|token|api_?key|(^|[_-])auth(entication|orization)?([_-]|$)|cookie/i', $k ) ? '[redacted]' : self::redact( $v );
            }
            return $data;
        }
        if ( is_string( $data ) ) {
            return preg_replace(
                array(
                    '/\b(eyJ[\w-]+\.[\w-]+\.[\w-]+|sk_(live|test)_\w+|sk-[\w-]{16,}|gh[pousr]_\w{20,}|AKIA[0-9A-Z]{16}|AIza[\w-]{35}|cm(at|rt|ll)_[a-f0-9]{64})\b/',
                    '/\bBearer\s+[\w.~+\/-]+=*/i',
                    '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
                    // Application password (six groups of four); a digit and a capital keep plain words out.
                    '/\b(?=[^\n]{0,28}\d)(?=[^\n]{0,28}[A-Z])[A-Za-z0-9]{4}(?: [A-Za-z0-9]{4}){5}\b/',
                ),
                array( '[redacted]', 'Bearer [redacted]', '[redacted]', '[redacted]' ),
                $data
            );
        }
        return $data;
    }

    private static function summarize( $result ) {
        if ( is_string( $result ) ) {
            return $result;
        }
        return (string) wp_json_encode( self::redact( $result ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    private static function truncate( $text ) {
        return mb_strlen( $text ) > self::SUMMARY_MAX ? mb_substr( $text, 0, self::SUMMARY_MAX - 1 ) . '…' : $text;
    }

    private static function prune() {
        global $wpdb;
        $table  = self::table();
        $cutoff = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', $table, self::KEEP_ROWS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
        if ( $cutoff ) {
            $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id <= %d', $table, $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
        }
    }

    /**
     * Newest row for a connection, for the Connections list.
     */
    public static function last_for_connection( $uuid ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT ability, status, created_at FROM %i WHERE connection_uuid = %s ORDER BY id DESC LIMIT 1', self::table(), $uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- own log table, no core API; rows change on every call
    }

    /* ------------------------------------------------------------------
     * Dashboard
     * ------------------------------------------------------------------ */

    /**
     * Log rows, newest first, for the Monks Central page.
     *
     * @param array $args filter ('' = all, or a connection id), ability, status, limit (max 100), offset.
     * @return array{total: int, rows: array[]}
     */
    public static function list_rows( array $args = array() ) {
        global $wpdb;
        $filter = (string) ( $args['filter'] ?? '' );
        $where  = array( '1=1' );
        $params = array( self::table() );

        if ( '' !== $filter ) {
            $where[]  = 'connection_uuid = %s';
            $params[] = $filter;
        }
        foreach ( array( 'ability', 'status' ) as $column ) {
            if ( ! empty( $args[ $column ] ) ) {
                $where[]  = "{$column} = %s";
                $params[] = (string) $args[ $column ];
            }
        }
        $where = implode( ' AND ', $where );
        $limit = max( 1, min( 100, (int) ( $args['limit'] ?? 100 ) ) );

        // $where joins fixed column names with %s placeholders; every value is in $params.
        $count = "SELECT COUNT(*) FROM %i WHERE {$where}";
        $total = (int) $wpdb->get_var( $wpdb->prepare( $count, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared; own log table
        $sql   = "SELECT id, created_at, user_id, source, connection_uuid, client_label, ability, status, result_summary, input_json, undo_json, approval_json FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $limit, max( 0, (int) ( $args['offset'] ?? 0 ) ) ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared; own log table

        foreach ( $rows as &$row ) {
            $user          = get_userdata( (int) $row['user_id'] );
            $row['user']   = $user ? $user->user_login : '';
            $row['when']   = sprintf(
                /* translators: %s: human time difference */
                __( '%s ago', 'classic-monks' ),
                human_time_diff( strtotime( $row['created_at'] . ' UTC' ) )
            );
            $row['tool']     = preg_replace( '#^classic-monks/#', '', $row['ability'] );
            // Requests nobody decided in time are shown as expired; the gate treats them that way too.
            $approval = json_decode( (string) $row['approval_json'], true );
            if ( in_array( $row['status'], array( 'pending', 'approved' ), true ) && is_array( $approval ) && $approval['expires'] < time() ) {
                $row['status'] = 'expired';
            }
            if ( 'pending' === $row['status'] && class_exists( 'CM_MCP_Approvals' ) ) {
                $row['approve_url'] = CM_MCP_Approvals::approve_url( $row['id'] );
            }
            unset( $row['undo_json'], $row['approval_json'] );
        }
        unset( $row );

        return array( 'total' => $total, 'rows' => $rows );
    }

}

CM_AI_Activity_Log::init();
