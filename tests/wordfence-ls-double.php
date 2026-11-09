<?php

namespace WordfenceLS;

class Controller_Settings {
	public static $values = array();

	public static function shared() {
		return new self();
	}

	public function set( $key, $value, $autoload = null ) {
		unset( $autoload );
		self::$values[ $key ] = $value;
	}
}
