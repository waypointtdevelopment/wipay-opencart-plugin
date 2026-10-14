<?php
/**
 * Admin settings for the WiPay hosted-checkout extension on OpenCart 3.x.
 *
 * Independent implementation maintained by Waypoint. Not affiliated with,
 * sponsored by, or endorsed by WiPay.
 *
 * @license GPL-2.0-or-later
 */

class ControllerExtensionPaymentWipayWaypoint extends Controller {

	public function index() {
		$this->load->language( 'extension/payment/wipay_waypoint' );

		$this->document->setTitle( $this->language->get( 'heading_title' ) );

		$this->load->model( 'setting/setting' );

		if ( ( $this->request->server['REQUEST_METHOD'] === 'POST' ) && $this->validate() ) {
			$this->model_setting_setting->editSetting( 'payment_wipay_waypoint', $this->request->post );

			$this->session->data['success'] = $this->language->get( 'text_success' );

			$this->response->redirect(
				$this->url->link(
					'marketplace/extension',
					'user_token=' . $this->session->data['user_token'] . '&type=payment',
					true
				)
			);
		}

		$data['heading_title'] = $this->language->get( 'heading_title' );

		$data['text_edit']        = $this->language->get( 'text_edit' );
		$data['text_enabled']     = $this->language->get( 'text_enabled' );
		$data['text_disabled']    = $this->language->get( 'text_disabled' );
		$data['text_yes']         = $this->language->get( 'text_yes' );
		$data['text_no']          = $this->language->get( 'text_no' );
		$data['text_sandbox']     = $this->language->get( 'text_sandbox' );
		$data['text_live']        = $this->language->get( 'text_live' );
		$data['text_absorb']      = $this->language->get( 'text_absorb' );
		$data['text_pay']         = $this->language->get( 'text_pay' );
		$data['text_split']       = $this->language->get( 'text_split' );

		$data['entry_status']       = $this->language->get( 'entry_status' );
		$data['entry_title']        = $this->language->get( 'entry_title' );
		$data['entry_text']         = $this->language->get( 'entry_text' );
		$data['entry_environment']  = $this->language->get( 'entry_environment' );
		$data['entry_country']      = $this->language->get( 'entry_country' );
		$data['entry_fee']          = $this->language->get( 'entry_fee' );
		$data['entry_account']      = $this->language->get( 'entry_account' );
		$data['entry_sort_order']   = $this->language->get( 'entry_sort_order' );

		$data['help_account']       = $this->language->get( 'help_account' );
		$data['help_live_key']      = $this->language->get( 'help_live_key' );

		$data['error_permission'] = $this->session->data['error_permission'] ?? '';
		$data['error_country']    = $this->session->data['error_payment_wipay_waypoint_country'] ?? '';
		$data['error_live']       = $this->session->data['error_payment_wipay_waypoint_live'] ?? '';

		unset(
			$this->session->data['error_permission'],
			$this->session->data['error_payment_wipay_waypoint_country'],
			$this->session->data['error_payment_wipay_waypoint_live']
		);

		$data['user_token'] = $this->session->data['user_token'];

		$data['header']      = $this->load->controller( 'common/header' );
		$data['column_left'] = $this->load->controller( 'common/column_left' );
		$data['footer']      = $this->load->controller( 'common/footer' );

		$data['countries'] = array();
		foreach ( array( 'BB', 'GD', 'GY', 'JM', 'TT' ) as $code ) {
			$data['countries'][] = array( 'code' => $code, 'name' => $code );
		}

		foreach ( $this->getKeys() as $key ) {
			$data[ 'payment_wipay_waypoint_' . $key ] = $this->config->get( 'payment_wipay_waypoint_' . $key );
		}

		$this->response->setOutput( $this->load->view( 'extension/payment/wipay_waypoint', $data ) );
	}

	public function validate() {
		if ( ! $this->user->hasPermission( 'modify', 'extension/payment/wipay_waypoint' ) ) {
			$this->session->data['error_permission'] = $this->language->get( 'error_permission' );
			return false;
		}

		if ( empty( $this->request->post['payment_wipay_waypoint_country'] ) ) {
			$this->session->data['error_payment_wipay_waypoint_country'] = 'Select the country your WiPay account is registered in.';
			return false;
		}

		// A live account needs its own number and a real key, both supplied
		// through the constants defined in config.php rather than this form.
		if (
			isset( $this->request->post['payment_wipay_waypoint_environment'] )
			&& $this->request->post['payment_wipay_waypoint_environment'] === 'live'
		) {
			$has_number = ! empty( $this->request->post['payment_wipay_waypoint_account'] );
			$has_key    = defined( 'WIPAY_WAYPOINT_LIVE_API_KEY' ) && WIPAY_WAYPOINT_LIVE_API_KEY !== '';

			if ( ! $has_number || ! $has_key ) {
				$this->session->data['error_payment_wipay_waypoint_live'] =
					'Live mode needs the WiPay account number here and WIPAY_WAYPOINT_LIVE_API_KEY in config.php.';
				return false;
			}
		}

		return true;
	}

	private function getKeys() {
		return array(
			'status',
			'title',
			'text',
			'environment',
			'country',
			'fee',
			'account',
			'sort_order',
		);
	}
}
