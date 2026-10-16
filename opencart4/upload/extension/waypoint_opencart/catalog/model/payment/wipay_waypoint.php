<?php
namespace Opencart\Catalog\Model\Extension\WaypointOpencart\Payment;

/**
 * WiPay Waypoint payment model for OpenCart 4.x.
 *
 * Independent implementation maintained by Waypoint. Not affiliated with,
 * sponsored by, or endorsed by WiPay. Card details are entered on WiPay's
 * hosted checkout page; this extension never sees them.
 *
 * @license GPL-2.0-or-later
 */
class WipayWaypoint extends \Opencart\System\Engine\Model {

	/** Published sandbox test values (WiPay Payments API > Testing). */
	const SANDBOX_ACCOUNT = '1234567890';
	const SANDBOX_KEY     = '123';

	/**
	 * Territory => environment => hostname.
	 *
	 * All five live/sandbox pairs are confirmed against WiPay's Platforms and
	 * Environments page (checked 15 October 2026), and all ten hostnames were
	 * verified reachable over HTTPS. This is no longer an open release item.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $hosts = [
		'BB' => [ 'live' => 'bb.wipayfinancial.com', 'sandbox' => 'bbsb.wipayfinancial.com' ],
		'GD' => [ 'live' => 'gd.wipayfinancial.com', 'sandbox' => 'gdsb.wipayfinancial.com' ],
		'GY' => [ 'live' => 'gy.wipayfinancial.com', 'sandbox' => 'gysb.wipayfinancial.com' ],
		'JM' => [ 'live' => 'jm.wipayfinancial.com', 'sandbox' => 'jmsb.wipayfinancial.com' ],
		'TT' => [ 'live' => 'tt.wipayfinancial.com', 'sandbox' => 'ttsb.wipayfinancial.com' ],
	];

	/**
	 * Offer the method at checkout when enabled.
	 *
	 * @param array $address
	 * @return array
	 */
	public function getMethods(array $address = []): array {
		if (!$this->config->get('payment_wipay_waypoint_status')) {
			return [];
		}

		$name = $this->config->get('payment_wipay_waypoint_title');

		if (!$name) {
			$name = $this->language->get('heading_title');
		}

		return [
			'code'       => 'wipay_waypoint',
			'name'       => $name,
			'option'     => [
				'wipay_waypoint' => [
					'code' => 'wipay_waypoint',
					'name' => $name,
				],
			],
			'sort_order' => (int)$this->config->get('payment_wipay_waypoint_sort_order'),
		];
	}

	/* ---------------------------------------------------------------- */
	/* Configuration                                                     */
	/* ---------------------------------------------------------------- */

	/**
	 * @return string
	 */
	public function title(): string {
		$title = (string)$this->config->get('payment_wipay_waypoint_title');

		return $title !== '' ? $title : 'Credit card (WiPay)';
	}

	/**
	 * @return string
	 */
	public function environment(): string {
		$value = (string)$this->config->get('payment_wipay_waypoint_environment');

		return in_array($value, ['live', 'sandbox'], true) ? $value : 'sandbox';
	}

	/**
	 * @return string
	 */
	public function country(): string {
		return strtoupper((string)$this->config->get('payment_wipay_waypoint_country'));
	}

	/**
	 * @return string
	 */
	public function feeStructure(): string {
		$value = (string)$this->config->get('payment_wipay_waypoint_fee');

		return in_array($value, ['merchant_absorb', 'customer_pay', 'split'], true)
			? $value
			: 'merchant_absorb';
	}

	/**
	 * Sandbox always uses WiPay's published test account.
	 *
	 * @param string $environment
	 * @return string
	 */
	public function accountNumber(string $environment): string {
		if ($environment === 'sandbox') {
			return self::SANDBOX_ACCOUNT;
		}

		return preg_replace('/\D+/', '', (string)$this->config->get('payment_wipay_waypoint_account'));
	}

	/**
	 * Response-verification key. Live keys are read from a PHP constant
	 * defined in config.php and are never stored in the settings table.
	 *
	 * @param string $environment
	 * @return string
	 */
	public function apiKey(string $environment): string {
		if ($environment === 'sandbox') {
			return self::SANDBOX_KEY;
		}

		return defined('WIPAY_WAYPOINT_LIVE_API_KEY') ? (string)WIPAY_WAYPOINT_LIVE_API_KEY : '';
	}

