<?php
/**
 * Dominus QuickBooks — Logger
 * Writes plugin events to /wp-content/uploads/dq-log.txt
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DQ_Logger {

    /**
     * Get the absolute path to the log file.
     *
     * @return string
     */
    private static function path() {
        $upload = wp_upload_dir();
        $path   = trailingslashit( $upload['basedir'] ) . 'dq-log.txt';

        if ( ! file_exists( $path ) ) {
            file_put_contents( $path, '[' . date('c') . "] Log initialized\n" );
        }

        return $path;
    }

    /**
     * Write a message to the log file.
     *
     * @param string $level  INFO|ERROR|WARN|DEBUG
     * @param string $msg    Message text
     * @param mixed  $context Optional context data
     */
    public static function write( $level, $msg, $context = null ) {
        $path = self::path();
        $line = sprintf( "[%s] [%s] %s", date( 'Y-m-d H:i:s' ), strtoupper( $level ), $msg );

        if ( $context !== null ) {
            if ( is_array( $context ) || is_object( $context ) ) {
                $line .= ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            } else {
                $line .= ' ' . $context;
            }
        }

        $line .= "\n";

        error_log( $line, 3, $path );
    }

    /**
     * Log an info message.
     */
    public static function info( $msg, $context = null ) {
        self::write( 'INFO', $msg, $context );
    }

    /**
     * Log a warning.
     */
    public static function warn( $msg, $context = null ) {
        self::write( 'WARN', $msg, $context );
    }

    /**
     * Log an error.
     */
    public static function error( $msg, $context = null ) {
        self::write( 'ERROR', $msg, $context );
    }

    /**
     * Log debug-level info (only if WP_DEBUG is true).
     */
    public static function debug( $msg, $context = null ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            self::write( 'DEBUG', $msg, $context );
        }
    }

    /**
     * Rotate the log (keep last 500 lines only).
     */
    public static function rotate() {
        $path = self::path();
        if ( ! file_exists( $path ) ) return;

        $lines = file( $path );
        $max   = 500;

        if ( count( $lines ) > $max ) {
            $lines = array_slice( $lines, -$max );
            file_put_contents( $path, implode( '', $lines ) );
        }
    }
}
