<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Twilio\Rest\Client;
class WhatsAppController extends Controller
{
    public function sendOtp(Request $request)
    {
        // 1. Get the destination number from your app's frontend
        // For testing, we will hardcode your Algerian number
        $destinationNumber = '+213562206450';

        // 2. Load credentials securely from .env
        $sid = env('TWILIO_SID');
        $token = env('TWILIO_AUTH_TOKEN');
        $twilio = new Client($sid, $token);

        try {
            // ==========================================
            // ATTEMPT 1: Send via WhatsApp Sandbox
            // ==========================================
            $message = $twilio->messages->create(
                "whatsapp:" . $destinationNumber,
                [
                    "from" => env('TWILIO_WHATSAPP_FROM'),
                    // Using your exact sandbox template
                    "contentSid" => "HXb5b62575e6e4ff6129ad7c8efe1f983e",
                    // json_encode is much cleaner than escaping quotes in a string!
                    "contentVariables" => json_encode([
                        "1" => "12/1",
                        "2" => "3pm"
                    ]),
                ]
            );

            return response()->json([
                'status' => 'success',
                'channel' => 'whatsapp',
                'message_sid' => $message->sid
            ]);

        } catch (Exception $e) {
            // ==========================================
            // ATTEMPT 2: Fallback to Standard SMS
            // ==========================================
            // If the user doesn't have WhatsApp (or the sandbox connection expired),
            // Twilio throws an exception. We catch it here and send an SMS instead.

            try {
                $smsMessage = $twilio->messages->create(
                    $destinationNumber, // Notice: No "whatsapp:" prefix for SMS
                    [
                        "from" => env('TWILIO_SMS_FROM'), // Your US +1 502... number
                        "body" => "Your appointment is coming up on 12/1 at 3pm."
                    ]
                );

                return response()->json([
                    'status' => 'success',
                    'channel' => 'sms_fallback',
                    'message_sid' => $smsMessage->sid
                ]);

            } catch (Exception $smsError) {
                // If BOTH fail, return the error to your frontend
                return response()->json([
                    'status' => 'failed',
                    'error' => $smsError->getMessage()
                ], 500);
            }
        }
    }
}
