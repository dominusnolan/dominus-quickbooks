<?php
/**
 * Dominus QuickBooks Logger
 * Writes all plugin logs to a dedicated dq-log.txt file.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class DQ_Logger {

    private static $log_file = '';

    /**
     * Initialize log path
     */
    private static function get_log_path() {
        if ( ! self::$log_file ) {
            $upload_dir = wp_upload_dir();
            $path = trailingslashit( $upload_dir['basedir'] ) . 'dq-log.txt';
            self::$log_file = $path;
        }
        return self::$log_file;
    }

    /**
     * Write message to dq-log.txt
     */
    public static function log( $message, $context = '' ) {
        $file = self::get_log_path();
        $timestamp = gmdate( 'Y-m-d H:i:s' );

        // Prefix context like [INVOICE UPDATE] or [AUTH]
        $prefix = $context ? '[' . strtoupper( $context ) . '] ' : '';

        $entry = sprintf( "[%s] %s%s\n", $timestamp, $prefix, is_string( $message ) ? $message : print_r( $message, true ) );

        // Try writing to dq-log.txt
        $result = @file_put_contents( $file, $entry, FILE_APPEND | LOCK_EX );

        // Fallback to default error_log if write fails
        if ( false === $result ) {
            error_log( "DQ_LOG_FALLBACK: " . $entry );
        }
    }

    /**
     * Clear log file manually (for admin tools)
     */
    public static function clear() {
        $file = self::get_log_path();
        if ( file_exists( $file ) ) {
            @unlink( $file );
            self::log( 'dq-log.txt cleared manually.', 'SYSTEM' );
        }
    }

    /**
     * Get current log file path (for admin links)
     */
    public static function get_file_url() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['baseurl'] ) . 'dq-log.txt';
    }
}
