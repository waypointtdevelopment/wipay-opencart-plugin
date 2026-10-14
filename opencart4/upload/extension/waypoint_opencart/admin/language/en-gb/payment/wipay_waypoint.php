<?php
/**
 * Admin language strings for the WiPay extension on OpenCart 4.x.
 *
 * @license GPL-2.0-or-later
 */

// Heading.
$_['heading_title'] = 'WiPay';

// Text.
$_['text_edit']     = 'WiPay hosted checkout';
$_['text_general']  = 'General';
$_['text_enabled']  = 'Enabled';
$_['text_disabled'] = 'Disabled';
$_['text_sandbox']  = 'Sandbox';
$_['text_live']     = 'Live';
$_['text_absorb']   = 'Merchant absorbs the fee';
$_['text_pay']      = 'Customer pays the fee';
$_['text_split']    = 'Split fee';
$_['text_select']   = '- Select -';
$_['text_success']  = 'You have modified the WiPay payment module.';

// Button.
$_['button_save']   = 'Save';
$_['button_cancel'] = 'Cancel';

// Entry.
$_['entry_status']      = 'Status';
$_['entry_title']       = 'Title';
$_['entry_text']        = 'Checkout description';
$_['entry_environment'] = 'Environment';
$_['entry_country']     = 'Country';
$_['entry_fee']         = 'Fee structure';
$_['entry_account']     = 'Live account number';
$_['entry_sort_order']  = 'Sort order';

// Help.
$_['help_account']  = 'Your WiPay merchant account number, used only in Live mode. Sandbox always uses WiPay\'s published test account.';
$_['help_live_key'] = 'The Live response key is never entered here. Define WIPAY_WAYPOINT_LIVE_API_KEY in config.php, next to the other constants.';

// Error.
$_['error_permission'] = 'Warning: You do not have permission to modify the WiPay payment module.';
