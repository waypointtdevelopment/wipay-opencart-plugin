<?php
/**
 * WiPay Waypoint payment model for OpenCart 3.x.
 *
 * Independent implementation maintained by Waypoint. Not affiliated with,
 * sponsored by, or endorsed by WiPay. Card details are entered on WiPay's
 * hosted checkout page; this extension never sees them.
 *
 * @license GPL-2.0-or-later
 */

class ModelExtensionPaymentWipayWaypoint extends Model {

	/** Published sandbox test values (WiPay Payments API > Testing). */
	const SANDBOX_ACCOUNT = '1234567890';
	const SANDBOX_KEY     = '123';

	/**
	 * Territory => environment => hostname.
	 *
	 * All five live/sandbox pairs are confirmed against WiPay's Platforms and
	 * Environments page (checked 15 October 2026), and all ten hostnames were
	 * verified reachable over HTTPS. This is no longer an open release item.
	 */
	private static $hosts = array(
		'BB' => array( 'live' => 'bb.wipayfinancial.com', 'sandbox' => 'bbsb.wipayfinancial.com' ),
		'GD' => array( 'live' => 'gd.wipayfinancial.com', 'sandbox' => 'gdsb.wipayfinancial.com' ),
		'GY' => array( 'live' => 'gy.wipayfinancial.com', 'sandbox' => 'gysb.wipayfinancial.com' ),
		'JM' => array( 'live' => 'jm.wipayfinancial.com', 'sandbox' => 'jmsb.wipayfinancial.com' ),
		'TT' => array( 'live' => 'tt.wipayfinancial.com', 'sandbox' => 'ttsb.wipayfinancial.com' ),
	);

	/**
	 * Offer the method at checkout when enabled.
	 *
	 * @param array $address
	 * @param float $total
	 * @return array
	 */
	public function getMethod( $address, $total ) {
		if ( ! $this->config->get( 'payment_wipay_waypoint_status' ) ) {
			return array();
		}

		return array(
			'code'       => 'wipay_waypoint',
			'title'      => $this->title(),
			'terms'      => '',
			'sort_order' => (int) $this->config->get( 'payment_wipay_waypoint_sort_order' ),
		);
	}

	public function title() {
		$title = (string) $this->config->get( 'payment_wipay_waypoint_title' );

		return $title !== '' ? $title : 'Credit card (WiPay)';
	}

	/* ---------------------------------------------------------------- */
	/* Configuration                                                     */
	/* ---------------------------------------------------------------- */

	public function environment() {
		$value = (string) $this->config->get( 'payment_wipay_waypoint_environment' );

		return in_array( $value, array( 'live', 'sandbox' ), true ) ? $value : 'sandbox';
	}

	public function country() {
		return strtoupper( (string) $this->config->get( 'payment_wipay_waypoint_country' ) );
	}

	public function feeStructure() {
		$value = (string) $this->config->get( 'payment_wipay_waypoint_fee' );

		return in_array( $value, array( 'merchant_absorb', 'customer_pay', 'split' ), true )
			? $value
			: 'merchant_absorb';
	}

	/**
	 * Sandbox always uses WiPay's published test account.
	 *
	 * @param string $environment
	 * @return string
	 */
	public function accountNumber( $environment ) {
		if ( $environment === 'sandbox' ) {
			return self::SANDBOX_ACCOUNT;
		}

		return preg_replace( '/\D+/', '', (string) $this->config->get( 'payment_wipay_waypoint_account' ) );
	}

	/**
	 * Response-verification key. Live keys are read from a PHP constant
	 * defined in config.php and are never stored in oc_setting.
	 *
	 * @param string $environment
	 * @return string
	 */
	public function apiKey( $environment ) {
		if ( $environment === 'sandbox' ) {
			return self::SANDBOX_KEY;
		}

		return defined( 'WIPAY_WAYPOINT_LIVE_API_KEY' ) ? (string) WIPAY_WAYPOINT_LIVE_API_KEY : '';
	}

