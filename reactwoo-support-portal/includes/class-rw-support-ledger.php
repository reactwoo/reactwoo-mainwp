<?php
/**
 * Append-only support minute ledger. Balance is the sum of unexpired rows.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Support_Ledger {
	public static function rows_for_subscription( $subscription_id ) {
		global $wpdb;

		$table = RW_DB::table( 'support_ledger' );
		if ( ! RW_DB::table_exists( $table ) ) {
			return array();
		}

		$loaded = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE subscription_id = %d ORDER BY id ASC",
				(int) $subscription_id
			),
			ARRAY_A
		);

		return is_array( $loaded ) ? $loaded : array();
	}

	public static function balances( $subscription_id, $now = '' ) {
		$now  = $now ? $now : current_time( 'mysql' );
		$rows = self::rows_for_subscription( $subscription_id );

		return array(
			'included'  => RW_Support_Balance::included_remainder( $rows, $now ),
			'purchased' => RW_Support_Balance::purchased_remainder( $rows, $now ),
			'rows'      => $rows,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public static function append( $user_id, $subscription_id, array $row, $created_by = 0 ) {
		global $wpdb;

		$table = RW_DB::table( 'support_ledger' );
		$now   = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'user_id'         => (int) $user_id,
				'subscription_id' => (int) $subscription_id,
				'managed_site_id' => isset( $row['managed_site_id'] ) ? (int) $row['managed_site_id'] : null,
				'order_id'        => isset( $row['order_id'] ) ? (int) $row['order_id'] : null,
				'order_item_id'   => isset( $row['order_item_id'] ) ? (int) $row['order_item_id'] : null,
				'minutes_delta'   => (int) $row['minutes_delta'],
				'credit_type'     => (string) $row['credit_type'],
				'reference'       => isset( $row['reference'] ) ? (string) $row['reference'] : '',
				'expires_at'      => isset( $row['expires_at'] ) ? (string) $row['expires_at'] : null,
				'created_at'      => $now,
				'created_by'      => (int) $created_by,
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function record_adjustment( $user_id, $subscription_id, $minutes_delta, $actor_id, $reference ) {
		return self::append(
			$user_id,
			$subscription_id,
			array(
				'minutes_delta' => (int) $minutes_delta,
				'credit_type'   => 'adjustment',
				'reference'     => 'included:' . sanitize_text_field( (string) $reference ),
				'expires_at'    => null,
			),
			(int) $actor_id
		);
	}
}