	/* ---------------------------------------------------------------- */
	/* Host map                                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * @param string $country
	 * @param string $environment
	 * @return string Hostname, or an empty string when unknown.
	 */
	public function host(string $country, string $environment): string {
		$country     = strtoupper(trim($country));
		$environment = trim($environment);

		return isset(self::$hosts[$country][$environment])
			? self::$hosts[$country][$environment]
			: '';
	}

	/**
	 * @param string $country
	 * @param string $environment
	 * @return string Full request endpoint, or an empty string when unknown.
	 */
	public function endpoint(string $country, string $environment): string {
		$host = $this->host($country, $environment);

		return $host === '' ? '' : 'https://' . $host . '/plugins/payments/request';
	}

	/**
	 * @param string $url
	 * @return bool True when the URL is a credible WiPay checkout target.
	 */
	public function isValidCheckoutUrl(string $url): bool {
		$parts = parse_url($url);

		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return false;
		}

		if (strtolower($parts['scheme']) !== 'https') {
			return false;
		}

		return (bool)preg_match('/(^|\.)wipayfinancial\.com$/i', $parts['host']);
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
	public function computeHash($transaction_id, $original_total, $api_key): string {
		return md5(
			(string)$transaction_id
			. number_format((float)$original_total, 2, '.', '')
			. (string)$api_key
		);
	}

