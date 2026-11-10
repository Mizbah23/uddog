<?php

namespace App\Services;

use App\Models\EcommerceOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SteadfastCourier
{
    public function payload(EcommerceOrder $order, string $amountDue): array
    {
        $phone = preg_replace('/^(?:\+?880)/', '0', $order->phone);
        if (! preg_match('/\A01[0-9]{9}\z/', $phone) || mb_strlen($order->address) > 250) {
            throw ValidationException::withMessages([
                'order' => 'Steadfast requires a Bangladesh mobile number and a delivery address of at most 250 characters.',
            ]);
        }

        return [
            'invoice' => 'UDDOG-'.$order->organization_id.'-'.$order->id.'-'.$order->number,
            'recipient_name' => $order->customer_name,
            'recipient_phone' => $phone,
            'recipient_address' => $order->address,
            'cod_amount' => $amountDue,
        ];
    }

    public function book(array $settings, array $payload): array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['Api-Key' => $settings['api_key'], 'Secret-Key' => $settings['secret_key']])
                ->withoutRedirecting()->connectTimeout(3)->timeout(10)
                ->post('https://portal.packzy.com/api/v1/create_order', $payload);
        } catch (ConnectionException) {
            return ['state' => 'review'];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return ['state' => 'credentials_rejected'];
        }

        $id = $response->json('consignment.consignment_id');
        $tracking = $response->json('consignment.tracking_code');
        if (! $response->successful() || (! is_int($id) && ! is_string($id)) || ! ctype_digit((string) $id)
            || ! is_string($tracking) || ! preg_match('/\A[A-Za-z0-9_-]{1,100}\z/', $tracking)) {
            return ['state' => 'review'];
        }

        return ['state' => 'booked', 'consignment_id' => (string) $id, 'tracking_code' => $tracking];
    }
}
