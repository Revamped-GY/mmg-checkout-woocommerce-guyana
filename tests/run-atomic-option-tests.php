<?php

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

final class MMGWC_Test_WPDB {
	public $options = 'wp_options';
	public $rows = array();
	public $used_insert_ignore = false;
	public $fail_scan = false;

	public function prepare( string $query, ...$args ): array {
		return array( 'query' => $query, 'args' => $args );
	}

	public function query( array $statement ) {
		$query = ltrim( $statement['query'] );
		$args = $statement['args'];
		if ( stripos( $query, 'INSERT IGNORE' ) === 0 ) {
			$this->used_insert_ignore = true;
			$key = (string) $args[0];
			if ( array_key_exists( $key, $this->rows ) ) {
				return 0;
			}
			$this->rows[ $key ] = (string) $args[1];
			return 1;
		}
		if ( stripos( $query, 'UPDATE' ) === 0 ) {
			$new_value = (string) $args[0];
			$key = (string) $args[1];
			$old_value = (string) $args[2];
			if ( ! array_key_exists( $key, $this->rows ) || $this->rows[ $key ] !== $old_value ) {
				return 0;
			}
			$this->rows[ $key ] = $new_value;
			return 1;
		}
		if ( stripos( $query, 'DELETE' ) === 0 ) {
			$key = (string) $args[0];
			$owned_value = (string) $args[1];
			if ( ! array_key_exists( $key, $this->rows ) || $this->rows[ $key ] !== $owned_value ) {
				return 0;
			}
			unset( $this->rows[ $key ] );
			return 1;
		}
		return false;
	}

	public function get_var( array $statement ) {
		$key = (string) $statement['args'][0];
		return $this->rows[ $key ] ?? null;
	}

	public function esc_like( string $value ): string { return $value; }

	public function get_col( array $statement ) {
		if ( $this->fail_scan ) {
			return null;
		}
		$pattern = rtrim( (string) $statement['args'][0], '%' );
		$limit = (int) $statement['args'][1];
		$offset = (int) ( $statement['args'][2] ?? 0 );
		return array_slice( array_values( array_filter( array_keys( $this->rows ), static function( string $key ) use ( $pattern ): bool {
			return strpos( $key, $pattern ) === 0;
		} ) ), $offset, $limit );
	}
}

$wpdb = new MMGWC_Test_WPDB();

function wp_cache_delete( string $key, string $group ): bool { return true; }

require dirname( __DIR__ ) . '/includes/class-mmgwc-atomic-option.php';

$tests = 0;
$failures = 0;

function atomic_check( bool $condition, string $message ): void {
	global $tests, $failures;
	$tests++;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

atomic_check( MMGWC_Atomic_Option::reserve( 'claim', 'order-42' ), 'The first owner creates a persistent claim.' );
atomic_check( MMGWC_Atomic_Option::reserve( 'claim', 'order-42' ), 'The same owner can verify its existing claim idempotently.' );
atomic_check( ! MMGWC_Atomic_Option::reserve( 'claim', 'order-43' ), 'A second owner cannot overwrite an existing claim.' );
atomic_check( $wpdb->rows['claim'] === 'order-42', 'A rejected claim leaves the original owner unchanged.' );
atomic_check( $wpdb->used_insert_ignore, 'Claims use a database insert-only operation.' );
atomic_check( MMGWC_Atomic_Option::replace_reserved_value( 'claim', 'order-42', 'order-42-retry-1' ), 'The current owner can advance a reservation with compare-and-swap.' );
atomic_check( ! MMGWC_Atomic_Option::replace_reserved_value( 'claim', 'order-42', 'stale-overwrite' ), 'A stale reservation value cannot overwrite newer state.' );
atomic_check( $wpdb->rows['claim'] === 'order-42-retry-1', 'A rejected stale replacement preserves the newer reservation.' );
atomic_check( MMGWC_Atomic_Option::keys_with_prefix( 'cla', 5 ) === array( 'claim' ), 'Durable reservations can be discovered by a bounded prefix scan.' );
atomic_check( MMGWC_Atomic_Option::keys_with_prefix( 'cla', 5, 1 ) === array(), 'Durable reservation scans can advance past an earlier recovery page.' );
$wpdb->fail_scan = true;
atomic_check( MMGWC_Atomic_Option::keys_with_prefix( 'cla', 5 ) === null, 'A failed prefix scan is distinct from an empty durable queue.' );
$wpdb->fail_scan = false;

$first_lock = MMGWC_Atomic_Option::acquire_lock( 'lock', 120 );
atomic_check( $first_lock !== '', 'The first request acquires an owned lock.' );
atomic_check( MMGWC_Atomic_Option::acquire_lock( 'lock', 120 ) === '', 'A second request cannot acquire a current lock.' );
$renewed_lock = MMGWC_Atomic_Option::renew_lock( 'lock', $first_lock );
atomic_check( $renewed_lock !== '' && $wpdb->rows['lock'] === $renewed_lock, 'The current owner can renew its lock with compare-and-swap.' );
atomic_check( MMGWC_Atomic_Option::renew_lock( 'lock', $first_lock ) === '', 'A stale pre-renewal value cannot renew the lock again.' );
$first_lock = $renewed_lock;

$wpdb->rows['lock'] = '1|stale-owner';
$replacement_lock = MMGWC_Atomic_Option::acquire_lock( 'lock', 1 );
atomic_check( $replacement_lock !== '' && $replacement_lock !== '1|stale-owner', 'A stale lock is replaced with compare-and-swap ownership.' );
MMGWC_Atomic_Option::release_lock( 'lock', $first_lock );
atomic_check( isset( $wpdb->rows['lock'] ) && $wpdb->rows['lock'] === $replacement_lock, 'A stale owner cannot release the replacement lock.' );
MMGWC_Atomic_Option::release_lock( 'lock', $replacement_lock );
atomic_check( ! isset( $wpdb->rows['lock'] ), 'The current owner can release its exact lock.' );

if ( $failures > 0 ) {
	fwrite( STDERR, "{$failures} of {$tests} atomic option tests failed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "All {$tests} atomic option tests passed.\n" );
