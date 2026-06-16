<?php

/**
 * Khalti.com Payment Gateway WHMCS Module
 * @see https://docs.khalti.com/
 * @see https://github.com/khalti/whmcs-khaltigateway-plugin
 * @copyright Copyright (c) Khalti Private Limited
 * @author : @acpmasquerade for Khalti.com
 */


if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . "/khaltigateway/init.php";

function khaltigateway_MetaData()
{
    return array(
        'DisplayName' => 'Khalti Payment Gateway (KPG-2)',
        'APIVersion' => '2.0', // Use API Version 1.1
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function khaltigateway_config()
{
    $sandbox_target = "<a href='https://sandbox.khalti.com' target='_blank'>sandbox.khalti.com</a>";
    $live_target = "<a href='https://admin.khalti.com' target='_blank'>admin.khalti.com</a>";

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Khalti Payment Gateway (KPG-2)',
        ),
        'test_api_key' => array(
            'FriendlyName' => 'TEST API Secret Key for KPG-2',
            'Type' => 'text',
            'Size' => '48',
            'Default' => 'test_key_01234567890123456789012345678901',
            'Description' => "Please visit {$sandbox_target} to get your keys",
        ),
        'live_api_key' => array(
            'FriendlyName' => 'LIVE API Secret Key for KPG-2',
            'Type' => 'password',
            'Size' => '48',
            'Default' => 'live_key_01234567890123456789012345678901',
            'Description' => "Please visit {$live_target} to get your keys",
        ),
        'is_debug_mode' => array(
            'FriendlyName' => 'Enable Debugging',
            'Type' => 'yesno',
            'Description' => 'Tick to enable debugging mode',
        ),
        'is_test_mode' => array(
            'FriendlyName' => 'Enable Test (sandbox) Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable sandbox mode of integration',
        )
    );
}

function khaltigateway_link($gateway_params)
{
    $currentPage = khaltigateway_whmcs_current_page();
    if ($currentPage !== KHALTIGATEWAY_WHMCS_VIEWINOVICE_PAGE) {
        return khaltigateway_noinvoicepage_code();
    }
    return  khaltigateway_invoicepage_code($gateway_params);
}

function khaltigateway_refund($gateway_params)
{
    $gateway_module = $gateway_params['paymentmethod'] ?? KHALTIGATEWAY_WHMCS_MODULE_NAME;
    $transaction_id = $gateway_params['transid'] ?? '';
    $refund_amount = isset($gateway_params['amount']) ? floatval($gateway_params['amount']) : 0.0;
    $currency_code = $gateway_params['currency'] ?? 'NPR';
    $client_phone = $gateway_params['clientdetails']['phonenumber'] ?? '';

    if (!$transaction_id) {
        return array(
            'status' => 'error',
            'rawdata' => 'Missing Khalti transaction ID for refund.',
        );
    }

    if ($refund_amount <= 0) {
        return array(
            'status' => 'error',
            'rawdata' => 'Refund amount must be greater than zero.',
        );
    }

    if (!khaltigateway_validate_currency($currency_code)) {
        $converted_amount = khaltigateway_convert_currency($currency_code, $refund_amount);
        if ($converted_amount === false) {
            return array(
                'status' => 'error',
                'rawdata' => "Unable to convert refund currency {$currency_code} to NPR.",
            );
        }
        $refund_amount = floatval($converted_amount);
    }

    $refund_amount_paisa = intval(round($refund_amount * 100));
    if ($refund_amount_paisa < 1) {
        return array(
            'status' => 'error',
            'rawdata' => 'Refund amount must be at least 1 paisa.',
        );
    }

    $payload = array(
        'amount' => $refund_amount_paisa,
    );

    $normalized_phone = preg_replace('/\D+/', '', $client_phone);
    if (strlen($normalized_phone) > 10 && substr($normalized_phone, 0, 3) === '977') {
        $normalized_phone = substr($normalized_phone, -10);
    }
    if ($normalized_phone) {
        $payload['mobile'] = $normalized_phone;
    }

    $refund_response = khaltigateway_refund_api_call($gateway_params, $transaction_id, $payload);
    $raw_data = array(
        'request' => array(
            'transaction_id' => $transaction_id,
            'payload' => $payload,
            'amount_npr' => $refund_amount,
            'amount_paisa' => $refund_amount_paisa,
            'mode' => khaltigateway_get_production_mode($gateway_params),
        ),
        'response' => $refund_response,
    );

    $http_code = $refund_response['http_code'] ?? 0;
    $api_response = $refund_response['response'] ?? array();
    $detail = is_array($api_response) ? strtolower($api_response['detail'] ?? '') : '';

    if ($http_code >= 200 && $http_code < 300 && strpos($detail, 'successful') !== false) {
        return array(
            'status' => 'success',
            'rawdata' => $raw_data,
            'transid' => 'refund-' . $transaction_id . '-' . time(),
            'fees' => 0,
        );
    }

    if (function_exists('logTransaction')) {
        logTransaction($gateway_module, $raw_data, 'Refund Error');
    }

    return array(
        'status' => 'error',
        'rawdata' => $raw_data,
    );
}

