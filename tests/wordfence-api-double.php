<?php

class wfAPI {
	public function __construct( $key, $version ) {
		unset( $key, $version );
	}

	public function call( $action, $get, $post ) {
		unset( $action, $get, $post );
		return array(
			'ok'     => true,
			'isPaid' => true,
		);
	}
}

class wfUtils {
	public static function getWPVersion() {
		return '6.4';
	}
}
