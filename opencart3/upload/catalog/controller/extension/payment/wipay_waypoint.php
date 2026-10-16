<?php
/**
 * Hosted-checkout controller for WiPay on OpenCart 3.x.
 *
 * Independent implementation maintained by Waypoint. Not affiliated with,
 * sponsored by, or endorsed by WiPay.
 *
 * @license GPL-2.0-or-later
 */

class ControllerExtensionPaymentWipayWaypoint extends Controller {

	/**
	 * Renders the confirmation block shown in the OpenCart order summary.
	 *
	 * @return string
	 */
	public function index() {
		$this->load->language( 'extension/payment/wipay_waypoint' );

		$data = array(
			'description'      => $this->getSetting( 'description', $this->language->get( 'text_description' ) ),
			'button_confirm'   => $this->language->get( 'button_confirm' ),
		);

		return $this->load->view( 'extension/payment/wipay_waypoint', $data );
	}

	/**
	 * Called over AJAX when the shopper confirms the order. The order has
	 * already been created by OpenCart at this point; this only asks WiPay
	 * for a hosted checkout URL and hands it back for the browser redirect.
	 *
	 * @return void
	 */
	public function confirm() {
		$this->load->language( 'extension/payment/wipay_waypoint' );
		$this->load->model( 'checkout/order' );

		$order_id = isset( $this->session->data['order_id'] ) ? (int) $this->session->data['order_id'] : 0;

		if ( ! $order_id ) {
			$this->errorJson( 'Order not found in the session.' );
			return;
		}

		if (
			! isset( $this->session->data['payment_method']['code'] )
			|| $this->session->data['payment_method']['code'] !== 'wipay_waypoint'
		) {
			$this->errorJson( 'WiPay is not the selected payment method.' );
			return;
		}

		$order = $this->model_checkout_order->getOrder( $order_id );

		if ( ! $order ) {
			$this->errorJson( 'Order not found.' );
			return;
		}

		/** @var ModelExtensionPaymentWipayWaypoint $model */
		$this->load->model( 'extension/payment/wipay_waypoint' );
		$model       = $this->model_extension_payment_wipay_waypoint;
		$country     = $model->country();
		$environment = $model->environment();
		$endpoint   = $model->endpoint( $country, $environment );

		if ( $endpoint === '' ) {
			$this->errorJson( 'WiPay is not configured for this territory yet. Set the country in Admin.' );
			return;
		}

		if ( $model->apiKey( $environment ) === '' ) {
			$this->errorJson( 'The WiPay response verification key is not set.' );
			return;
		}

		$blocked = $model->minimumError( $order );

		if ( $blocked !== '' ) {
			$this->errorJson( $blocked );
			return;
		}

		$transaction_id = 'oc3' . $order_id . '_' . uniqid( '', false );

		$response_url = $this->url->link( 'extension/payment/wipay_waypoint/callback', '', true );

		$payload = $model->buildPayload( $order, $response_url );
		$payload['transaction_id'] = $transaction_id;

		$result = $model->request( $endpoint, $payload );

		if ( $result['code'] !== 200 ) {
			$this->errorJson(
				$result['code'] === 0
					? 'WiPay could not be reached.'
					: 'WiPay rejected the payment request (HTTP ' . $result['code'] . ').'
			);
			return;
		}

		$decoded = json_decode( $result['body'], true );

		if ( ! is_array( $decoded ) || empty( $decoded['data']['url'] ) || empty( $decoded['status'] ) ) {
			$this->errorJson( 'WiPay returned an unexpected response.' );
			return;
		}

		$checkout_url = (string) $decoded['data']['url'];

		if ( ! $model->isValidCheckoutUrl( $checkout_url ) ) {
			$this->errorJson( 'WiPay returned an unexpected checkout address.' );
			return;
		}

		$model->recordAttempt(
			$order_id,
			$transaction_id,
			number_format( (float) $order['total'], 2, '.', '' ),
			$environment
		);

		$this->model_checkout_order->addOrderHistory(
			$order_id,
			(int) $this->config->get( 'config_order_status_id' ),
			'Waiting for WiPay payment. Transaction ' . $transaction_id,
			false
		);

		$this->json( array( 'redirect' => $checkout_url ) );
	}

