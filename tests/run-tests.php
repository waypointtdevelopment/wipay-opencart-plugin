<?php
/**
 * Contract tests for the WiPay OpenCart extensions.
 *
 * Both the OpenCart 3 and OpenCart 4 models are loaded against stubbed
 * OpenCart classes and pushed through the same battery, so the two versions
 * cannot drift apart on the parts that talk to WiPay.
 *
 * Run: php tests/run-tests.php
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/stubs.php';
require __DIR__ . '/stubs-opencart4.php';

require __DIR__ . '/../opencart3/upload/catalog/model/extension/payment/wipay_waypoint.php';
require __DIR__ . '/../opencart4/upload/extension/waypoint_opencart/catalog/model/payment/wipay_waypoint.php';

$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;

function check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		$GLOBALS['pass']++;
		return;
	}

	$GLOBALS['fail']++;
	echo "FAIL: $label\n";
	echo '  expected: ' . var_export( $expected, true ) . "\n";
	echo '  actual:   ' . var_export( $actual, true ) . "\n";
}

function truthy( string $label, bool $value ): void {
	check( $label, $value, true );
}

/* ---------------------------------------------------------------- */
/* Harnesses                                                          */
/* ---------------------------------------------------------------- */

class Oc3Harness extends ModelExtensionPaymentWipayWaypoint {
	public function __construct( FakeConfig $config, FakeDb $db, FakeLanguage $language ) {
		$this->config   = $config;
		$this->db       = $db;
		$this->language = $language;
	}
}

class Oc4Harness extends Opencart\Catalog\Model\Extension\WaypointOpencart\Payment\WipayWaypoint {
	public function __construct( FakeConfig $config, FakeDb $db, FakeLanguage $language ) {
		$this->config   = $config;
		$this->db       = $db;
		$this->language = $language;
	}
}

/**
 * @return array{0: object, 1: FakeConfig, 2: FakeDb, 3: string}
 */
function make_model( string $version, array $settings = array() ): array {
	$config          = new FakeConfig();
	$db              = new FakeDb();
	$language        = new FakeLanguage();
	$config->data    = array_merge(
		array(
			'payment_wipay_waypoint_status'      => 1,
			'payment_wipay_waypoint_title'       => 'Credit card (WiPay)',
			'payment_wipay_waypoint_text'        => '',
			'payment_wipay_waypoint_environment' => 'sandbox',
			'payment_wipay_waypoint_country'     => 'TT',
			'payment_wipay_waypoint_fee'         => 'merchant_absorb',
			'payment_wipay_waypoint_account'     => '9912345678',
			'payment_wipay_waypoint_sort_order'  => 1,
			'config_currency'                    => 'TTD',
		),
		$settings
	);

	$model = $version === 'oc3'
		? new Oc3Harness( $config, $db, $language )
		: new Oc4Harness( $config, $db, $language );

	return array( $model, $config, $db, $version );
}

/**
 * The shared battery. Every assertion here must hold for both versions.
 *
 * @param object $model
 * @param string $version
 * @return void
 */
