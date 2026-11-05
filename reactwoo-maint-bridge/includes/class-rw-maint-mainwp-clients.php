<?php
/**
 * Find or create the MainWP client for a ReactWoo subscriber and link one site.
 *
 * Matching prefers a stored rw_customer_id, then email. Site assignment writes
 * client_id on that one mainwp_wp row. It does not call
 * MainWP_DB_Client::update_selected_sites_for_client(), because that helper
 * clears client_id on every other site of the client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RW_Maint_MainWP_Clients {
	const PROTECTED_SITE_ID = 12;
	const PROTECTED_HOST    = 'aplenty.co.uk';
	const CUSTOMER_FIELD    = 'rw_customer_id';
	const COMPANY_FIELD     = 'company';

	/**
	 * In-memory directory used by tests. Null uses MainWP.
	 *
	 * @var array<string,mixed>|null
	 */
	private static $override = null;

	/**
	 * @param array<string,mixed>|null $directory
	 */
	public static function use_directory( $directory ) {
		self::$override = is_array( $directory ) ? $directory : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function directory() {
		return self::$override;
	}

	/**
	 * @param int    $site_id MainWP site id.
	 * @param string $url     Site URL.
	 */
	public static function is_protected_site( $site_id, $url = '' ) {
		if ( self::PROTECTED_SITE_ID === (int) $site_id ) {
			return true;
		}

		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		return self::PROTECTED_HOST === $host;
	}

	/**
	 * The only site write this class will issue: one column, one row.
	 *
	 * @param int    $client_id MainWP client id.
	 * @param int    $site_id   MainWP site id.
	 * @param string $url       Site URL.
	 * @return array<string,mixed>|null
	 */
	public static function assignment_update( $client_id, $site_id, $url = '', $enrolled_url = '' ) {
		$client_id    = (int) $client_id;
		$site_id      = (int) $site_id;
		$enrolled_url = '' === (string) $enrolled_url ? (string) $url : (string) $enrolled_url;
		if ( $client_id <= 0 || $site_id <= 0 || ! self::may_assign( $site_id, $url, $enrolled_url ) ) {
			return null;
		}

		return array(
			'table' => 'mainwp_wp',
			'data'  => array( 'client_id' => $client_id ),
			'where' => array( 'id' => $site_id ),
		);
	}

	/**
	 * @param array<string,mixed> $payload Enrol or client-update body.
	 * @return array<string,mixed>
	 */
	public static function subscriber_from_payload( array $payload ) {
		$client = ( isset( $payload['client'] ) && is_array( $payload['client'] ) ) ? $payload['client'] : array();
		$source = array_merge( $payload, $client );

		return self::normalize_subscriber( $source );
	}

	/**
	 * @param array<string,mixed> $subscriber
	 * @return array{customer_id:int,name:string,company:string,email:string,phone:?string}
	 */
	public static function normalize_subscriber( array $subscriber ) {
		$customer_id = 0;
		if ( isset( $subscriber['customer_id'] ) ) {
			$customer_id = absint( $subscriber['customer_id'] );
		} elseif ( isset( $subscriber['rw_customer_id'] ) ) {
			$customer_id = absint( $subscriber['rw_customer_id'] );
		}
		if ( ! $customer_id && isset( $subscriber['user_id'] ) ) {
			$customer_id = absint( $subscriber['user_id'] );
		}

		$name = '';
		if ( isset( $subscriber['name'] ) ) {
			$name = $subscriber['name'];
		} elseif ( isset( $subscriber['client_name'] ) ) {
			$name = $subscriber['client_name'];
		}

		$company = '';
		if ( isset( $subscriber['company'] ) ) {
			$company = $subscriber['company'];
		} elseif ( isset( $subscriber['client_company'] ) ) {
			$company = $subscriber['client_company'];
		}

		$email = '';
		if ( isset( $subscriber['email'] ) ) {
			$email = $subscriber['email'];
		} elseif ( isset( $subscriber['client_email'] ) ) {
			$email = $subscriber['client_email'];
		}

		$phone = null;
		if ( array_key_exists( 'client_phone', $subscriber ) ) {
			$phone = trim( (string) $subscriber['client_phone'] );
		} elseif ( array_key_exists( 'phone', $subscriber ) ) {
			$phone = trim( (string) $subscriber['phone'] );
		}
		if ( '' === $phone ) {
			$phone = null;
		}

		return array(
			'customer_id' => $customer_id,
			'name'        => sanitize_text_field( (string) $name ),
			'company'     => sanitize_text_field( (string) $company ),
			'email'       => sanitize_email( (string) $email ),
			'phone'       => null === $phone ? null : sanitize_text_field( $phone ),
		);
	}

	/**
	 * @param array<string,mixed> $subscriber Subscriber details.
	 * @param int                 $mainwp_site_id MainWP site to assign, or 0 for a details update.
	 * @param array<string,mixed> $context Audit context.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function sync( array $subscriber, $mainwp_site_id = 0, array $context = array() ) {
		$loaded = self::load( (int) $mainwp_site_id );
		if ( is_wp_error( $loaded ) ) {
			self::audit_failure( $loaded, $subscriber, $context );
			return $loaded;
		}

		$enrolled_url = isset( $context['site_url'] ) ? (string) $context['site_url'] : '';
		$applied      = self::apply( $loaded, $subscriber, $mainwp_site_id, $enrolled_url );
		if ( is_wp_error( $applied ) ) {
			self::audit_failure( $applied, $subscriber, $context );
			return $applied;
		}

		$saved = self::commit( $loaded, $applied['directory'], $applied['result'] );
		if ( is_wp_error( $saved ) ) {
			self::audit_failure( $saved, $subscriber, $context );
			return $saved;
		}

		if ( ! empty( $saved['mainwp_client_id'] ) ) {
			$applied['result']['mainwp_client_id'] = (int) $saved['mainwp_client_id'];
		}

		self::audit_success( $applied['result'], $context );

		return $applied['result'];
	}

	/**
	 * Pure find-or-create. Copies the directory and changes one client, plus
	 * the enrolled site when that site is present and not protected.
	 *
	 * @param array<string,mixed> $directory
	 * @param array<string,mixed> $subscriber
	 * @param int                 $mainwp_site_id
	 * @return array<string,mixed>|WP_Error
	 */
	public static function apply( array $directory, array $subscriber, $mainwp_site_id = 0, $enrolled_url = '' ) {
		$subscriber = self::normalize_subscriber( $subscriber );
		if ( ! $subscriber['customer_id'] || '' === $subscriber['email'] ) {
			return new WP_Error( 'rw_mainwp_client_missing', 'Subscriber email and customer ID are required.' );
		}

		$clients = ( isset( $directory['clients'] ) && is_array( $directory['clients'] ) ) ? array_values( $directory['clients'] ) : array();
		$sites   = ( isset( $directory['sites'] ) && is_array( $directory['sites'] ) ) ? $directory['sites'] : array();

		$matched_by = 'customer_id';
		$matched    = self::find_by_customer_id( $clients, $subscriber['customer_id'] );
		if ( ! $matched ) {
			$by_email = self::find_by_email( $clients, $subscriber['email'] );
			if ( $by_email ) {
				$stored = isset( $by_email['rw_customer_id'] ) ? (string) $by_email['rw_customer_id'] : '';
				if ( '' !== $stored && (string) $subscriber['customer_id'] !== $stored ) {
					return new WP_Error( 'rw_mainwp_client_conflict', 'That email belongs to a different customer.' );
				}
				$matched    = $by_email;
				$matched_by = 'email';
			}
		}

		if ( ! $matched ) {
			if ( self::find_by_email( $clients, $subscriber['email'] ) ) {
				return new WP_Error( 'rw_mainwp_client_conflict', 'That email belongs to a different customer.' );
			}
			$matched_by = 'created';
			$matched    = array(
				'client_id'      => self::next_client_id( $clients ),
				'name'           => '',
				'client_email'   => '',
				'client_phone'   => '',
				'company'        => '',
				'rw_customer_id' => '',
			);
			$clients[]  = $matched;
		}

		$index = self::client_index( $clients, (int) $matched['client_id'] );
		if ( $index < 0 ) {
			return new WP_Error( 'rw_mainwp_client_missing', 'Matched client was not in the directory.' );
		}

		$client                     = $clients[ $index ];
		$client['name']             = $subscriber['name'];
		$client['company']          = $subscriber['company'];
		$client['rw_customer_id']   = (string) $subscriber['customer_id'];
		$email_owner                = self::find_by_email( $clients, $subscriber['email'] );
		if ( ! $email_owner || (int) $email_owner['client_id'] === (int) $client['client_id'] ) {
			$client['client_email'] = $subscriber['email'];
		}
		if ( null !== $subscriber['phone'] ) {
			$client['client_phone'] = $subscriber['phone'];
		}
		$clients[ $index ] = $client;

		$assigned       = 0;
		$protected      = false;
		$mainwp_site_id = (int) $mainwp_site_id;
		$url            = self::site_url( $sites, $mainwp_site_id );
		$enrolled_url   = '' === (string) $enrolled_url ? $url : (string) $enrolled_url;
		if ( $mainwp_site_id > 0 && ( self::is_protected_site( $mainwp_site_id, $url ) || self::is_protected_site( $mainwp_site_id, $enrolled_url ) ) ) {
			$protected = true;
		} elseif ( $mainwp_site_id > 0 && isset( $sites[ $mainwp_site_id ] ) && empty( $sites[ $mainwp_site_id ]['protected'] ) && self::may_assign( $mainwp_site_id, $url, $enrolled_url ) ) {
			$sites[ $mainwp_site_id ]['client_id'] = (int) $client['client_id'];
			$assigned                              = $mainwp_site_id;
		}

		return array(
			'directory' => array(
				'clients' => $clients,
				'sites'   => $sites,
			),
			'result'    => array(
				'mainwp_client_id' => (int) $client['client_id'],
				'matched_by'       => $matched_by,
				'customer_id'      => (int) $subscriber['customer_id'],
				'mainwp_site_id'   => $assigned,
				'site_protected'   => $protected,
				'name'             => $client['name'],
				'company'          => $client['company'],
				'email'            => $client['client_email'],
			),
		);
	}

	/**
	 * @param int $mainwp_site_id
	 * @return array<string,mixed>|WP_Error
	 */
	private static function load( $mainwp_site_id ) {
		if ( is_array( self::$override ) ) {
			return self::$override;
		}

		return self::load_from_mainwp( (int) $mainwp_site_id );
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>|WP_Error
	 */
	private static function commit( array $before, array $after, array $result ) {
		$safe = self::safe_directory( $before, $after, $result );
		if ( is_array( self::$override ) ) {
			self::$override = $safe;
			return array( 'mainwp_client_id' => (int) $result['mainwp_client_id'] );
		}

		return self::persist( $before, $safe, $result );
	}

	/**
	 * Keep every client and site that this enrolment does not own.
	 * Site 12 is never replaced, even if the working copy changed it.
	 *
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private static function safe_directory( array $before, array $after, array $result ) {
		$safe      = $before;
		$client_id = (int) $result['mainwp_client_id'];
		$site_id   = (int) $result['mainwp_site_id'];
		$updated   = null;

		foreach ( $after['clients'] as $client ) {
			if ( (int) $client['client_id'] === $client_id ) {
				$updated = $client;
				break;
			}
		}
		if ( ! is_array( $updated ) ) {
			return $before;
		}

		$replaced = false;
		if ( ! isset( $safe['clients'] ) || ! is_array( $safe['clients'] ) ) {
			$safe['clients'] = array();
		}
		foreach ( $safe['clients'] as $index => $existing ) {
			if ( (int) $existing['client_id'] === $client_id ) {
				$safe['clients'][ $index ] = $updated;
				$replaced                  = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$safe['clients'][] = $updated;
		}

		if ( $site_id <= 0 || self::is_protected_site( $site_id, self::site_url( $before['sites'] ?? array(), $site_id ) ) || self::is_protected_site( $site_id, self::site_url( $after['sites'] ?? array(), $site_id ) ) ) {
			return $safe;
		}
		if ( empty( $after['sites'][ $site_id ] ) || ! empty( $after['sites'][ $site_id ]['protected'] ) ) {
			return $safe;
		}

		$after_site = $after['sites'][ $site_id ];
		if ( ! isset( $safe['sites'] ) || ! is_array( $safe['sites'] ) ) {
			$safe['sites'] = array();
		}
		if ( isset( $safe['sites'][ $site_id ] ) ) {
			$current = $safe['sites'][ $site_id ];
			if ( self::same_tags( $current, $after_site ) && self::same_subscription( $current, $after_site ) ) {
				$safe['sites'][ $site_id ]['client_id'] = (int) $after_site['client_id'];
			}
			return $safe;
		}

		$safe['sites'][ $site_id ] = $after_site;
		return $safe;
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $safe
	 * @param array<string,mixed> $result
	 * @return array<string,int>|WP_Error
	 */
	private static function persist( array $before, array $safe, array $result ) {
		$client_id      = (int) $result['mainwp_client_id'];
		$real_id        = $client_id;
		$before_clients = self::index_clients( isset( $before['clients'] ) ? $before['clients'] : array() );
		$safe_clients   = self::index_clients( isset( $safe['clients'] ) ? $safe['clients'] : array() );

		if ( isset( $safe_clients[ $client_id ] ) ) {
			if ( ! isset( $before_clients[ $client_id ] ) ) {
				$inserted = self::insert_client( $safe_clients[ $client_id ] );
				if ( is_wp_error( $inserted ) ) {
					return $inserted;
				}
				$real_id = (int) $inserted;
			} elseif ( self::client_changed( $before_clients[ $client_id ], $safe_clients[ $client_id ] ) ) {
				$updated = self::update_client_row( $before_clients[ $client_id ], $safe_clients[ $client_id ] );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				$real_id = (int) $updated;
			}
		}

		$site_id = (int) $result['mainwp_site_id'];
		$url     = self::site_url( isset( $safe['sites'] ) ? $safe['sites'] : array(), $site_id );
		$write   = self::assignment_update( $real_id, $site_id, $url );
		if ( null !== $write && isset( $safe['sites'][ $site_id ] ) ) {
			$before_link = isset( $before['sites'][ $site_id ] ) ? (int) $before['sites'][ $site_id ]['client_id'] : null;
			$after_link  = (int) $safe['sites'][ $site_id ]['client_id'];
			if ( null === $before_link || $before_link !== $after_link ) {
				self::assign_site( $write );
			}
		}

		return array( 'mainwp_client_id' => $real_id );
	}

	/**
	 * @param int $mainwp_site_id
	 * @return array<string,mixed>|WP_Error
	 */
	private static function load_from_mainwp( $mainwp_site_id ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return new WP_Error( 'rw_mainwp_clients_unavailable', 'MainWP clients are not available.' );
		}

		$clients_table = self::table( 'wp_clients' );
		$fields_table  = self::table( 'wp_clients_fields' );
		$values_table  = self::table( 'wp_clients_field_values' );
		if ( '' === $clients_table || ! self::table_exists( $clients_table ) ) {
			return new WP_Error( 'rw_mainwp_clients_unavailable', 'MainWP clients are not available.' );
		}

		$rows = $wpdb->get_results( "SELECT client_id, name, client_email, client_phone FROM {$clients_table}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'rw_mainwp_clients_unavailable', 'MainWP clients could not be read.' );
		}

		$values = array();
		if ( self::table_exists( $fields_table ) && self::table_exists( $values_table ) ) {
			$loaded = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT v.value_client_id, f.field_name, v.field_value
					FROM {$values_table} v
					INNER JOIN {$fields_table} f ON f.field_id = v.field_id
					WHERE f.client_id = 0 AND f.field_name IN (%s, %s)",
					self::CUSTOMER_FIELD,
					self::COMPANY_FIELD
				),
				ARRAY_A
			);
			if ( is_array( $loaded ) ) {
				$values = $loaded;
			}
		}

		$by_client = array();
		foreach ( $values as $value ) {
			$client_key = (int) $value['value_client_id'];
			if ( ! isset( $by_client[ $client_key ] ) ) {
				$by_client[ $client_key ] = array();
			}
			$by_client[ $client_key ][ (string) $value['field_name'] ] = (string) $value['field_value'];
		}

		$clients = array();
		foreach ( $rows as $row ) {
			$id        = (int) $row['client_id'];
			$customer  = isset( $by_client[ $id ][ self::CUSTOMER_FIELD ] ) ? (string) absint( $by_client[ $id ][ self::CUSTOMER_FIELD ] ) : '';
			$clients[] = array(
				'client_id'      => $id,
				'name'           => sanitize_text_field( (string) $row['name'] ),
				'client_email'   => sanitize_email( (string) $row['client_email'] ),
				'client_phone'   => sanitize_text_field( (string) $row['client_phone'] ),
				'company'        => isset( $by_client[ $id ][ self::COMPANY_FIELD ] ) ? sanitize_text_field( $by_client[ $id ][ self::COMPANY_FIELD ] ) : '',
				'rw_customer_id' => ( '0' === $customer ) ? '' : $customer,
			);
		}

		$directory = array(
			'clients' => $clients,
			'sites'   => array(),
		);
		$site      = self::fetch_site( (int) $mainwp_site_id );
		if ( is_array( $site ) ) {
			$directory['sites'][ (int) $site['id'] ] = $site;
		}

		return $directory;
	}

	/**
	 * Read one site. Site 12 is not queried.
	 *
	 * @param int $site_id
	 * @return array<string,mixed>|null
	 */
	private static function fetch_site( $site_id ) {
		global $wpdb;

		$site_id = (int) $site_id;
		if ( $site_id <= 0 || self::PROTECTED_SITE_ID === $site_id ) {
			return null;
		}
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return null;
		}

		$table = self::table( 'wp' );
		if ( '' === $table || ! self::table_exists( $table ) ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, url, client_id FROM {$table} WHERE id = %d", $site_id ), ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return null;
		}

		$site = array(
			'id'        => (int) $row['id'],
			'url'       => (string) $row['url'],
			'client_id' => (int) $row['client_id'],
			'tags'      => array(),
		);
		if ( self::is_protected_site( $site['id'], $site['url'] ) ) {
			$site['protected'] = true;
		}

		return $site;
	}

	/**
	 * @param array<string,mixed> $client
	 * @return int|WP_Error
	 */
	private static function insert_client( array $client ) {
		$data = array(
			'name'         => $client['name'],
			'client_email' => $client['client_email'],
			'client_phone' => isset( $client['client_phone'] ) ? (string) $client['client_phone'] : '',
			'created'      => time(),
		);

		$db = self::mainwp_clients();
		if ( $db && method_exists( $db, 'update_client' ) ) {
			$row = $db->update_client( $data );
			if ( ! is_object( $row ) || empty( $row->client_id ) ) {
				return new WP_Error( 'rw_mainwp_client_create_failed', 'MainWP did not create the client.' );
			}
			$client_id = (int) $row->client_id;
		} else {
			global $wpdb;
			$ok = $wpdb->insert(
				self::table( 'wp_clients' ),
				$data,
				array( '%s', '%s', '%s', '%d' )
			);
			if ( ! $ok ) {
				return new WP_Error( 'rw_mainwp_client_create_failed', 'MainWP did not create the client.' );
			}
			$client_id = (int) $wpdb->insert_id;
		}

		$stored = self::store_fields( $client_id, $client );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return $client_id;
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $client
	 * @return int|WP_Error
	 */
	private static function update_client_row( array $before, array $client ) {
		$client_id = (int) $client['client_id'];
		$data      = array(
			'client_id'    => $client_id,
			'name'         => $client['name'],
			'client_email' => $client['client_email'],
		);
		if ( (string) $before['client_phone'] !== (string) $client['client_phone'] ) {
			$data['client_phone'] = (string) $client['client_phone'];
		}

		$db = self::mainwp_clients();
		if ( $db && method_exists( $db, 'update_client' ) ) {
			$row = $db->update_client( $data );
			if ( ! $row ) {
				return new WP_Error( 'rw_mainwp_client_update_failed', 'MainWP did not update the client.' );
			}
		} else {
			global $wpdb;
			$fields = $data;
			unset( $fields['client_id'] );
			$formats = array();
			foreach ( $fields as $value ) {
				$formats[] = is_int( $value ) ? '%d' : '%s';
			}
			$updated = $wpdb->update(
				self::table( 'wp_clients' ),
				$fields,
				array( 'client_id' => $client_id ),
				$formats,
				array( '%d' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'rw_mainwp_client_update_failed', 'MainWP did not update the client.' );
			}
		}

		$stored = self::store_fields( $client_id, $client );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return $client_id;
	}

	/**
	 * @param int                 $client_id
	 * @param array<string,mixed> $client
	 * @return true|WP_Error
	 */
	private static function store_fields( $client_id, array $client ) {
		$customer = self::store_field( self::CUSTOMER_FIELD, (string) $client['rw_customer_id'], (int) $client_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$company = self::store_field( self::COMPANY_FIELD, (string) $client['company'], (int) $client_id );
		if ( is_wp_error( $company ) ) {
			return $company;
		}

		return true;
	}

	/**
	 * @param string $name
	 * @param string $value
	 * @param int    $client_id
	 * @return true|WP_Error
	 */
	private static function store_field( $name, $value, $client_id ) {
		$field_id = self::ensure_field( $name );
		if ( is_wp_error( $field_id ) ) {
			return $field_id;
		}
		if ( ! $field_id ) {
			return new WP_Error( 'rw_mainwp_client_field_failed', 'MainWP client field could not be stored.' );
		}

		$db = self::mainwp_clients();
		if ( $db && method_exists( $db, 'update_client_field_value' ) ) {
			$stored = $db->update_client_field_value( (int) $field_id, $value, (int) $client_id );
			if ( false === $stored ) {
				return new WP_Error( 'rw_mainwp_client_field_failed', 'MainWP client field could not be stored.' );
			}
			return true;
		}

		global $wpdb;
		$table = self::table( 'wp_clients_field_values' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT value_id, field_value FROM {$table} WHERE field_id = %d AND value_client_id = %d", (int) $field_id, (int) $client_id ),
			ARRAY_A
		);
		if ( is_array( $row ) && isset( $row['value_id'] ) ) {
			if ( (string) $row['field_value'] === (string) $value ) {
				return true;
			}
			$updated = $wpdb->update(
				$table,
				array( 'field_value' => $value ),
				array( 'value_id' => (int) $row['value_id'] ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return new WP_Error( 'rw_mainwp_client_field_failed', 'MainWP client field could not be stored.' );
			}
			return true;
		}

		$inserted = $wpdb->insert(
			$table,
			array(
				'field_id'        => (int) $field_id,
				'field_value'     => $value,
				'value_client_id' => (int) $client_id,
			),
			array( '%d', '%s', '%d' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'rw_mainwp_client_field_failed', 'MainWP client field could not be stored.' );
		}

		return true;
	}

	/**
	 * @param string $name
	 * @return int|WP_Error
	 */
	private static function ensure_field( $name ) {
		$db = self::mainwp_clients();
		if ( $db && method_exists( $db, 'get_client_fields_by' ) ) {
			$field = $db->get_client_fields_by( 'field_name', $name, 0 );
			if ( is_object( $field ) && ! empty( $field->field_id ) ) {
				return (int) $field->field_id;
			}
			if ( method_exists( $db, 'add_client_field' ) ) {
				$created = $db->add_client_field(
					array(
						'field_name' => $name,
						'field_desc' => self::field_description( $name ),
						'client_id'  => 0,
					)
				);
				if ( is_object( $created ) && ! empty( $created->field_id ) ) {
					return (int) $created->field_id;
				}
			}
		}

		global $wpdb;
		$table = self::table( 'wp_clients_fields' );
		if ( '' === $table ) {
			return new WP_Error( 'rw_mainwp_client_field_failed', 'MainWP client field could not be stored.' );
		}
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT field_id FROM {$table} WHERE field_name = %s AND client_id = 0", $name ) );
		if ( $existing ) {
			return (int) $existing;
		}
		$inserted = $wpdb->insert(
			$table,
			array(
				'field_name' => $name,
				'field_desc' => self::field_description( $name ),
				'client_id'  => 0,
			),
			array( '%s', '%s', '%d' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'rw_mainwp_client_field_failed', 'MainWP client field could not be stored.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string,mixed> $write
	 */
	private static function assign_site( array $write ) {
		global $wpdb;

		if ( self::PROTECTED_SITE_ID === (int) $write['where']['id'] ) {
			return;
		}

		$wpdb->update(
			self::table( 'wp' ),
			$write['data'],
			$write['where'],
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * @return object|null
	 */
	private static function mainwp_clients() {
		$class = '\MainWP\Dashboard\MainWP_DB_Client';
		if ( ! class_exists( $class ) ) {
			return null;
		}
		if ( method_exists( $class, 'instance' ) ) {
			return $class::instance();
		}
		if ( method_exists( $class, 'get_instance' ) ) {
			return $class::get_instance();
		}

		return null;
	}

	/**
	 * Site writes go through the same URL guard as check and sync.
	 * Site 12 and aplenty.co.uk are refused before that guard.
	 *
	 * @param int    $site_id MainWP site id.
	 * @param string $record_url URL stored for that MainWP site.
	 * @param string $enrolled_url URL being enrolled or checked.
	 * @return bool
	 */
	private static function may_assign( $site_id, $record_url, $enrolled_url ) {
		if ( self::is_protected_site( $site_id, $record_url ) || self::is_protected_site( $site_id, $enrolled_url ) ) {
			return false;
		}
		if ( ! class_exists( 'RW_Maint_MainWP' ) ) {
			return false;
		}

		return RW_Maint_MainWP::may_operate_on( $site_id, $enrolled_url, is_string( $record_url ) && '' !== $record_url ? $record_url : null );
	}

	/**
	 * @param string $name Field name.
	 */
	private static function field_description( $name ) {
		if ( self::COMPANY_FIELD === $name ) {
			return 'Company';
		}
		if ( self::CUSTOMER_FIELD === $name ) {
			return 'ReactWoo customer ID';
		}

		return $name;
	}

	/**
	 * @param string $suffix
	 */
	private static function table( $suffix ) {
		global $wpdb;

		$allowed = array( 'wp_clients', 'wp_clients_fields', 'wp_clients_field_values', 'wp' );
		if ( ! in_array( $suffix, $allowed, true ) || ! is_object( $wpdb ) ) {
			return '';
		}

		return $wpdb->prefix . 'mainwp_' . $suffix;
	}

	/**
	 * @param string $table
	 */
	private static function table_exists( $table ) {
		global $wpdb;

		if ( '' === $table || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * @param array<int,array<string,mixed>> $clients
	 * @param int                            $customer_id
	 * @return array<string,mixed>|null
	 */
	private static function find_by_customer_id( array $clients, $customer_id ) {
		$customer_id = (string) absint( $customer_id );
		if ( '0' === $customer_id ) {
			return null;
		}
		foreach ( $clients as $client ) {
			$stored = isset( $client['rw_customer_id'] ) ? (string) $client['rw_customer_id'] : '';
			if ( $stored === $customer_id ) {
				return $client;
			}
		}

		return null;
	}

	/**
	 * @param array<int,array<string,mixed>> $clients
	 * @param string                         $email
	 * @return array<string,mixed>|null
	 */
	private static function find_by_email( array $clients, $email ) {
		$email = strtolower( sanitize_email( $email ) );
		if ( '' === $email ) {
			return null;
		}
		foreach ( $clients as $client ) {
			$stored = isset( $client['client_email'] ) ? strtolower( sanitize_email( $client['client_email'] ) ) : '';
			if ( '' !== $stored && $stored === $email ) {
				return $client;
			}
		}

		return null;
	}

	/**
	 * @param array<int,array<string,mixed>> $clients
	 */
	private static function next_client_id( array $clients ) {
		$next = 1;
		foreach ( $clients as $client ) {
			$next = max( $next, (int) $client['client_id'] + 1 );
		}

		return $next;
	}

	/**
	 * @param array<int,array<string,mixed>> $clients
	 * @param int                            $client_id
	 */
	private static function client_index( array $clients, $client_id ) {
		foreach ( $clients as $index => $client ) {
			if ( (int) $client['client_id'] === (int) $client_id ) {
				return (int) $index;
			}
		}

		return -1;
	}

	/**
	 * @param array<int,array<string,mixed>> $clients
	 * @return array<int,array<string,mixed>>
	 */
	private static function index_clients( $clients ) {
		$indexed = array();
		foreach ( (array) $clients as $client ) {
			if ( is_array( $client ) && isset( $client['client_id'] ) ) {
				$indexed[ (int) $client['client_id'] ] = $client;
			}
		}

		return $indexed;
	}

	/**
	 * @param array<string,mixed> $before
	 * @param array<string,mixed> $after
	 */
	private static function client_changed( array $before, array $after ) {
		foreach ( array( 'name', 'client_email', 'client_phone', 'company', 'rw_customer_id' ) as $key ) {
			$left  = isset( $before[ $key ] ) ? (string) $before[ $key ] : '';
			$right = isset( $after[ $key ] ) ? (string) $after[ $key ] : '';
			if ( $left !== $right ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $sites
	 * @param int                            $site_id
	 */
	private static function site_url( $sites, $site_id ) {
		$site_id = (int) $site_id;
		if ( $site_id && isset( $sites[ $site_id ]['url'] ) ) {
			return (string) $sites[ $site_id ]['url'];
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $left
	 * @param array<string,mixed> $right
	 */
	private static function same_tags( array $left, array $right ) {
		$tags = function ( $site ) {
			return ( isset( $site['tags'] ) && is_array( $site['tags'] ) ) ? array_values( $site['tags'] ) : array();
		};

		return $tags( $left ) === $tags( $right );
	}

	/**
	 * @param array<string,mixed> $left
	 * @param array<string,mixed> $right
	 */
	private static function same_subscription( array $left, array $right ) {
		$left_has  = array_key_exists( 'subscription_id', $left );
		$right_has = array_key_exists( 'subscription_id', $right );
		if ( $left_has !== $right_has ) {
			return false;
		}
		if ( ! $left_has ) {
			return true;
		}

		return (int) $left['subscription_id'] === (int) $right['subscription_id'];
	}

	/**
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $context
	 */
	private static function audit_success( array $result, array $context ) {
		if ( ! class_exists( 'RW_Maint_Audit' ) ) {
			return;
		}

		$entry = array(
			'mainwp_client_id' => (int) $result['mainwp_client_id'],
			'matched_by'       => (string) $result['matched_by'],
			'customer_id'      => (int) $result['customer_id'],
			'mainwp_site_id'   => (int) $result['mainwp_site_id'],
		);
		if ( ! empty( $result['site_protected'] ) ) {
			$entry['protected_site'] = self::PROTECTED_SITE_ID;
		}

		RW_Maint_Audit::log( 'mainwp_client_linked', array_merge( $context, $entry ) );
	}

	/**
	 * @param WP_Error            $error
	 * @param array<string,mixed> $subscriber
	 * @param array<string,mixed> $context
	 */
	private static function audit_failure( $error, array $subscriber, array $context ) {
		if ( ! class_exists( 'RW_Maint_Audit' ) ) {
			return;
		}

		$normalized = self::normalize_subscriber( $subscriber );
		RW_Maint_Audit::log(
			'mainwp_client_failed',
			array_merge(
				$context,
				array(
					'customer_id' => (int) $normalized['customer_id'],
					'error'       => $error->get_error_message(),
				)
			)
		);
	}
}
