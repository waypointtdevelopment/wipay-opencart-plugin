<?php
namespace Opencart\Admin\Controller\Extension\WaypointOpencart\Payment;

/**
 * Admin settings for the WiPay hosted-checkout extension on OpenCart 4.x.
 *
 * Independent implementation maintained by Waypoint. Not affiliated with,
 * sponsored by, or endorsed by WiPay.
 *
 * @license GPL-2.0-or-later
 */
class WipayWaypoint extends \Opencart\System\Engine\Controller {

	/**
	 * @return void
	 */
	public function index(): void {
		$this->load->language('extension/waypoint_opencart/payment/wipay_waypoint');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');

		if (($this->request->server['REQUEST_METHOD'] === 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('payment_wipay_waypoint', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment'));
		}

		$data['heading_title'] = $this->language->get('heading_title');

		$data['text_edit']    = $this->language->get('text_edit');
		$data['text_general'] = $this->language->get('text_general');
		$data['text_enabled'] = $this->language->get('text_enabled');
		$data['text_disabled'] = $this->language->get('text_disabled');
		$data['text_sandbox'] = $this->language->get('text_sandbox');
		$data['text_live']    = $this->language->get('text_live');
		$data['text_absorb']  = $this->language->get('text_absorb');
		$data['text_pay']     = $this->language->get('text_pay');
		$data['text_split']   = $this->language->get('text_split');
		$data['text_select']  = $this->language->get('text_select');

		$data['button_save'] = $this->language->get('button_save');
		$data['button_cancel'] = $this->language->get('button_cancel');

		$data['entry_status']      = $this->language->get('entry_status');
		$data['entry_title']       = $this->language->get('entry_title');
		$data['entry_text']        = $this->language->get('entry_text');
		$data['entry_environment'] = $this->language->get('entry_environment');
		$data['entry_country']     = $this->language->get('entry_country');
		$data['entry_fee']         = $this->language->get('entry_fee');
		$data['entry_account']     = $this->language->get('entry_account');
		$data['entry_sort_order']  = $this->language->get('entry_sort_order');

		$data['help_account']  = $this->language->get('help_account');
		$data['help_live_key'] = $this->language->get('help_live_key');

		$data['error_permission'] = $this->session->data['error_permission'] ?? '';
		$data['error_country']    = $this->session->data['error_payment_wipay_waypoint_country'] ?? '';
		$data['error_live']       = $this->session->data['error_payment_wipay_waypoint_live'] ?? '';

		unset(
			$this->session->data['error_permission'],
			$this->session->data['error_payment_wipay_waypoint_country'],
			$this->session->data['error_payment_wipay_waypoint_live']
		);

		$data['user_token'] = $this->session->data['user_token'];

		$data['countries'] = [];
		foreach (['BB', 'GD', 'GY', 'JM', 'TT'] as $code) {
			$data['countries'][] = ['code' => $code, 'name' => $code];
		}

		foreach ($this->getKeys() as $key) {
			$data['payment_wipay_waypoint_' . $key] = $this->config->get('payment_wipay_waypoint_' . $key);
		}

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/waypoint_opencart/payment/wipay_waypoint', $data));
	}

	/**
	 * @return bool
	 */
	public function validate(): bool {
		if (!$this->user->hasPermission('modify', 'extension/waypoint_opencart/payment/wipay_waypoint')) {
			$this->session->data['error_permission'] = $this->language->get('error_permission');

			return false;
		}

		if (empty($this->request->post['payment_wipay_waypoint_country'])) {
			$this->session->data['error_payment_wipay_waypoint_country'] =
				'Select the country your WiPay account is registered in.';

			return false;
		}

		if (($this->request->post['payment_wipay_waypoint_environment'] ?? '') === 'live') {
			$has_number = !empty($this->request->post['payment_wipay_waypoint_account']);
			$has_key    = defined('WIPAY_WAYPOINT_LIVE_API_KEY') && WIPAY_WAYPOINT_LIVE_API_KEY !== '';

			if (!$has_number || !$has_key) {
				$this->session->data['error_payment_wipay_waypoint_live'] =
					'Live mode needs the WiPay account number here and WIPAY_WAYPOINT_LIVE_API_KEY in config.php.';

				return false;
			}
		}

		return true;
	}

	/**
	 * @return array
	 */
	private function getKeys(): array {
		return [
			'status',
			'title',
			'text',
			'environment',
			'country',
			'fee',
			'account',
			'sort_order',
		];
	}
}
