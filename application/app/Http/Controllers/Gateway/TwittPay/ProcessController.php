<?php

namespace App\Http\Controllers\Gateway\TwittPay;

use App\Models\Deposit;
use App\Models\GatewayCurrency;
use App\Http\Controllers\Gateway\PaymentController;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Exception;

/**
 * TwittPay - SMMCrowd gateway
 * ---------------------------------------------------------------------------
 * process()  creates the payment and hands the checkout URL to the panel
 * ipn()      answers both the returning user and the gateway's webhook
 *
 * The deposit is only credited after the transaction has been verified against
 * the API, and only while it is still unpaid - so the webhook and the user's
 * return cannot credit the same deposit twice.
 *
 * @version 1.0.0
 */
class ProcessController extends Controller
{
    public static function process($deposit)
    {
        $gateway_currency = $deposit->gatewayCurrency();
        $params           = json_decode($gateway_currency->gateway_parameter);

        if (empty($params->api_key->value ?? $params->api_key ?? '')) {
            return json_encode([
                'error'   => true,
                'message' => 'This payment method is not fully configured yet.',
            ]);
        }

        $requestData = [
            'cus_name'    => optional($deposit->user)->username ?? "Default Name",
            'cus_email'   => optional($deposit->user)->email ?? "default@gmail.com",
            'amount'      => number_format(round($deposit->final_amo, 2), 2, '.', ''),
            'success_url' => route('ipn.' . $deposit->gateway->alias),
            'cancel_url'  => route(gatewayRedirectUrl()),
            'webhook_url' => route('ipn.' . $deposit->gateway->alias),
            'metadata'    => [
                'invoiceid' => (string) $deposit->trx,
                'source'    => 'smmcrowd',
            ],
        ];

        try {
            $redirect_url         = self::initPayment($requestData, $params);
            $send['redirect']     = true;
            $send['redirect_url'] = $redirect_url;
        } catch (Exception $e) {
            $send['error']   = true;
            $send['message'] = $e->getMessage();
        }

        return json_encode($send);
    }

    /**
     * The gateway's webhook posts here, and the returning user arrives here too.
     * The webhook gets a short text answer; the user is sent back into the panel.
     */
    public function ipn(Request $request)
    {
        $isWebhook = $request->isMethod('post');

        $upAcc  = GatewayCurrency::where('gateway_alias', 'TwittPay')->orderBy('id', 'desc')->first();
        $params = $upAcc ? json_decode($upAcc->gateway_parameter) : null;

        if (!$params) {
            return $this->answer($isWebhook, 'Gateway not configured', 400);
        }

        $transactionId = self::transactionIdFrom($request);

        if ($transactionId === '') {
            return $this->answer($isWebhook, 'No transaction id received', 400);
        }

        try {
            $verify = self::verifyPayment($transactionId, $params);
        } catch (Exception $e) {
            return $this->answer($isWebhook, 'The payment could not be checked right now', 200);
        }

        $status = self::readStatus($verify);

        if ($status === '') {
            return $this->answer($isWebhook, 'Unknown transaction', 400);
        }

        $meta = self::metadata($verify);

        if (empty($meta['invoiceid'])) {
            return $this->answer($isWebhook, 'This payment carries no deposit reference', 400);
        }

        $deposit = Deposit::where('trx', $meta['invoiceid'])->first();

        if (!$deposit) {
            return $this->answer($isWebhook, 'Deposit not found', 404);
        }

        if ($status === 'PENDING') {
            // Sent, not approved by the merchant yet. The gateway calls again with
            // the answer, so nothing is credited now.
            return $this->answer($isWebhook, 'Payment is being checked', 200);
        }

        if ($status !== 'COMPLETED') {
            return $this->answer($isWebhook, 'Payment not completed', 200);
        }

        // Short of what was asked for - do not credit it.
        $paid = isset($verify['amount']) ? (float) $verify['amount'] : 0;

        if ($paid + 0.01 < (float) $deposit->final_amo) {
            return $this->answer($isWebhook, 'Amount paid is less than the amount due', 200);
        }

        // status 0 means not yet credited, so this can only happen once.
        if ($deposit->status == 0) {
            PaymentController::userDataUpdate($deposit);
        }

        return $this->answer($isWebhook, 'OK', 200);
    }

