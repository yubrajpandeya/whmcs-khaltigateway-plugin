<?php

/**
 * Khalti.com Payment Gateway WHMCS Module Callback Handler
 * 
 * @see https://docs.khalti.com/
 * 
 * @copyright Copyright (c) Khalti Private Limited
 * @author : @acpmasquerade for Khalti.com
 * @author : @om-ghimire for Khalti.com
 */
header("Content-Type: application/json");

// Enable error reporting for debugging (disable in production)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

try {

    // Robust WHMCS bootstrap: use __DIR__ relative path instead of relying on SCRIPT_FILENAME
    require_once __DIR__ . '/../../../init.php';

    // Include helper functions if available (older/newer WHMCS versions)
    if (file_exists(__DIR__ . '/../../../includes/gatewayfunctions.php')) {
        require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
    }
    if (file_exists(__DIR__ . '/../../../includes/invoicefunctions.php')) {
        require_once __DIR__ . '/../../../includes/invoicefunctions.php';
    }

    // Load Khalti gateway module helpers
    require_once __DIR__ . "/init.php";
    require_once __DIR__ . "/whmcs.php";

    // Ensure gateway params exist; fallback to getGatewayVariables() if needed
    global $khaltigateway_gateway_params;
    if (empty($khaltigateway_gateway_params) && function_exists('getGatewayVariables')) {
        $khaltigateway_gateway_params = getGatewayVariables(KHALTIGATEWAY_WHMCS_MODULE_NAME);
    }

    // Fetch callback parameters (support POST form, GET or raw JSON)
    $rawInput = file_get_contents('php://input');
    $decoded_input = !empty($rawInput) ? json_decode($rawInput, true) : [];
    if (!is_array($decoded_input)) {
        $decoded_input = [];
    }
    $callback_args = !empty($_POST) ? $_POST : (!empty($_GET) ? $_GET : $decoded_input);

    // Basic early logging to help diagnose production-only failures
    $gateway_module = ($khaltigateway_gateway_params['paymentmethod'] ?? 'khaltigateway');
    $rawLog = is_string($rawInput) ? substr($rawInput, 0, 4000) : json_encode($rawInput);
    if (function_exists('logTransaction')) {
        logTransaction($gateway_module, "Callback received: method={$_SERVER['REQUEST_METHOD']}; raw=" . $rawLog . "; post_keys=" . json_encode(array_keys($_POST)), "Debug");
    }
    
    // Extract essential fields
    $pidx = $callback_args['pidx'] ?? null;
    $purchase_order_id = $callback_args['purchase_order_id'] ?? null;

    // Helper: safe error response and logging
    function error_resp($msg, $gateway_module = 'khaltigateway') {
        if (function_exists('logTransaction')) {
            logTransaction($gateway_module, $msg, "Error");
        }
        http_response_code(400);
        die(json_encode(['error' => $msg]));
    }

    // Ensure gateway params exist
    global $khaltigateway_gateway_params;
    if (!isset($khaltigateway_gateway_params)) {
        error_resp("Gateway parameters missing.", 'khaltigateway');
    }
    $gateway_module = $khaltigateway_gateway_params['paymentmethod'] ?? 'khaltigateway';

    logTransaction($gateway_module, "Callback received: " . json_encode($callback_args), "Debug");

    // Validate required fields
    if (!$pidx) {
        error_resp("Missing pidx parameter.", $gateway_module);
    }
    if (!$purchase_order_id) {
        error_resp("Missing purchase_order_id parameter.", $gateway_module);
    }

    // Extract invoice ID safely
    $invoice_id = $purchase_order_id;
    $expected_amount_paisa = null;
    if (preg_match('/(?:^|[:_])invoice:(\d+)(?::amount:(\d+))?/i', $purchase_order_id, $matches)) {
        $invoice_id = $matches[1];
        if (isset($matches[2])) {
            $expected_amount_paisa = intval($matches[2]);
        }
    } else {
        $parts = explode('_', $purchase_order_id);
        if (isset($parts[0])) {
            $invoiceParts = explode(':', $parts[0]);
            if (isset($invoiceParts[1]) && is_numeric($invoiceParts[1])) {
                $invoice_id = $invoiceParts[1];
            }
        }
    }
    if (!$invoice_id || !is_numeric($invoice_id)) {
        error_resp("Invalid purchase_order_id format.", $gateway_module);
    }

    // Secure Khalti payment verification
    $response = khaltigateway_epay_lookup($khaltigateway_gateway_params, $pidx);
    if (!$response) {
        error_resp("Failed to confirm payment with Khalti.", $gateway_module);
    }

    logTransaction($gateway_module, "Khalti response: " . json_encode($response), "Debug");

    if (($response['pidx'] ?? null) !== $pidx) {
        error_resp("Khalti lookup pidx mismatch.", $gateway_module);
    }

    $khalti_transaction_id = $response['transaction_id'] ?? ($callback_args['transaction_id'] ?? ($callback_args['txnId'] ?? null));
    $amount_paisa = isset($response['total_amount']) ? intval($response['total_amount']) : null;
    $amount_rs = $amount_paisa !== null ? $amount_paisa / 100 : null;
    if ($expected_amount_paisa !== null && $amount_paisa !== $expected_amount_paisa) {
        error_resp("Amount mismatch: Payment was initiated for {$expected_amount_paisa} paisa, lookup returned {$amount_paisa} paisa.", $gateway_module);
    }

    // Handle payment status
    $status = strtolower($response['status'] ?? '');
    switch ($status) {
        case 'refunded':
        case 'refuded': // typo fix
            error_resp("Payment already refunded.", $gateway_module);
        case 'expired':
            error_resp("Payment request expired.", $gateway_module);
        case 'pending':
            error_resp("Payment is still pending.", $gateway_module);
        case 'initiated':
            error_resp("Payment is not completed yet.", $gateway_module);
        case 'user canceled':
        case 'canceled':
        case 'cancelled':
            error_resp("Payment was canceled.", $gateway_module);
        case 'partially refunded':
            error_resp("Payment has been partially refunded.", $gateway_module);
        case 'completed':
            // continue
            break;
        default:
            error_resp("Payment status not completed. Status: {$status}", $gateway_module);
    }

    // Fetch invoice
    $invoice = localAPI("GetInvoice", ["invoiceid" => $invoice_id]);
    if (!$invoice || ($invoice['result'] ?? '') !== 'success') {
        error_resp("Invoice #{$invoice_id} not found.", $gateway_module);
    } else {
        $invoice_exists = true;
        if ($amount_rs === null) {
            error_resp("Missing Khalti payment amount after lookup.", $gateway_module);
        }
    }

    if (!$khalti_transaction_id) {
        error_resp("Missing Khalti transaction ID after lookup.", $gateway_module);
    }

    // Prepare submission
    $submit_data = [
        'wh_payload' => $callback_args,
        'wh_response' => $response,
        'wh_invoiceId' => $invoice_id,
        'wh_gatewayModule' => $gateway_module,
        'wh_transactionId' => $khalti_transaction_id,
        'wh_paymentAmount' => $amount_rs,
        'wh_paymentFee' => 0,
        'wh_paymentSuccess' => true,
    ];

    logTransaction($gateway_module, "Submitting payment to WHMCS: " . json_encode($submit_data), "Debug");

    // Acknowledge WHMCS
    if (!khaltigateway_acknowledge_whmcs_for_payment($submit_data)) {
        error_resp("Failed to record payment in WHMCS.", $gateway_module);
    }
    logTransaction($gateway_module, "Payment processing completed successfully.", "Success");

    // Prepare invoice URL safely
    $system_url = rtrim($khaltigateway_gateway_params['systemurl'] ?? $khaltigateway_gateway_params['SystemURL'], '/');
    $invoice_url = $system_url . "/viewinvoice.php?id=" . $invoice_id;

    // Handle GET redirect safely
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($invoice_exists) {
            header("Location: " . $invoice_url);
            exit;
        } else {
            echo "Invoice #{$invoice_id} not found. Please check your WHMCS system.";
            exit;
        }
    }

    // For webhooks / non-browser requests
    echo json_encode([
        'status' => 'success',
        'invoice_id' => $invoice_id,
        'invoice_exists' => $invoice_exists
    ]);
    exit;

} catch (Exception $e) {
    // Catch all unexpected errors
    $error_msg = "Exception caught: " . $e->getMessage();
    if (isset($gateway_module)) {
        logTransaction($gateway_module, $error_msg, "Error");
    }
    http_response_code(500);
    echo json_encode(['error' => $error_msg]);
    exit;
}
