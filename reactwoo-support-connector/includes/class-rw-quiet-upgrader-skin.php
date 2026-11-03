<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'RW_Quiet_Upgrader_Skin', false ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
	return;
}

class RW_Quiet_Upgrader_Skin extends Automatic_Upgrader_Skin {
	public function feedback( $feedback, ...$args ) {
	}

	public function header() {
	}

	public function footer() {
	}

	public function error( $errors ) {
	}

	public function request_filesystem_credentials( $error = false, $context = '', $allow_relaxed_file_ownership = false ) {
		return true;
	}
}