	/**
	 * WiPay redirects the shopper back here. Everything needed to verify the
	 * result is read from the recorded attempt, never from the query string.
	 *
	 * @return void
	 */
	public function callback() {
		$this->load->language( 'extension/payment/wipay_waypoint' );
		$this->load->model( 'checkout/order' );

		$transaction_id = isset( $_GET['transaction_id'] ) ? (string) $_GET['transaction_id'] : '';

		if ( $transaction_id === '' ) {
			$this->errorJson( 'Missing transaction identifier.' );
			return;
		}

		/** @var ModelExtensionPaymentWipayWaypoint $model */
		$this->load->model( 'extension/payment/wipay_waypoint' );
		$model   = $this->model_extension_payment_wipay_waypoint;
		$attempt = $model->findAttempt( $transaction_id );

		if ( ! $attempt ) {
			$this->errorJson( 'Unknown transaction.' );
			return;
		}

		$order_id = (int) $attempt['order_id'];
		$order    = $this->model_checkout_order->getOrder( $order_id );

		if ( ! $order ) {
			$this->errorJson( 'Order not found.' );
			return;
		}

		$environment = $attempt['environment'];
		$api_key     = $model->apiKey( $environment );
		$provided    = isset( $_GET['hash'] ) ? (string) $_GET['hash'] : '';

		if ( ! $model->verifyHash( $transaction_id, $attempt['total'], $api_key, $provided ) ) {
			$this->errorJson( 'Invalid response signature.' );
			return;
		}

		$status = isset( $_GET['status'] ) ? strtolower( (string) $_GET['status'] ) : '';
		$returned_total = isset( $_GET['total'] ) ? (string) $_GET['total'] : '';

		$pending_status_id = (int) $this->config->get( 'config_order_status_id' );

		if ( $status === 'approved' ) {
			if ( (int) $order['order_status_id'] !== $pending_status_id ) {
				$model->updateAttempt( $transaction_id, 'late' );
				$this->model_checkout_order->addOrderHistory(
					$order_id,
					(int) $order['order_status_id'],
					'Late WiPay payment notification for a completed or cancelled order. Transaction ' . $transaction_id
					. ' returned total ' . $returned_total . '. Needs manual review.',
					true
				);
				$this->json( array( 'redirect' => $this->url->link( 'checkout/success', '', true ) ) );
				return;
			}

			$model->updateAttempt( $transaction_id, 'paid' );

			// A store with a dedicated "processing" status uses it when set.
			$processed = (int) $this->config->get( 'wipay_waypoint_order_status_paid' );

			$this->model_checkout_order->addOrderHistory(
				$order_id,
				$processed > 0 ? $processed : 2,
				'Payment received through WiPay. Transaction ' . $transaction_id,
				true
			);

			$this->json( array( 'redirect' => $this->url->link( 'checkout/success', '', true ) ) );
			return;
		}

		if ( $status === 'failed' || $status === 'cancelled' ) {
			if ( (int) $order['order_status_id'] !== $pending_status_id ) {
				$this->errorJson( 'This order has already moved on.' );
				return;
			}

			$model->updateAttempt( $transaction_id, 'cancelled' );

			$this->model_checkout_order->addOrderHistory(
				$order_id,
				(int) $this->config->get( 'config_cancel_status_id' ),
				'WiPay payment ' . $status . '. Transaction ' . $transaction_id,
				true
			);

			$this->json( array( 'redirect' => $this->url->link( 'checkout/checkout', '', true ) ) );
			return;
		}

		$this->errorJson( 'Unrecognised payment status.' );
	}

	/* ---------------------------------------------------------------- */

	private function getSetting( $key, $default = '' ) {
		$value = $this->config->get( 'payment_wipay_waypoint_' . $key );

		return ( $value === null || $value === '' ) ? $default : $value;
	}

	private function errorJson( $message ) {
		$this->json( array( 'error' => $message ) );
	}

	private function json( array $data ) {
		$this->response->addHeader( 'Content-Type: application/json' );
		$this->response->setOutput( json_encode( $data ) );
	}
}