function run_shared_battery( $model, string $version ): void {
	$origin = $version === 'oc3' ? 'WaypointOC3' : 'WaypointOC4';

	/* --- Host map ------------------------------------------------- */

	$expected_hosts = array(
		array( 'BB', 'live', 'bb.wipayfinancial.com' ),
		array( 'BB', 'sandbox', 'bbsb.wipayfinancial.com' ),
		array( 'GD', 'live', 'gd.wipayfinancial.com' ),
		array( 'GD', 'sandbox', 'gdsb.wipayfinancial.com' ),
		array( 'GY', 'live', 'gy.wipayfinancial.com' ),
		array( 'GY', 'sandbox', 'gysb.wipayfinancial.com' ),
		array( 'JM', 'live', 'jm.wipayfinancial.com' ),
		array( 'JM', 'sandbox', 'jmsb.wipayfinancial.com' ),
		array( 'TT', 'live', 'tt.wipayfinancial.com' ),
		array( 'TT', 'sandbox', 'ttsb.wipayfinancial.com' ),
	);

	foreach ( $expected_hosts as $host_case ) {
		check(
			"$version host {$host_case[0]}/{$host_case[1]}",
			$model->host( $host_case[0], $host_case[1] ),
			$host_case[2]
		);
	}

	check( "$version host unknown country", $model->host( 'US', 'sandbox' ), '' );
	check( "$version host lowercase country", $model->host( 'tt', 'sandbox' ), 'ttsb.wipayfinancial.com' );
	check( "$version host unknown environment", $model->host( 'TT', 'staging' ), '' );

	check(
		"$version endpoint",
		$model->endpoint( 'TT', 'sandbox' ),
		'https://ttsb.wipayfinancial.com/plugins/payments/request'
	);
	check( "$version endpoint unknown country", $model->endpoint( 'US', 'sandbox' ), '' );

	/* --- Minimum amount guard ------------------------------------ */

	$too_small = array(
		'order_id'      => 7,
		'email'         => 'buyer@example.com',
		'total'         => '4.99',
		'currency_code' => 'TTD',
	);

	check(
		"$version blocks a sub-minimum TTD order",
		$model->minimumError( $too_small ),
		'WiPay requires a minimum payment of TTD 5.00.'
	);
	check( "$version allows exactly the minimum", $model->minimumError( array( 'total' => '5.00', 'currency_code' => 'TTD' ) ), '' );
	check( "$version allows above the minimum", $model->minimumError( array( 'total' => '5.01', 'currency_code' => 'TTD' ) ), '' );

	/* The guard is scoped to the TT platform, so other currencies are free. */
	check( "$version does not block a sub-minimum USD order", $model->minimumError( array( 'total' => '4.99', 'currency_code' => 'USD' ) ), '' );

	/* An order with no currency falls back to the store default, which the
	 * shared fixture sets to TTD, so the guard must still apply. */
	check( "$version falls back to the store currency", $model->minimumError( array( 'total' => '4.99' ) ), 'WiPay requires a minimum payment of TTD 5.00.' );

	/* A non-TT platform is never blocked by the TTD floor. */
	list( $other_platform ) = make_model( $version, array( 'payment_wipay_waypoint_country' => 'JM' ) );
	check( "$version a non-TT platform is not blocked", $other_platform->minimumError( $too_small ), '' );

	/* The guard and the payload must agree on the currency. */
	check( "$version payload currency still resolves", $model->buildPayload( $too_small, 'https://ttsb.wipayfinancial.com/callback' )['currency'], 'TTD' );

	/* --- Credentials ---------------------------------------------- */

	check( "$version sandbox account is pinned", $model->accountNumber( 'sandbox' ), '1234567890' );
	check( "$version sandbox key is pinned", $model->apiKey( 'sandbox' ), '123' );
	check( "$version live account strips formatting", $model->accountNumber( 'live' ), '9912345678' );
	check( "$version live key comes from the constant", $model->apiKey( 'live' ), 'livekey_987654321' );

	/* --- Signature ------------------------------------------------ */

	$hash = $model->computeHash( 'tx-1', 10, '123' );
	check( "$version hash shape", (bool) preg_match( '/^[a-f0-9]{32}$/', $hash ), true );
	check( "$version hash value", $hash, md5( 'tx-1' . '10.00' . '123' ) );

	truthy( "$version verify accepts a matching hash", $model->verifyHash( 'tx-1', 10, '123', $hash ) );
	truthy( "$version verify accepts uppercase", $model->verifyHash( 'tx-1', 10, '123', strtoupper( $hash ) ) );

	check( "$version verify rejects a wrong key", $model->verifyHash( 'tx-1', 10, '124', $hash ), false );
	check( "$version verify rejects a wrong total", $model->verifyHash( 'tx-1', 11, '123', $hash ), false );
	check( "$version verify rejects a tampered transaction id", $model->verifyHash( 'tx-2', 10, '123', $hash ), false );
	check( "$version verify rejects an empty key", $model->verifyHash( 'tx-1', 10, '', $hash ), false );
	check( "$version verify rejects an empty hash", $model->verifyHash( 'tx-1', 10, '123', '' ), false );
	check( "$version verify rejects a non-hex hash", $model->verifyHash( 'tx-1', 10, '123', str_repeat( 'z', 32 ) ), false );
	check( "$version verify rejects a short hash", $model->verifyHash( 'tx-1', 10, '123', 'abc' ), false );

	/* Total normalisation drives the hash input. */
	check( "$version total 10", $model->computeHash( 't', 10, 'k' ), md5( 't10.00k' ) );
	check( "$version total 10.456 rounds", $model->computeHash( 't', 10.456, 'k' ), md5( 't10.46k' ) );
	check( "$version total 1000.5 pads", $model->computeHash( 't', 1000.5, 'k' ), md5( 't1000.50k' ) );
	check( "$version total accepts a string", $model->computeHash( 't', '42', 'k' ), md5( 't42.00k' ) );

	/* --- Checkout URL allowlist ----------------------------------- */

	truthy( "$version accepts a sandbox host", $model->isValidCheckoutUrl( 'https://ttsb.wipayfinancial.com/pay?tx=1' ) );
	truthy( "$version accepts a live host", $model->isValidCheckoutUrl( 'https://tt.wipayfinancial.com/pay' ) );
	truthy( "$version accepts a subdomain", $model->isValidCheckoutUrl( 'https://checkout.wipayfinancial.com/pay' ) );

	check( "$version rejects plain http", $model->isValidCheckoutUrl( 'http://tt.wipayfinancial.com/pay' ), false );
	check( "$version rejects an unrelated host", $model->isValidCheckoutUrl( 'https://evil.example.com/pay' ), false );
	check(
		"$version rejects a host-suffix trick",
		$model->isValidCheckoutUrl( 'https://wipayfinancial.com.evil.example.com/pay' ),
		false
	);
	check( "$version rejects a lookalike with a hyphen", $model->isValidCheckoutUrl( 'https://wipayfinancial.com-x.example.com/pay' ), false );
	check( "$version rejects an empty url", $model->isValidCheckoutUrl( '' ), false );
	check( "$version rejects a relative url", $model->isValidCheckoutUrl( '/pay' ), false );

	/* --- Request payload ------------------------------------------ */

	$order = array(
		'order_id'            => 42,
		'email'               => 'buyer@example.com',
		'total'               => '123.45',
		'currency_code'       => 'TTD',
		'payment_firstname'   => 'Ann',
		'payment_lastname'    => 'Lee',
		'payment_address_1'   => '10 Main Street',
		'payment_address_2'   => 'Unit 4',
		'payment_city'        => 'Port of Spain',
		'payment_postcode'    => '00000',
		'payment_iso_code_2'  => 'TT',
		'telephone'           => '+18685551234',
	);

	$payload = $model->buildPayload( $order, 'https://ttsb.wipayfinancial.com/callback' );

	check( "$version payload account_number is an integer", $payload['account_number'], 1234567890 );
	check( "$version payload country_code", $payload['country_code'], 'TT' );
	check( "$version payload currency", $payload['currency'], 'TTD' );
	check( "$version payload environment", $payload['environment'], 'sandbox' );
	check( "$version payload fee_structure", $payload['fee_structure'], 'merchant_absorb' );
	check( "$version payload method", $payload['method'], 'credit_card_co' );
	check( "$version payload order_id is a string", $payload['order_id'], '42' );
	check( "$version payload origin", $payload['origin'], $origin );
	check( "$version payload response_url", $payload['response_url'], 'https://ttsb.wipayfinancial.com/callback' );
	check( "$version payload total", $payload['total'], '123.45' );
	check( "$version payload email", $payload['email'], 'buyer@example.com' );
	check( "$version payload version", $payload['version'], '1.0.0' );
	check( "$version payload fname", $payload['fname'], 'Ann' );
	check( "$version payload lname", $payload['lname'], 'Lee' );
	check( "$version payload addr1", $payload['addr1'], '10 Main Street' );
	check( "$version payload addr2", $payload['addr2'], 'Unit 4' );
	check( "$version payload city", $payload['city'], 'Port of Spain' );
	check( "$version payload zipcode", $payload['zipcode'], '00000' );
	check( "$version payload country field", $payload['country'], 'TT' );
	check( "$version payload phone", $payload['phone'], '+18685551234' );

	/* Dependent fields drop out when their parent is missing. */
	$sparse = array(
		'order_id' => 7,
		'email'    => 'b@example.com',
		'total'    => '10.00',
	);
	$sparse_payload = $model->buildPayload( $sparse, 'https://ttsb.wipayfinancial.com/callback' );

	check( "$version lname dropped without fname", isset( $sparse_payload['lname'] ), false );
	check( "$version addr2 dropped without addr1", isset( $sparse_payload['addr2'] ), false );
	check( "$version total still two decimals", $sparse_payload['total'], '10.00' );
	check( "$version currency falls back to store currency", $sparse_payload['currency'], 'TTD' );

	/* Length caps. */
	$long = $order;
	$long['payment_firstname']   = str_repeat( 'A', 60 );
	$long['payment_lastname']    = str_repeat( 'B', 60 );
	$long['payment_address_1']   = str_repeat( 'C', 90 );
	$long['payment_address_2']   = str_repeat( 'D', 90 );
	$long['payment_city']        = str_repeat( 'E', 60 );
	$long['payment_postcode']    = str_repeat( 'F', 30 );
	$long['payment_iso_code_2']  = 'TTO';
	$long['email']               = str_repeat( 'g', 90 ) . '@example.com';

	$long_payload = $model->buildPayload( $long, 'https://ttsb.wipayfinancial.com/callback' );

	check( "$version fname capped", strlen( $long_payload['fname'] ), 30 );
	check( "$version lname capped", strlen( $long_payload['lname'] ), 30 );
	check( "$version addr1 capped", strlen( $long_payload['addr1'] ), 50 );
	check( "$version addr2 capped", strlen( $long_payload['addr2'] ), 50 );
	check( "$version city capped", strlen( $long_payload['city'] ), 30 );
	check( "$version zipcode capped", strlen( $long_payload['zipcode'] ), 10 );
	check( "$version country capped", $long_payload['country'], 'TT' );
	check( "$version email capped", strlen( $long_payload['email'] ), 50 );

	/* Phone normalisation: only a valid E.164 number is sent. */
	foreach ( array(
		'868-555-1234'   => false,
		'8685551234'     => false,
		'+1 (868) 555-1234' => true,
		'+0123456789'    => false,
		'+'              => false,
		''               => false,
	) as $phone => $expected ) {
		$phone_order          = $order;
		$phone_order['telephone'] = $phone;
		$phone_payload        = $model->buildPayload( $phone_order, 'https://ttsb.wipayfinancial.com/callback' );
		check(
			"$version phone '$phone' " . ( $expected ? 'sent' : 'dropped' ),
			isset( $phone_payload['phone'] ),
			$expected
		);
	}

	/* Whitespace-only values count as missing. */
	$blank = $order;
	$blank['payment_firstname'] = '   ';
	$blank['payment_lastname']  = '   ';
	$blank['payment_address_1'] = '   ';
	$blank_payload              = $model->buildPayload( $blank, 'https://ttsb.wipayfinancial.com/callback' );

	check( "$version blank fname dropped", isset( $blank_payload['fname'] ), false );
	check( "$version blank lname dropped", isset( $blank_payload['lname'] ), false );
	check( "$version blank addr1 dropped", isset( $blank_payload['addr1'] ), false );
	check( "$version blank addr2 dropped", isset( $blank_payload['addr2'] ), false );

	/* --- Attempt ledger ------------------------------------------- */

	$db = new FakeDb();
	$model->db = $db;

	$model->recordAttempt( 42, 'tx-abc', '123.45', 'sandbox' );
	check( "$version ledger creates the table first", $db->likeStatements()[0], 'create' );
	check( "$version ledger records the attempt", $db->likeStatements()[1], 'insert' );

	$model->findAttempt( 'tx-abc' );
	$statements        = $db->likeStatements();
	$last_statement    = array_pop( $statements );
	check( "$version ledger reads by transaction", $last_statement, 'select' );

	$model->updateAttempt( 'tx-abc', 'paid' );
	$statements     = $db->likeStatements();
	$last_statement = array_pop( $statements );
	check( "$version ledger updates by transaction", $last_statement, 'update' );

	$sql = implode( "\n", $db->queries );
	check( "$version ledger pins the recorded total", strpos( $sql, '`total` = "123.45"' ) !== false, true );
	check( "$version ledger pins the environment", strpos( $sql, '`environment` = "sandbox"' ) !== false, true );

	/* --- Environment and country settings -------------------------- */

	( function () {
		$this->config->data['payment_wipay_waypoint_environment'] = 'nonsense';
	} )->call( $model );
	check( "$version unknown environment falls back to sandbox", $model->environment(), 'sandbox' );

	( function () {
		$this->config->data['payment_wipay_waypoint_environment'] = 'live';
	} )->call( $model );
	check( "$version live environment honoured", $model->environment(), 'live' );

	( function () {
		$this->config->data['payment_wipay_waypoint_fee'] = 'nonsense';
	} )->call( $model );
	check( "$version unknown fee falls back to merchant_absorb", $model->feeStructure(), 'merchant_absorb' );

	( function () {
		$this->config->data['payment_wipay_waypoint_fee'] = 'split';
	} )->call( $model );
	check( "$version split fee honoured", $model->feeStructure(), 'split' );

	( function () {
		unset( $this->config->data['payment_wipay_waypoint_country'] );
	} )->call( $model );
	check( "$version missing country blocks the endpoint", $model->endpoint( $model->country(), 'sandbox' ), '' );
}