	/* ---------------------------------------------------------------- */
	/* Host map                                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * @param string $country
	 * @param string $environment
	 * @return string Hostname, or an empty string when unknown.
	 */
	public function host( $country, $environment ) {
		$country     = strtoupper( trim( (string) $country ) );
		$environment = trim( (string) $environment );

		return isset( self::$hosts[ $country ][ $environment ] )
			? self::$hosts[ $country ][ $environment ]
			: '';
	}

	/**
	 * @param string $country
	 * @param string $environment
	 * @return string Full request endpoint, or an empty string when unknown.
	 */
	public function endpoint( $country, $environment ) {
		$host = $this->host( $country, $environment );

		return $host === '' ? '' : 'https://' . $host . '/plugins/payments/request';
	}

	/**
	 * @param string $url
	 * @return bool True when the URL is a credible WiPay checkout target.
	 */
	public function isValidCheckoutUrl( $url ) {
		$parts = parse_url( (string) $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return false;
		}

		if ( strtolower( $parts['scheme'] ) !== 'https' ) {
			return false;
		}

		return (bool) preg_match( '/(^|\.)wipayfinancial\.com$/i', $parts['host'] );
	}

	/* ---------------------------------------------------------------- */
	/* Signature                                                         */
	/* ---------------------------------------------------------------- */

	/**
	 * hash = md5(transaction_id + original_total + api_key)
	 *
	 * The original total is the amount sent in the request, at exactly two
	 * decimals. The customer-facing total returned on redirect may include
	 * fees and must never be used here.
	 *
	 * @param string $transaction_id
	 * @param float|string $original_total
	 * @param string $api_key
	 * @return string
	 */
	public function computeHash( $transaction_id, $original_total, $api_key ) {
		return md5(
			(string) $transaction_id
			. number_format( (float) $original_total, 2, '.', '' )
			. (string) $api_key
		);
	}

	/**
	 * @param string $transaction_id
	 * @param float|string $original_total
	 * @param string $api_key
	 * @param string $provided
	 * @return bool
	 */
	public function verifyHash( $transaction_id, $original_total, $api_key, $provided ) {
		$provided = strtolower( trim( (string) $provided ) );

		if ( $provided === '' || ! preg_match( '/^[a-f0-9]{32}$/', $provided ) ) {
			return false;
		}

		if ( $api_key === '' || $transaction_id === '' ) {
			return false;
		}

		return hash_equals( $this->computeHash( $transaction_id, $original_total, $api_key ), $provided );
	}

	/* ---------------------------------------------------------------- */
	/* Request payload                                                   */
	/* ---------------------------------------------------------------- */