    /** The webhook gets plain text, the user gets sent back into the panel. */
    private function answer($isWebhook, $message, $code)
    {
        if ($isWebhook) {
            return response($message, $code);
        }

        return redirect()->route(gatewayRedirectUrl());
    }

    public static function initPayment($requestData, $params)
    {
        $result = self::apiCall('/api/payment/create', $requestData, $params);

        if (!empty($result['status']) && !empty($result['payment_url'])) {
            return $result['payment_url'];
        }

        // The API message is not passed on - an error string can carry the key back
        // out to the user.
        throw new Exception('The payment could not be started. Please try again.');
    }

    public static function verifyPayment($transactionId, $params)
    {
        return self::apiCall('/api/payment/verify', ['transaction_id' => $transactionId], $params);
    }

    /** The transaction id, from the URL, a form body, or a JSON body. */
    protected static function transactionIdFrom($request)
    {
        foreach (['transactionId', 'transaction_id'] as $key) {
            $value = $request ? $request->input($key) : null;

            if (!empty($value)) {
                return trim((string) $value);
            }
        }

        $raw = $request ? $request->getContent() : file_get_contents('php://input');

        if (!empty($raw)) {
            $body = json_decode($raw, true);

            if (is_array($body)) {
                foreach (['transactionId', 'transaction_id'] as $key) {
                    if (!empty($body[$key])) {
                        return trim((string) $body[$key]);
                    }
                }
            }
        }

        return '';
    }

    /**
     * The verify status: PENDING, COMPLETED or ERROR when the transaction is real,
     * and an empty string when it is not - a miss answers a number, not text.
     */
    protected static function readStatus($verified)
    {
        if (!is_array($verified) || !isset($verified['status']) || !is_string($verified['status'])) {
            return '';
        }

        return strtoupper(trim($verified['status']));
    }

    /** metadata comes back from verify as a JSON string. */
    protected static function metadata($verified)
    {
        if (!is_array($verified) || !isset($verified['metadata'])) {
            return [];
        }

        $meta = $verified['metadata'];

        if (is_array($meta)) {
            return $meta;
        }

        if (is_object($meta)) {
            return (array) $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** The saved value of a setting, whichever shape the panel stored it in. */
    protected static function settingValue($params, $name)
    {
        if (!is_object($params) || !isset($params->$name)) {
            return '';
        }

        $field = $params->$name;

        if (is_object($field)) {
            return isset($field->value) ? trim((string) $field->value) : '';
        }

        return trim((string) $field);
    }

    /**
     * Scheme and host of the configured endpoint. Pasting the whole endpoint or a
     * trailing /api still works.
     */
    protected static function baseUrl($params)
    {
        $raw    = rtrim(self::settingValue($params, 'api_url'), '/');
        $scheme = parse_url($raw, PHP_URL_SCHEME);
        $host   = parse_url($raw, PHP_URL_HOST);

        if (empty($host)) {
            $host = strtok(ltrim(preg_replace('#^[a-z]+://#i', '', $raw), '/'), '/');
        }

        if (empty($scheme)) {
            $scheme = 'https';
        }

        if (empty($host)) { $host = 'checkout.twittpay.com'; }
        return 'https://' . $host;
    }

    /** One POST to the API. JSON in, array out. */
    protected static function apiCall($endpoint, $payload, $params)
    {
        // metadata has to arrive as a JSON object; a PHP list would encode as an
        // array and be rejected.
        if (isset($payload['metadata'])) {
            $payload['metadata'] = (object) $payload['metadata'];
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => self::baseUrl($params) . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                "API-KEY: " . self::settingValue($params, 'api_key'),
                "Accept: application/json",
                "Content-Type: application/json",
            ],
        ]);

        $response = curl_exec($curl);
        $err      = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new Exception("Connection error: " . $err);
        }

        $result = json_decode($response, true);

        return is_array($result) ? $result : [];
    }
}
