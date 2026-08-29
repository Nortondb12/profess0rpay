# Profess0rPay - Laravel Integration Guide

This guide provides a basic, ready-to-use template for integrating the Profess0rPay payment gateway into any Laravel application. You can share this guide with your developer or an AI assistant to implement the payment flow in minutes.

---

## 1. Define Routes
Add the following routes to your Laravel application.

**In `routes/web.php`:**
```php
use App\Http\Controllers\PaymentController;

// Initiate the payment
Route::post('/pay-now', [PaymentController::class, 'pay'])->name('payment.pay');

// Redirected here after payment
Route::get('/payment/success', [PaymentController::class, 'success'])->name('payment.success');
```

**In `routes/api.php`:**
```php
use App\Http\Controllers\PaymentController;

// Webhook callback (Server-to-Server)
Route::post('/payment/webhook', [PaymentController::class, 'webhook'])->name('payment.webhook');
```

---

## 2. Exclude Webhook from CSRF
Since the webhook receives a `POST` request from an external server (Profess0rPay), you must exclude the webhook route from Laravel's CSRF protection.

* **For Laravel 10 and below:** Add `'api/payment/webhook'` to the `$except` array in `app/Http/Middleware/VerifyCsrfToken.php`.
* **For Laravel 11+:** Add it in `bootstrap/app.php` under `->withMiddleware(function (Middleware $middleware) { $middleware->validateCsrfTokens(except: ['api/payment/webhook']); })`.

---

## 3. Create the Controller
Create a controller named `PaymentController` (`php artisan make:controller PaymentController`) and paste the following code.

**`app/Http/Controllers/PaymentController.php`**

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    private $apiKey = 'YOUR_PROFESS0RPAY_API_KEY';
    private $gatewayUrl = 'https://yourgateway.com'; // Replace with the actual Profess0rPay domain

    /**
     * 1. Initiate Payment
     */
    public function pay(Request $request)
    {
        // Example Order Data (Fetch this from your database)
        $orderId = 'ORD-' . uniqid();
        $amount = 1200; // Must be greater than 0
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json'
        ])->post($this->gatewayUrl . '/api/create-payment', [
            'amount'        => $amount,
            'return_url'    => route('payment.success'),
            
            // Optional: User details (Send if available)
            // 'full_name'     => auth()->user()->name ?? 'Guest',
            // 'email_address' => auth()->user()->email ?? '',
            // 'mobile_number' => '01700000000',
            
            // Send your internal Order ID in metadata to track it later
            'metadata'      => [
                'order_id'  => $orderId
            ]
        ]);

        $data = $response->json();

        if ($response->successful() && isset($data['status']) && $data['status'] == true) {
            // Update order status to 'PENDING' in your database here
            
            // Redirect user to the Profess0rPay checkout page
            return redirect()->away($data['checkout_url']);
        }

        return back()->with('error', 'Failed to initiate payment: ' . ($data['error']['message'] ?? 'Unknown error'));
    }

    /**
     * 2. Handle Return / Success Redirect
     * Note: Do NOT update sensitive database info here. Rely on the webhook for security.
     */
    public function success(Request $request)
    {
        // The user is redirected here after the payment process
        // $pp_id = $request->query('pp_id'); // If you need to show the Transaction Ref ID

        return view('payment.success')->with('message', 'Thank you! Your payment is being processed.');
    }

    /**
     * 3. Handle Webhook (IPN)
     * This is an automated background request sent by Profess0rPay when a payment is successful.
     */
    public function webhook(Request $request)
    {
        $payload = $request->all();
        
        // Log the payload for debugging
        Log::info('Profess0rPay Webhook Received: ', $payload);

        if (isset($payload['status']) && $payload['status'] === 'completed') {
            
            $pp_id = $payload['pp_id'];
            $orderId = $payload['metadata']['order_id'] ?? null;
            $paidAmount = $payload['amount'];
            $trxId = $payload['transaction_id'];

            /**
             * SECURITY CHECK: Verify the payment directly with Profess0rPay 
             * to ensure the webhook wasn't spoofed by an attacker.
             */
            $verifyResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json'
            ])->post($this->gatewayUrl . '/api/verify-payment', [
                'pp_id' => $pp_id
            ]);

            $verifyData = $verifyResponse->json();

            if ($verifyResponse->successful() && isset($verifyData['status']) && $verifyData['status'] === 'completed') {
                
                // --- TODO: Update your Database ---
                // 1. Find the order by $orderId
                // 2. Check if the $paidAmount matches your database order total
                // 3. Mark the order as 'PAID' or 'PROCESSING'
                // 4. Save the $trxId and $pp_id in your database for future reference
                
                return response()->json(['message' => 'Webhook processed and order verified successfully'], 200);
            }
        }

        return response()->json(['error' => 'Invalid payment or verification failed'], 400);
    }
}
```

---

## Next Steps for the Developer:
1. Replace `'YOUR_PROFESS0RPAY_API_KEY'` with the actual API key generated from the gateway dashboard.
2. Replace `'https://yourgateway.com'` with the actual installation URL of the Profess0rPay gateway.
3. Ensure that the `payment.success` view exists in your Laravel resources.
4. Add the necessary Database / Eloquent updates inside the `webhook()` method where marked with `TODO`.
