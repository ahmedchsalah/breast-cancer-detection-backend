<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function createCheckout(Request $request)
    {
        // 1. نستقبل السعر من الـ Frontend (في مشروع حقيقي سنستقبل الـ ID ونجلب السعر من قاعدة البيانات لضمان الأمان)
        $request->validate([
            'amount' => 'required|numeric'
        ]);

        // 2. الـ Backend هو من يتخاطب مع Chargily بالسر
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('CHARGILY_SECRET_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://pay.chargily.net/test/api/v2/checkouts', [
            'amount' => $request->amount,
            'currency' => 'dzd',
            'success_url' => 'http://localhost:5173/payment-success', // رابط عودة المستخدم
        ]);

        // 3. نتحقق إذا نجح الاتصال
        if ($response->successful()) {
            $data = $response->json();

            // 4. نعيد رابط الدفع فقط للـ Frontend (بدون أي مفاتيح سرية)
            return response()->json([
                'success' => true,
                'checkout_url' => $data['checkout_url']
            ]);
        }

        // في حال فشل الاتصال مع بوابات الدفع
        return response()->json([
            'success' => false,
            'message' => 'فشل الاتصال ببوابة الدفع.'
        ], 500);
    }
}