/* ---------------------------------------------------------------- */
/* Version-specific behaviour                                         */
/* ---------------------------------------------------------------- */

function run_oc3_specific(): void {
	list( $model, $config ) = make_model( 'oc3' );

	$method = $model->getMethod( array(), 100 );
	check( 'oc3 method code', $method['code'], 'wipay_waypoint' );
	check( 'oc3 method title', $method['title'], 'Credit card (WiPay)' );
	check( 'oc3 method sort order', $method['sort_order'], 1 );
	check( 'oc3 method has no terms', $method['terms'], '' );

	$config->data['payment_wipay_waypoint_title'] = 'Pay by card';
	check( 'oc3 method honours a custom title', $model->getMethod( array(), 100 )['title'], 'Pay by card' );

	$config->data['payment_wipay_waypoint_status'] = 0;
	check( 'oc3 method hidden when disabled', $model->getMethod( array(), 100 ), array() );
}

function run_oc4_specific(): void {
	list( $model, $config ) = make_model( 'oc4' );

	$methods = $model->getMethods( array() );
	check( 'oc4 method code', $methods['code'], 'wipay_waypoint' );
	check( 'oc4 method name', $methods['name'], 'Credit card (WiPay)' );
	check( 'oc4 method sort order', $methods['sort_order'], 1 );
	check( 'oc4 method offers one option', count( $methods['option'] ), 1 );
	check( 'oc4 option code', $methods['option']['wipay_waypoint']['code'], 'wipay_waypoint' );

	$config->data['payment_wipay_waypoint_title'] = 'Pay by card';
	check( 'oc4 method honours a custom title', $model->getMethods( array() )['name'], 'Pay by card' );

	$config->data['payment_wipay_waypoint_status'] = 0;
	check( 'oc4 method hidden when disabled', $model->getMethods( array() ), array() );
}

