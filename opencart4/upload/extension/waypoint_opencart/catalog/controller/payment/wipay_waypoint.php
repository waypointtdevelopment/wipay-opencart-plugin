<?php
namespace Opencart\Catalog\Controller\Extension\WaypointOpencart\Payment;

/**
 * Hosted-checkout controller for WiPay on OpenCart 4.x.
 *
 * Independent implementation maintained by Waypoint. Not affiliated with,
 * sponsored by, or endorsed by WiPay.
 *
 * @license GPL-2.0-or-later
 */
class WipayWaypoint extends \Opencart\System\Engine\Controller {

	/**
	 * Renders the confirmation block shown in the OpenCart order summary.
	 *
	 * @return string
	 */
	public function index(): string {
		$this->load->language('extension/waypoint_opencart/payment/wipay_waypoint');

		$data['description']    = (string)$this->config->get('payment_wipay_waypoint_text');
		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['text_loading']   = $this->language->get('text_loading');

		if ($data['description'] === '') {
			$data['description'] = $this->language->get('text_description');
		}

		return $this->load->view('extension/waypoint_opencart/payment/wipay_waypoint', $data);
	}

	/**
	 * Called over AJAX when the shopper confirms the order. The order has
	 * already been created by OpenCart at this point; this only asks WiPay
	 * for a hosted checkout URL and hands it back for the browser redirect.
	 *
	 * @return void
	 */
	public function confirm(): void {
		$this->load->language('extension/waypoint_opencart/payment/wipay_waypoint');
		$this->load->model('checkout/order');

		$order_id = isset($this->session->data['order_id']) ? (int)$this->session->data['order_id'] : 0;

		if (!$order_id) {
			$this->errorJson('Order not found in the session.');
			return;
		}

		$selected = (string)($this->session->data['payment_method']['code'] ?? '');

		if ($selected !== 'wipay_waypoint' && strpos($selected, 'wipay_waypoint') !== 0) {
			$this->errorJson('WiPay is not the selected payment method.');
			return;
		}

		$order = $this->model_checkout_order->getOrder($order_id);

		if (!$order) {
			$this->errorJson('Order not found.');
			return;
		}

		/** @var \Opencart\Catalog\Model\Extension\WaypointOpencart\Payment\WipayWaypoint $model */
		$this->load->model('extension/waypoint_opencart/payment/wipay_waypoint');
		$model       = $this->model_extension_waypoint_opencart_payment_wipay_waypoint;
		$country     = $model->country();
		$environment = $model->environment();
		$endpoint    = $model->endpoint($country, $environment);

		if ($endpoint === '') {
			$this->errorJson('WiPay is not configured for this territory yet. Set the country in Admin.');
			return;
		}

		if ($model->apiKey($environment) === '') {
			$this->errorJson('The WiPay response verification key is not set.');
			return;
		}

		$blocked = $model->minimumError($order);

		if ($blocked !== '') {
			$this->errorJson($blocked);
			return;
		}

		$transaction_id = 'oc4' . $order_id . '_' . uniqid('', false);

		$response_url = $this->url->link(
			'extension/waypoint_opencart/payment/wipay_waypoint/callback',
			'language=' . $this->config->get('config_language'),
			true
		);

		$payload                             = $model->buildPayload($order, $response_url);
		$payload['transaction_id']           = $transaction_id;

		$result = $model->request($endpoint, $payload);

		if ($result['code'] !== 200) {
			$this->errorJson(
				$result['code'] === 0
					? 'WiPay could not be reached.'
					: 'WiPay rejected the payment request (HTTP ' . $result['code'] . ').'
			);
			return;
		}

		$decoded = json_decode($result['body'], true);

		if (!is_array($decoded) || empty($decoded['data']['url']) || empty($decoded['status'])) {
			$this->errorJson('WiPay returned an unexpected response.');
			return;
		}

		$checkout_url = (string)$decoded['data']['url'];

		if (!$model->isValidCheckoutUrl($checkout_url)) {
			$this->errorJson('WiPay returned an unexpected checkout address.');
			return;
		}

		$model->recordAttempt(
			$order_id,
			$transaction_id,
			number_format((float)($order['total'] ?? 0), 2, '.', ''),
			$environment
		);

		$this->model_checkout_order->addHistory(
			$order_id,
			(int)$this->config->get('config_order_status_id'),
			'Waiting for WiPay payment. Transaction ' . $transaction_id,
			false
		);

		$json['redirect'] = $checkout_url;

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * WiPay redirects the shopper back here. Everything needed to verify the
	 * result is read from the recorded attempt, never from the query string.
	 *
	 * @return void
	 */
	public function callback(): void {
		$this->load->language('extension/waypoint_opencart/payment/wipay_waypoint');
		$this->load->model('checkout/order');

		$transaction_id = (string)($this->request->get['transaction_id'] ?? '');

		if ($transaction_id === '') {
			$this->errorJson('Missing transaction identifier.');
			return;
		}

		/** @var \Opencart\Catalog\Model\Extension\WaypointOpencart\Payment\WipayWaypoint $model */
		$this->load->model('extension/waypoint_opencart/payment/wipay_waypoint');
		$model   = $this->model_extension_waypoint_opencart_payment_wipay_waypoint;
		$attempt = $model->findAttempt($transaction_id);

		if (!$attempt) {
			$this->errorJson('Unknown transaction.');
			return;
		}

		$order_id = (int)$attempt['order_id'];
		$order    = $this->model_checkout_order->getOrder($order_id);

		if (!$order) {
			$this->errorJson('Order not found.');
			return;
		}

		$environment = (string)$attempt['environment'];
		$api_key     = $model->apiKey($environment);
		$provided    = (string)($this->request->get['hash'] ?? '');

		if (!$model->verifyHash($transaction_id, $attempt['total'], $api_key, $provided)) {
			$this->errorJson('Invalid response signature.');
			return;
		}

		$status         = strtolower((string)($this->request->get['status'] ?? ''));
		$returned_total = (string)($this->request->get['total'] ?? '');

		$pending_status_id = (int)$this->config->get('config_order_status_id');
		$paid_status_id    = (int)$this->config->get('payment_wipay_waypoint_order_status_paid');

		if ($status === 'approved') {
			if ((int)$order['order_status_id'] !== $pending_status_id) {
				$model->updateAttempt($transaction_id, 'late');

				$this->model_checkout_order->addHistory(
					$order_id,
					(int)$order['order_status_id'],
					'Late WiPay payment notification for a completed or cancelled order. Transaction ' . $transaction_id
					. ' returned total ' . $returned_total . '. Needs manual review.',
					true
				);

				$json['redirect'] = $this->url->link(
					'checkout/success',
					'language=' . $this->config->get('config_language'),
					true
				);

				$this->response->addHeader('Content-Type: application/json');
				$this->response->setOutput(json_encode($json));
				return;
			}

			$model->updateAttempt($transaction_id, 'paid');

			$this->model_checkout_order->addHistory(
				$order_id,
				$paid_status_id > 0 ? $paid_status_id : 2,
				'Payment received through WiPay. Transaction ' . $transaction_id,
				true
			);

			$json['redirect'] = $this->url->link(
				'checkout/success',
				'language=' . $this->config->get('config_language'),
				true
			);

			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));
			return;
		}

		if ($status === 'failed' || $status === 'cancelled') {
			if ((int)$order['order_status_id'] !== $pending_status_id) {
				$this->errorJson('This order has already moved on.');
				return;
			}

			$model->updateAttempt($transaction_id, 'cancelled');

			$this->model_checkout_order->addHistory(
				$order_id,
				(int)$this->config->get('config_cancel_status_id'),
				'WiPay payment ' . $status . '. Transaction ' . $transaction_id,
				true
			);

			$json['redirect'] = $this->url->link(
				'checkout/checkout',
				'language=' . $this->config->get('config_language'),
				true
			);

			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));
			return;
		}

		$this->errorJson('Unrecognised payment status.');
	}

	/**
	 * @param string $message
	 * @return void
	 */
	private function errorJson(string $message): void {
		$json['error'] = $message;

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
}
