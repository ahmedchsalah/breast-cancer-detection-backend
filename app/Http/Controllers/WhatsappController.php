<?php

namespace App\Http\Controllers;
use Twilio\Rest\Client;
use Illuminate\Http\Request;

class WhatsappController extends Controller
{
    public function sendOtp()
    {
        $sid    = env('TWILIO_SID');
        $token  = env('TWILIO_AUTH_TOKEN');
        $twilio = new Client($sid, $token);

        // Generate a random 6-digit OTP
        $otp = rand(100000, 999999);

        try {
            $message = $twilio->messages->create(
                "whatsapp:+213562206450",
                [
                    "from"             => "whatsapp:+14155238886",
                    "contentSid"       => env('TWILIO_CONTENT_SID'),
                    "contentVariables" => json_encode(["1" => (string)$otp]),
                ]
            );

            return response()->json([
                'success' => true,
                'message_sid' => $message->sid,
                'otp' => $otp // remove this in production!
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
