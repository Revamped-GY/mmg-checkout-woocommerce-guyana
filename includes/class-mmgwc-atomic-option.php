<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides database-level option reservations and owned expiring locks.
 *
 * WordPress add_option() is not an insert-only primitive under concurrency.
 * These operations use the unique option_name index directly and do not rely
 * on the options cache for payment safety decisions.
 */
final class MMGWC_Atomic_Option {
	/**
	 * Reserve one option name for one owner.
	 *
	 * An existing reservation is accepted only when it already belongs to the
	 * same owner. Reservations are intentionally persistent until uninstall.
	 */
	public static function reserve( string $key, string $owner ): bool {
		if ( $key === '' || $owner === '' ) {
			return false;
		}

		if ( self::insert_if_absent( $key, $owner ) ) {
			return true;
		}

		$stored_owner = self::read_value( $key );
		return $stored_owner !== null && hash_equals( $stored_owner, $owner );
	}

	/**
	 * Read an immutable reservation without consulting the options cache.
	 */
	public static function reserved_value( string $key ): ?string {
		return $key === '' ? null : self::read_value( $key );
	}

	/**
	 * Delete a reservation only when its complete stored value still matches.
	 */
	public static function delete_if_owned( string $key, string $owned_value ): void {
		self::release_lock( $key, $owned_value );
	}

	/**
	 * Replace a reservation only when its complete stored value still matches.
	 *
	 * This compare-and-swap operation lets a durable queue advance its retry
	 * state without allowing a stale worker to overwrite a newer value.
	 */
	public static function replace_reserved_value( string $key, string $old_value, string $new_value ): bool {
		if ( $key === '' || $old_value === '' || $new_value === '' ) {
			return false;
		}
		return self::replace_if_owned( $key, $old_value, $new_value );
	}

	/**
	 * Find a bounded, offset-aware set of reservation names for recovery after reactivation.
	 *
	 * @return string[]|null Null means the database scan could not be completed.
	 */
	public static function keys_with_prefix( string $prefix, int $limit = 20, int $offset = 0 ): ?array {
		global $wpdb;
		if ( $prefix === '' || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'esc_like' ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return null;
		}
		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d OFFSET %d",
				$wpdb->esc_like( $prefix ) . '%',
				$limit,
				$offset
			)
		);
		return is_array( $keys ) ? array_values( array_filter( array_map( 'strval', $keys ) ) ) : null;
	}

	/**
	 * Acquire an expiring lock and return the exact owned value.
	 *
	 * @return string Empty when another request owns a current lock.
	 */
	public static function acquire_lock( string $key, int $ttl_seconds ): string {
		if ( $key === '' ) {
			return '';
		}

		$now = time();
		$value = $now . '|' . self::random_token();
		if ( self::insert_if_absent( $key, $value ) ) {
			return $value;
		}

		$current = self::read_value( $key );
		if ( $current === null ) {
			// The prior row may have disappeared between the insert and read.
			return self::insert_if_absent( $key, $value ) ? $value : '';
		}

		$separator = strpos( $current, '|' );
		$created_at = (int) ( $separator === false ? $current : substr( $current, 0, $separator ) );
		if ( $created_at <= 0 || ( $now - $created_at ) <= max( 1, $ttl_seconds ) ) {
			return '';
		}

		return self::replace_if_owned( $key, $current, $value ) ? $value : '';
	}

	/**
	 * Renew a lock only while the caller still owns its exact stored value.
	 *
	 * @return string The new owned value, or an empty string after ownership loss.
	 */
	public static function renew_lock( string $key, string $owned_value ): string {
		if ( $key === '' || $owned_value === '' ) {
			return '';
		}
		// Use a fresh token as well as a fresh timestamp. Two renewals can occur
		// within one second and MySQL reports zero changed rows for equal values.
		$new_value = time() . '|' . self::random_token();
		return self::replace_if_owned( $key, $owned_value, $new_value ) ? $new_value : '';
	}

	/**
	 * Release a lock only when the caller still owns its exact value.
	 */
	public static function release_lock( string $key, string $owned_value ): void {
		if ( $key === '' || $owned_value === '' ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				$owned_value
			)
		);
		if ( $deleted === 1 ) {
			self::clear_option_cache( $key );
		}
	}

	private static function insert_if_absent( string $key, string $value ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return false;
		}

		// option_name has a unique database index. INSERT IGNORE returns one only
		// for the request that created the row and zero for a duplicate name.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$key,
				$value
			)
		);
		if ( $inserted === 1 ) {
			self::clear_option_cache( $key );
			return true;
		}
		return false;
	}

	private static function replace_if_owned( string $key, string $old_value, string $new_value ): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return false;
		}

		// The old value is part of the WHERE clause, which prevents two stale-lock
		// contenders from both taking ownership.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				$key,
				$old_value
			)
		);
		if ( $updated === 1 ) {
			self::clear_option_cache( $key );
			return true;
		}
		return false;
	}

	private static function read_value( string $key ): ?string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			)
		);
		return $value === null ? null : (string) $value;
	}

	private static function clear_option_cache( string $key ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	private static function random_token(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $exception ) {
			return str_replace( '.', '', uniqid( 'mmgwc', true ) );
		}
	}
}