	/**
	 * Build the documented payment request body for an OpenCart order.
	 *
	 * Dependent fields (lname requires fname, addr2 requires addr1) are
	 * omitted unless their parent is present, and every value is capped at
	 * its documented length.
	 *
	 * @param array $order Row from model_checkout_order->getOrder().
	 * @param string $response_url Public callback URL.
	 * @return array
	 */
	public function buildPayload( array $order, $response_url ) {
		$environment = $this->environment();
		$country     = $this->country();
		$total       = number_format( (float) $order['total'], 2, '.', '' );
		$currency    = $this->orderCurrency( $order );

		$payload = array(
			'account_number' => (int) $this->accountNumber( $environment ),
			'country_code'   => $country,
			'currency'       => $currency,
			'environment'    => $environment,
			'fee_structure'  => $this->feeStructure(),
			'method'         => 'credit_card_co',
			'order_id'       => (string) $order['order_id'],
			'origin'         => 'WaypointOC3',
			'response_url'   => (string) $response_url,
			'total'          => $total,
			'email'          => $this->clip( (string) $order['email'], 50 ),
			'version'        => '1.0.0',
		);

		$first_name = trim( isset( $order['payment_firstname'] ) ? $order['payment_firstname'] : '' );
		$last_name  = trim( isset( $order['payment_lastname'] ) ? $order['payment_lastname'] : '' );
		$address_1  = trim( isset( $order['payment_address_1'] ) ? $order['payment_address_1'] : '' );
		$address_2  = trim( isset( $order['payment_address_2'] ) ? $order['payment_address_2'] : '' );

		if ( $first_name === '' ) {
			$first_name = trim( isset( $order['firstname'] ) ? $order['firstname'] : '' );
		}
		if ( $last_name === '' ) {
			$last_name = trim( isset( $order['lastname'] ) ? $order['lastname'] : '' );
		}

		$optional = array(
			'fname'   => array( $first_name, 30 ),
			'lname'   => array( $last_name, 30 ),
			'addr1'   => array( $address_1, 50 ),
			'addr2'   => array( $address_2, 50 ),
			'city'    => array( trim( isset( $order['payment_city'] ) ? $order['payment_city'] : '' ), 30 ),
			'zipcode' => array( trim( isset( $order['payment_postcode'] ) ? $order['payment_postcode'] : '' ), 10 ),
			'country' => array( strtoupper( trim( isset( $order['payment_iso_code_2'] ) ? $order['payment_iso_code_2'] : '' ) ), 2 ),
		);

		foreach ( $optional as $field => $value ) {
			if ( $field === 'lname' && $first_name === '' ) {
				continue;
			}
			if ( $field === 'addr2' && $address_1 === '' ) {
				continue;
			}
			if ( $value[0] !== '' ) {
				$payload[ $field ] = $this->clip( $value[0], (int) $value[1] );
			}
		}

		$phone = preg_replace( '/[^+0-9]/', '', isset( $order['telephone'] ) ? $order['telephone'] : '' );
		if ( $phone !== '' && preg_match( '/^\+[1-9][0-9]{1,14}$/', $phone ) ) {
			$payload['phone'] = $phone;
		}

		return $payload;
	}

	/**
	 * The message blocking this order, or an empty string when it may proceed.
	 *
	 * WiPay documents a TTD floor of 5 for the Trinidad & Tobago platform (Get
	 * Transaction Metadata returns totals.TTD as minimum 5, maximum 20400).
	 * Sending less would be rejected by WiPay anyway, so the shopper is told
	 * before the request is built instead of after it fails.
	 *
	 * Mirrors the guard already in the WordPress and Magento gateways.
	 *
	 * @param array $order
	 * @return string
	 */
	public function minimumError( array $order ) {
		if ( strtoupper( $this->country() ) !== 'TT' || $this->orderCurrency( $order ) !== 'TTD' ) {
			return '';
		}

		return (float) $order['total'] < 5.00
			? 'WiPay requires a minimum payment of TTD 5.00.'
			: '';
	}

	/**
	 * The order's currency, falling back to the store default.
	 *
	 * Shared with buildPayload so the guard and the payload can never disagree
	 * about which currency is being paid.
	 *
	 * @param array $order
	 * @return string
	 */
	private function orderCurrency( array $order ) {
		return isset( $order['currency_code'] ) && $order['currency_code'] !== ''
			? strtoupper( $order['currency_code'] )
			: strtoupper( (string) $this->config->get( 'config_currency' ) );
	}

