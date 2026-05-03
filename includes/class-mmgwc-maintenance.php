<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMGWC_Maintenance {
	public static function init(): void {
		add_action( 'mmgwc_rotate_logs', array( __CLASS__, 'rotate_logs' ) );
	}

	public static function activate(): void {
		self::schedule();
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled( 'mmgwc_rotate_logs' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'mmgwc_rotate_logs' );
		}
	}

	private static function schedule(): void {
		if ( ! wp_next_scheduled( 'mmgwc_rotate_logs' ) ) {
			// Run shortly after activation, then daily.
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', 'mmgwc_rotate_logs' );
		}
	}

	private static function get_log_dir(): string {
		$upload = wp_upload_dir();
		$basedir = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		if ( $basedir === '' ) {
			return '';
		}
		return trailingslashit( $basedir ) . 'wc-logs/';
	}

	public static function rotate_logs(): void {
		$log_dir = self::get_log_dir();
		if ( $log_dir === '' || ! is_dir( $log_dir ) ) {
			return;
		}

		$log_dir_real = realpath( $log_dir );
		if ( $log_dir_real === false ) {
			return;
		}
		$log_dir_real = rtrim( $log_dir_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

		// Keep last N days if debug enabled, otherwise keep a minimal amount.
		$days = 2;
		if ( class_exists( 'MMGWC_Settings' ) && MMGWC_Settings::is_debug_enabled() ) {
			$opt = MMGWC_Settings::get( 'log_retention_days', '14' );
			$opt = is_scalar( $opt ) ? (int) $opt : 14;
			$days = $opt > 0 ? $opt : 14;
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$pattern = $log_dir . '*' . MMGWC_LOG_SOURCE . '*.log';

		$files = glob( $pattern );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || ! is_file( $file ) ) {
				continue;
			}
			// Symlink guard: ensure the resolved file is actually inside the log dir.
			$real = realpath( $file );
			if ( $real === false || strpos( $real, $log_dir_real ) !== 0 ) {
				continue;
			}
			$mtime = @filemtime( $real );
			if ( ! $mtime || $mtime >= $cutoff ) {
				continue;
			}
			@unlink( $real );
		}
	}
}