	/**
	 * @param string $transaction_id
	 * @param float|string $original_total
	 * @param string $api_key
	 * @param string $provided
	 * @return bool
	 */
	public function verifyHash($transaction_id, $original_total, $api_key, string $provided): bool {
		$provided = strtolower(trim($provided));

		if ($provided === '' || !preg_match('/^[a-f0-9]{32}$/', $provided)) {
			return false;
		}

		if ($api_key === '' || $transaction_id === '') {
			return false;
		}

		return hash_equals($this->computeHash($transaction_id, $original_total, $api_key), $provided);
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
	 * @param array $order Row from model_checkout_order->getOrder().
	 * @return string
	 */
	public function minimumError(array $order): string {
		if (strtoupper($this->country()) !== 'TT' || $this->orderCurrency($order) !== 'TTD') {
			return '';
		}

		return (float)($order['total'] ?? 0) < 5.00
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
	private function orderCurrency(array $order): string {
		return !empty($order['currency_code'])
			? strtoupper((string)$order['currency_code'])
			: strtoupper((string)$this->config->get('config_currency'));
	}

	/**
	 * @param array $order Row from model_checkout_order->getOrder().
	 * @param string $response_url Public callback URL.
	 * @return array
	 */
	public function buildPayload(array $order, string $response_url): array {
		$environment = $this->environment();
		$country     = $this->country();
		$total       = number_format((float)($order['total'] ?? 0), 2, '.', '');
		$currency    = $this->orderCurrency($order);

		$payload = [
			'account_number' => (int)$this->accountNumber($environment),
			'country_code'   => $country,
			'currency'       => $currency,
			'environment'    => $environment,
			'fee_structure'  => $this->feeStructure(),
			'method'         => 'credit_card_co',
			'order_id'       => (string)($order['order_id'] ?? ''),
			'origin'         => 'WaypointOC4',
			'response_url'   => $response_url,
			'total'          => $total,
			'email'          => $this->clip((string)($order['email'] ?? ''), 50),
			'version'        => '1.0.0',
		];

		$first_name = trim((string)($order['payment_firstname'] ?? ''));
		$last_name  = trim((string)($order['payment_lastname'] ?? ''));
		$address_1  = trim((string)($order['payment_address_1'] ?? ''));
		$address_2  = trim((string)($order['payment_address_2'] ?? ''));

		if ($first_name === '') {
			$first_name = trim((string)($order['firstname'] ?? ''));
		}
		if ($last_name === '') {
			$last_name = trim((string)($order['lastname'] ?? ''));
		}

		$optional = [
			'fname'   => [$first_name, 30],
			'lname'   => [$last_name, 30],
			'addr1'   => [$address_1, 50],
			'addr2'   => [$address_2, 50],
			'city'    => [trim((string)($order['payment_city'] ?? '')), 30],
			'zipcode' => [trim((string)($order['payment_postcode'] ?? '')), 10],
			'country' => [strtoupper(trim((string)($order['payment_iso_code_2'] ?? ''))), 2],
		];

		foreach ($optional as $field => $value) {
			if ($field === 'lname' && $first_name === '') {
				continue;
			}
			if ($field === 'addr2' && $address_1 === '') {
				continue;
			}
			if ($value[0] !== '') {
				$payload[$field] = $this->clip($value[0], (int)$value[1]);
			}
		}

		$phone = preg_replace('/[^+0-9]/', '', (string)($order['telephone'] ?? ''));

		if ($phone !== '' && preg_match('/^\+[1-9][0-9]{1,14}$/', $phone)) {
			$payload['phone'] = $phone;
		}

		return $payload;
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
	private function clip(string $value, int $limit): string {
		if (function_exists('mb_substr')) {
			return mb_substr($value, 0, $limit);
		}

		if (strlen($value) <= $limit) {
			return $value;
		}

		$value = substr($value, 0, $limit);

		while ($value !== '' && !preg_match('//u', $value)) {
			$value = substr($value, 0, -1);
		}

		return $value;
	}

	/**
	 * POST the payload to WiPay.
	 *
	 * @param string $endpoint
	 * @param array $payload
	 * @return array{code: int, body: string} Status code (0 on transport failure) and raw body.
	 */
	public function request(string $endpoint, array $payload): array {
		$body = json_encode($payload);

		if ($body === false) {
			return ['code' => 0, 'body' => ''];
		}

		if (function_exists('curl_init')) {
			$ch = curl_init($endpoint);
			curl_setopt_array($ch, [
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json; charset=utf-8'],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 25,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
			]);

			$response  = curl_exec($ch);
			$status    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$transport = $response === false;
			curl_close($ch);

			return ['code' => $transport ? 0 : $status, 'body' => $transport ? '' : (string)$response];
		}

		$context = stream_context_create([
			'http' => [
				'method'          => 'POST',
				'header'          => "Accept: application/json\r\nContent-Type: application/json; charset=utf-8\r\n",
				'content'         => $body,
				'timeout'         => 25,
				'ignore_errors'   => true,
				'follow_location' => 0,
			],
			'ssl'   => ['verify_peer' => true, 'verify_peer_name' => true],
		]);

		$response = @file_get_contents($endpoint, false, $context);

		if ($response === false) {
			return ['code' => 0, 'body' => ''];
		}

		$status = 0;

		if (isset($http_response_header) && is_array($http_response_header)) {
			foreach ($http_response_header as $header) {
				if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
					$status = (int)$matches[1];
				}
			}
		}

		return ['code' => $status, 'body' => (string)$response];
	}

	/* ---------------------------------------------------------------- */
	/* Attempt ledger                                                    */
	/* ---------------------------------------------------------------- */

	/**
	 * Created lazily so the extension works whether or not the admin
	 * enabled it through an install hook.
	 *
	 * @return bool
	 */
	public function ensureAttemptTable(): bool {
		static $done = false;

		if ($done) {
			return true;
		}

		$this->db->query(
			'CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'wipay_waypoint_attempt` (
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
	public function recordAttempt(int $order_id, string $transaction_id, string $total, string $environment): void {
		$this->ensureAttemptTable();

		$this->db->query(
			'INSERT INTO `' . DB_PREFIX . 'wipay_waypoint_attempt`
			 SET `order_id` = "' . (int)$order_id . '",
			     `transaction_id` = "' . $this->db->escape($transaction_id) . '",
			     `total` = "' . $this->db->escape($total) . '",
			     `environment` = "' . $this->db->escape($environment) . '",
			     `status` = "started",
			     `date_added` = NOW()'
		);
	}

	/**
	 * @param string $transaction_id
	 * @return array|false
	 */
	public function findAttempt(string $transaction_id) {
		$this->ensureAttemptTable();

		$query = $this->db->query(
			'SELECT * FROM `' . DB_PREFIX . 'wipay_waypoint_attempt`
			 WHERE `transaction_id` = "' . $this->db->escape($transaction_id) . '"
			 ORDER BY `id_attempt` DESC LIMIT 1'
		);

		return $query->num_rows ? $query->row : false;
	}

	/**
	 * @param string $transaction_id
	 * @param string $status
	 * @return void
	 */
	public function updateAttempt(string $transaction_id, string $status): void {
		$this->ensureAttemptTable();

		$this->db->query(
			'UPDATE `' . DB_PREFIX . 'wipay_waypoint_attempt`
			 SET `status` = "' . $this->db->escape($status) . '"
			 WHERE `transaction_id` = "' . $this->db->escape($transaction_id) . '"'
		);
	}
}