	/**
	 * Cut a value to a character limit without breaking a UTF-8 sequence.
	 *
	 * mbstring is not a hard OpenCart requirement, so it is used only when the
	 * extension has it; the fallback trims back to a valid byte boundary.
	 *
	 * @param string $value
	 * @param int $limit
	 * @return string
	 */
	private function clip( $value, $limit ) {
		$value = (string) $value;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $limit );
		}

		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		$value = substr( $value, 0, $limit );

		while ( $value !== '' && ! preg_match( '//u', $value ) ) {
			$value = substr( $value, 0, -1 );
		}

		return $value;
	}

	/**
	 * POST the payload to WiPay.
	 *
	 * @param string $endpoint
	 * @param array $payload
	 * @return array{code:int, body:string} Status code (0 on transport failure) and raw body.
	 */
	public function request( $endpoint, array $payload ) {
		$body = json_encode( $payload );

		if ( $body === false ) {
			return array( 'code' => 0, 'body' => '' );
		}

		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $endpoint );
			curl_setopt_array( $ch, array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => array( 'Accept: application/json', 'Content-Type: application/json; charset=utf-8' ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 25,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
			) );

			$response  = curl_exec( $ch );
			$status    = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$transport = $response === false;
			curl_close( $ch );

			return array( 'code' => $transport ? 0 : $status, 'body' => $transport ? '' : (string) $response );
		}

		$context = stream_context_create( array(
			'http' => array(
				'method'          => 'POST',
				'header'          => "Accept: application/json\r\nContent-Type: application/json; charset=utf-8\r\n",
				'content'         => $body,
				'timeout'         => 25,
				'ignore_errors'   => true,
				'follow_location' => 0,
			),
			'ssl'   => array( 'verify_peer' => true, 'verify_peer_name' => true ),
		) );

		$response = @file_get_contents( $endpoint, false, $context );

		if ( $response === false ) {
			return array( 'code' => 0, 'body' => '' );
		}

		$status = 0;
		if ( isset( $http_response_header ) && is_array( $http_response_header ) ) {
			foreach ( $http_response_header as $header ) {
				if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $matches ) ) {
					$status = (int) $matches[1];
				}
			}
		}

		return array( 'code' => $status, 'body' => (string) $response );
	}

	/* ---------------------------------------------------------------- */
	/* Attempt ledger                                                    */
	/* ---------------------------------------------------------------- */

	/**
	 * Created lazily so a manual file upload works without an install step.
	 *
	 * @return bool
	 */
	public function ensureAttemptTable() {
		static $done = false;

		if ( $done ) {
			return true;
		}

		$prefix = $this->db->escape( DB_PREFIX );

		$this->db->query(
			'CREATE TABLE IF NOT EXISTS `' . $prefix . 'wipay_waypoint_attempt` (
				`id_attempt` int(11) NOT NULL AUTO_INCREMENT,
				`order_id` int(11) NOT NULL,
				`transaction_id` varchar(64) NOT NULL DEFAULT \'\',
				`total` varchar(16) NOT NULL DEFAULT \'\',
				`environment` varchar(16) NOT NULL DEFAULT \'\',
				`status` varchar(16) NOT NULL DEFAULT \'started\',
				`date_added` datetime NOT NULL,
				PRIMARY KEY (`id_attempt`),
				KEY `idx_transaction` (`transaction_id`),
				KEY `idx_order` (`order_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8'
		);

		$done = true;

		return true;
	}

	/**
	 * @param int $order_id
	 * @param string $transaction_id
	 * @param string $total
	 * @param string $environment
	 * @return void
	 */
	public function recordAttempt( $order_id, $transaction_id, $total, $environment ) {
		$this->ensureAttemptTable();

		$this->db->query(
			'INSERT INTO `' . DB_PREFIX . 'wipay_waypoint_attempt`
			 SET `order_id` = "' . (int) $order_id . '",
			     `transaction_id` = "' . $this->db->escape( $transaction_id ) . '",
			     `total` = "' . $this->db->escape( $total ) . '",
			     `environment` = "' . $this->db->escape( $environment ) . '",
			     `status` = "started",
			     `date_added` = NOW()'
		);
	}

	/**
	 * @param string $transaction_id
	 * @return array|false
	 */
	public function findAttempt( $transaction_id ) {
		$this->ensureAttemptTable();

		$query = $this->db->query(
			'SELECT * FROM `' . DB_PREFIX . 'wipay_waypoint_attempt`
			 WHERE `transaction_id` = "' . $this->db->escape( $transaction_id ) . '"
			 ORDER BY `id_attempt` DESC LIMIT 1'
		);

		return $query->num_rows ? $query->row : false;
	}

	/**
	 * @param string $transaction_id
	 * @param string $status
	 * @return void
	 */
	public function updateAttempt( $transaction_id, $status ) {
		$this->ensureAttemptTable();

		$this->db->query(
			'UPDATE `' . DB_PREFIX . 'wipay_waypoint_attempt`
			 SET `status` = "' . $this->db->escape( $status ) . '"
			 WHERE `transaction_id` = "' . $this->db->escape( $transaction_id ) . '"'
		);
	}
}
