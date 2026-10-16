<?php
/**
 * Pure support-minute arithmetic.
 *
 * The service period follows the subscription start anniversary. It is not a
 * calendar month and not the WooCommerce billing cycle. Balance is the sum of
 * unexpired ledger rows. Included minutes and purchased minutes stay separate.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Support_Balance {
	/**
	 * @param string $start Subscription start datetime.
	 * @param string $now   Current datetime.
	 * @return array<string,string>|null
	 */
	public static function period_containing( $start, $now ) {
		$start_at = self::parse( $start );
		$now_at   = self::parse( $now );
		if ( ! $start_at || ! $now_at || $now_at < $start_at ) {
			return null;
		}

		$anchor = (int) $start_at->format( 'j' );
		$time   = $start_at->format( 'H:i:s' );
		$cursor = $start_at;
		for ( $guard = 0; $guard < 2400; $guard++ ) {
			$next = self::add_month( $cursor, $anchor, $time );
			if ( $now_at < $next ) {
				$inclusive_end = $next->modify( '-1 day' );
				return array(
					'start'      => $cursor->format( 'Y-m-d H:i:s' ),
					'end'        => $next->format( 'Y-m-d H:i:s' ),
					'start_date' => $cursor->format( 'Y-m-d' ),
					'end_date'   => $inclusive_end->format( 'Y-m-d' ),
				);
			}
			$cursor = $next;
		}

		return null;
	}

	/**
	 * Rows to append for one service period. A second call for the same period
	 * returns no further allowance. Unused included minutes from earlier periods
	 * are closed with an expiry row and are not rolled forward.
	 *
	 * @param array<int,array<string,mixed>> $existing
	 * @param array<string,string>           $period
	 * @return array<int,array<string,mixed>>
	 */
	public static function grant_rows( array $existing, $minutes, array $period, $now ) {
		$reference = 'period:' . $period['start_date'];
		$rows      = array();

		if ( ! self::has_row( $existing, 'plan_allowance', $reference ) ) {
			$rows[] = array(
				'minutes_delta' => (int) $minutes,
				'credit_type'   => 'plan_allowance',
				'reference'     => $reference,
				'expires_at'    => $period['end'],
			);
		}

		$closed = array();
		foreach ( $existing as $row ) {
			if ( ! is_array( $row ) || 'plan_allowance' !== ( isset( $row['credit_type'] ) ? $row['credit_type'] : '' ) ) {
				continue;
			}
			$ref = isset( $row['reference'] ) ? (string) $row['reference'] : '';
			if ( $ref === $reference || 0 !== strpos( $ref, 'period:' ) || isset( $closed[ $ref ] ) ) {
				continue;
			}
			$closed[ $ref ] = true;
			if ( self::has_row( $existing, 'expiry', $ref ) ) {
				continue;
			}
			$remaining = self::period_included_total( $existing, $ref );
			if ( 0 === $remaining ) {
				continue;
			}
			$rows[] = array(
				'minutes_delta' => 0 - $remaining,
				'credit_type'   => 'expiry',
				'reference'     => $ref,
				'expires_at'    => self::parse( $now ) ? self::parse( $now )->format( 'Y-m-d H:i:s' ) : (string) $now,
			);
		}

		return $rows;
	}

	/**
	 * @param array<int,array<string,mixed>> $existing
	 * @return array<string,mixed>|null
	 */
	public static function purchase_row( array $existing, $order_item_id, $minutes, $validity_days, $now ) {
		$order_item_id = (int) $order_item_id;
		$minutes       = (int) $minutes;
		if ( $order_item_id <= 0 || $minutes <= 0 ) {
			return null;
		}
		foreach ( $existing as $row ) {
			if ( is_array( $row ) && 'purchased' === ( isset( $row['credit_type'] ) ? $row['credit_type'] : '' ) && (int) $row['order_item_id'] === $order_item_id ) {
				return null;
			}
		}

		$start = self::parse( $now );
		if ( ! $start ) {
			return null;
		}
		$days = max( 1, (int) $validity_days );

		return array(
			'minutes_delta' => $minutes,
			'credit_type'   => 'purchased',
			'reference'     => 'order_item:' . $order_item_id,
			'order_item_id' => $order_item_id,
			'expires_at'    => $start->modify( '+' . $days . ' days' )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Included minutes are consumed first. Purchased packs are consumed oldest-expiry first.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,string>           $period
	 * @return array{slices:array<int,array<string,mixed>>,unallocated:int}
	 */
	public static function usage_slices( array $rows, $minutes, array $period, $now ) {
		$minutes = (int) $minutes;
		$slices  = array();
		if ( $minutes <= 0 ) {
			return array(
				'slices'      => $slices,
				'unallocated' => 0,
			);
		}

		$take_included = min( $minutes, max( 0, self::included_remainder( $rows, $now ) ) );
		$rest          = $minutes - $take_included;
		if ( $take_included > 0 ) {
			$slices[] = array(
				'minutes_delta' => 0 - $take_included,
				'credit_type'   => 'usage',
				'reference'     => 'included:' . $period['start_date'],
				'expires_at'    => $period['end'],
			);
		}

		$packs = self::purchased_packs( $rows, $now );
		foreach ( $packs as $pack ) {
			if ( $rest <= 0 || $pack['left'] <= 0 ) {
				continue;
			}
			$take     = min( $rest, $pack['left'] );
			$slices[] = array(
				'minutes_delta' => 0 - $take,
				'credit_type'   => 'usage',
				'reference'     => 'purchased:' . $pack['order_item_id'],
				'expires_at'    => $pack['expires_at'],
			);
			$rest    -= $take;
		}

		return array(
			'slices'      => $slices,
			'unallocated' => $rest,
		);
	}

	/**
	 * @param array<string,string> $meta Product meta. Titles are ignored.
	 */
	public static function should_credit( array $meta, $has_maintenance ) {
		$type = isset( $meta['_rw_product_type'] ) ? (string) $meta['_rw_product_type'] : '';
		if ( 'support_credit' !== $type ) {
			return false;
		}
		if ( (int) ( isset( $meta['_rw_support_minutes'] ) ? $meta['_rw_support_minutes'] : 0 ) <= 0 ) {
			return false;
		}
		$requires = isset( $meta['_rw_requires_maintenance'] ) ? (string) $meta['_rw_requires_maintenance'] : '';
		if ( 'yes' === $requires && ! $has_maintenance ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public static function included_remainder( array $rows, $now ) {
		$sum = 0;
		foreach ( $rows as $row ) {
			if ( ! self::counts( $row, $now ) ) {
				continue;
			}
			$type = (string) $row['credit_type'];
			$ref  = isset( $row['reference'] ) ? (string) $row['reference'] : '';
			if ( 'plan_allowance' === $type || 'expiry' === $type || ( 'usage' === $type && 0 === strpos( $ref, 'included:' ) ) || ( 'adjustment' === $type && 0 === strpos( $ref, 'included:' ) ) ) {
				$sum += (int) $row['minutes_delta'];
			}
		}

		return $sum;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public static function purchased_remainder( array $rows, $now ) {
		$sum = 0;
		foreach ( $rows as $row ) {
			if ( ! self::counts( $row, $now ) ) {
				continue;
			}
			$type = (string) $row['credit_type'];
			$ref  = isset( $row['reference'] ) ? (string) $row['reference'] : '';
			if ( 'purchased' === $type || ( 'usage' === $type && 0 === strpos( $ref, 'purchased:' ) ) ) {
				$sum += (int) $row['minutes_delta'];
			}
		}

		return $sum;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	public static function current_grant( array $rows, $period_start_date ) {
		$reference = 'period:' . $period_start_date;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && 'plan_allowance' === ( isset( $row['credit_type'] ) ? $row['credit_type'] : '' ) && $reference === ( isset( $row['reference'] ) ? (string) $row['reference'] : '' ) ) {
				return (int) $row['minutes_delta'];
			}
		}

		return 0;
	}

	/**
	 * @param DateTimeImmutable $cursor
	 */
	public static function add_month( DateTimeImmutable $cursor, $anchor_day, $time ) {
		$year  = (int) $cursor->format( 'Y' );
		$month = (int) $cursor->format( 'n' ) + 1;
		if ( $month > 12 ) {
			$month = 1;
			$year++;
		}
		$day = min( (int) $anchor_day, (int) gmdate( 't', gmmktime( 0, 0, 0, $month, 1, $year ) ) );

		return new DateTimeImmutable( sprintf( '%04d-%02d-%02d %s', $year, $month, $day, $time ) );
	}

	private static function period_included_total( array $rows, $period_reference ) {
		$date = substr( $period_reference, strlen( 'period:' ) );
		$sum  = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = isset( $row['credit_type'] ) ? (string) $row['credit_type'] : '';
			$ref  = isset( $row['reference'] ) ? (string) $row['reference'] : '';
			if ( ( 'plan_allowance' === $type || 'expiry' === $type ) && $ref === $period_reference ) {
				$sum += (int) $row['minutes_delta'];
			}
			if ( 'usage' === $type && $ref === 'included:' . $date ) {
				$sum += (int) $row['minutes_delta'];
			}
		}

		return $sum;
	}

	private static function purchased_packs( array $rows, $now ) {
		$packs = array();
		foreach ( $rows as $row ) {
			if ( ! self::counts( $row, $now ) || 'purchased' !== (string) $row['credit_type'] ) {
				continue;
			}
			$id = (int) $row['order_item_id'];
			if ( ! isset( $packs[ $id ] ) ) {
				$packs[ $id ] = array(
					'order_item_id' => $id,
					'left'          => 0,
					'expires_at'    => isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '',
				);
			}
			$packs[ $id ]['left'] += (int) $row['minutes_delta'];
		}
		foreach ( $rows as $row ) {
			if ( ! self::counts( $row, $now ) || 'usage' !== (string) $row['credit_type'] ) {
				continue;
			}
			$ref = isset( $row['reference'] ) ? (string) $row['reference'] : '';
			if ( 0 !== strpos( $ref, 'purchased:' ) ) {
				continue;
			}
			$id = (int) substr( $ref, strlen( 'purchased:' ) );
			if ( isset( $packs[ $id ] ) ) {
				$packs[ $id ]['left'] += (int) $row['minutes_delta'];
			}
		}

		$packs = array_values( $packs );
		usort(
			$packs,
			static function ( $left, $right ) {
				return strcmp( (string) $left['expires_at'], (string) $right['expires_at'] );
			}
		);

		return $packs;
	}

	private static function has_row( array $rows, $type, $reference ) {
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( $type === ( isset( $row['credit_type'] ) ? $row['credit_type'] : '' ) && $reference === ( isset( $row['reference'] ) ? (string) $row['reference'] : '' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function counts( $row, $now ) {
		if ( ! is_array( $row ) || empty( $row['credit_type'] ) ) {
			return false;
		}
		if ( empty( $row['expires_at'] ) ) {
			return true;
		}

		return (string) $row['expires_at'] > (string) $now;
	}

	private static function parse( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value );
		if ( $parsed instanceof DateTimeImmutable ) {
			return $parsed;
		}
		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d', $value );
		if ( $parsed instanceof DateTimeImmutable ) {
			return $parsed->setTime( 0, 0, 0 );
		}

		return null;
	}
}
