<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress plugin updates via api.reactwoo.com.
 *
 * Catalog slug reactwoo-support-connector is allowlisted, so no licence JWT is sent.
 */
class RW_Connector_Updater {
	const CATALOG_SLUG = 'reactwoo-support-connector';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_update_transient' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugins_api' ), 10, 3 );
	}

	public static function filter_update_transient( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$basename = plugin_basename( RW_CONNECTOR_FILE );
		if ( ! isset( $transient->checked[ $basename ] ) ) {
			return $transient;
		}

		$current = defined( 'RW_CONNECTOR_VERSION' ) ? (string) RW_CONNECTOR_VERSION : '';
		$data    = self::request_check( $current );
		if ( ! is_array( $data ) || empty( $data['update'] ) || empty( $data['version'] ) || empty( $data['download_url'] ) ) {
			return $transient;
		}
		if ( version_compare( $current, (string) $data['version'], '>=' ) ) {
			return $transient;
		}

		$plugin_info              = new stdClass();
		$plugin_info->slug        = self::CATALOG_SLUG;
		$plugin_info->plugin      = $basename;
		$plugin_info->new_version = (string) $data['version'];
		$plugin_info->package     = (string) $data['download_url'];
		$plugin_info->url         = 'https://reactwoo.com';
		if ( ! empty( $data['tested_up_to'] ) ) {
			$plugin_info->tested = (string) $data['tested_up_to'];
		}
		if ( ! empty( $data['min_wp'] ) ) {
			$plugin_info->requires = (string) $data['min_wp'];
		}

		$transient->response[ $basename ] = $plugin_info;

		return $transient;
	}

	public static function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::CATALOG_SLUG !== sanitize_key( (string) $args->slug ) ) {
			return $result;
		}

		$data = self::request_check( '0.0.0' );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			return $result;
		}

		$info                = new stdClass();
		$info->name          = 'ReactWoo Support Connector';
		$info->slug          = self::CATALOG_SLUG;
		$info->version       = (string) $data['version'];
		$info->author        = '<a href="https://reactwoo.com">ReactWoo</a>';
		$info->homepage      = 'https://reactwoo.com';
		$info->requires      = ! empty( $data['min_wp'] ) ? (string) $data['min_wp'] : '';
		$info->tested        = ! empty( $data['tested_up_to'] ) ? (string) $data['tested_up_to'] : '';
		$info->requires_php  = ! empty( $data['min_php'] ) ? (string) $data['min_php'] : '';
		$info->download_link = ! empty( $data['download_url'] ) ? (string) $data['download_url'] : '';
		$info->sections      = array(
			'description' => 'Connects a WordPress website to ReactWoo Maintenance.',
			'changelog'   => ! empty( $data['changelog_html'] ) ? (string) $data['changelog_html'] : '',
		);

		return $info;
	}

	private static function request_check( $current_version ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? $host : 'localhost';

		$response = wp_remote_post(
			'https://api.reactwoo.com/api/v5/updates/check',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'slug'            => self::CATALOG_SLUG,
						'current_version' => (string) $current_version,
						'channel'         => 'stable',
						'site_host'       => $host,
					)
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : null;
	}
}