/**
 * Guard against a bug the unit tests cannot reach: OpenCart's Loader::model()
 * registers a proxy in the registry and returns nothing in both 3.x and 4.x,
 * so assigning its return value yields null and every later call fatals.
 *
 * @return void
 */
function run_loader_guard(): void {
	$controllers = array(
		'oc3' => __DIR__ . '/../opencart3/upload/catalog/controller/extension/payment/wipay_waypoint.php',
		'oc4' => __DIR__ . '/../opencart4/upload/extension/waypoint_opencart/catalog/controller/payment/wipay_waypoint.php',
	);

	$expected_property = array(
		'oc3' => 'model_extension_payment_wipay_waypoint',
		'oc4' => 'model_extension_waypoint_opencart_payment_wipay_waypoint',
	);

	foreach ( $controllers as $version => $path ) {
		$source = file_get_contents( $path );

		check( "$version controller readable", $source !== false && strlen( $source ) > 0, true );
		check(
			"$version never assigns load->model() to a variable",
			(bool) preg_match( '/=\s*\$this->load->model\s*\(/', $source ),
			false
		);
		check(
			"$version reads the model from the registry",
			(bool) preg_match(
				'/=\s*\$this->' . preg_quote( $expected_property[ $version ], '/' ) . '\b/',
				$source
			),
			true
		);
		$load_position = strpos( $source, '$this->load->model(' );
		$read_position = strpos( $source, '$this->' . $expected_property[ $version ] );

		check(
			"$version loads the model before reading it",
			$load_position !== false && $read_position !== false && $load_position < $read_position,
			true
		);
	}
}

/* ---------------------------------------------------------------- */

foreach ( array( 'oc3', 'oc4' ) as $version ) {
	list( $model ) = make_model( $version );
	run_shared_battery( $model, $version );
}

run_oc3_specific();
run_oc4_specific();
run_loader_guard();

echo "\n{$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";

exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
