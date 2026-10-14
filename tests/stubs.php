<?php
/**
 * Minimal OpenCart stand-ins so the WiPay models can be exercised without a
 * full store. Only the surface the extension actually touches is faked.
 *
 * @license GPL-2.0-or-later
 */

define( 'DB_PREFIX', 'oc_' );

if ( ! defined( 'WIPAY_WAYPOINT_LIVE_API_KEY' ) ) {
	define( 'WIPAY_WAYPOINT_LIVE_API_KEY', 'livekey_987654321' );
}

/** Base class the OpenCart 3 model extends. */
class Model {
	public $config;
	public $db;
	public $language;
}

class FakeConfig {
	public $data = array();

	public function get( $key ) {
		return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : null;
	}
}

class FakeLanguage {
	public function get( $key ) {
		return $key;
	}
}

class FakeQueryResult {
	public $num_rows = 0;
	public $row      = array();
	public $rows     = array();
}

class FakeDb {
	public $queries = array();

	public function query( $sql ) {
		$this->queries[] = $sql;

		return new FakeQueryResult();
	}

	public function escape( $value ) {
		return addslashes( (string) $value );
	}

	public function likeStatements() {
		$found = array();
		foreach ( $this->queries as $sql ) {
			if ( stripos( $sql, 'CREATE TABLE IF NOT EXISTS' ) !== false ) {
				$found[] = 'create';
			} elseif ( stripos( $sql, 'INSERT INTO' ) !== false ) {
				$found[] = 'insert';
			} elseif ( stripos( $sql, 'SELECT' ) !== false ) {
				$found[] = 'select';
			} elseif ( stripos( $sql, 'UPDATE' ) !== false ) {
				$found[] = 'update';
			}
		}

		return $found;
	}
}
