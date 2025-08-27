<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;

// Test mail configuration
Route::get('/test-mail', function (Request $request) {
    try {
        $testEmail = $request->get('email', 'taskflow91@gmail.com');
        
        Mail::raw('This is a test email from TaskFlow to verify SMTP configuration.', function ($message) use ($testEmail) {
            $message->to($testEmail)
                    ->subject('TaskFlow SMTP Test')
                    ->from(config('mail.from.address'), config('mail.from.name'));
        });
        
        return response()->json([
            'success' => true,
            'message' => 'Test email sent successfully',
            'config' => [
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'username' => config('mail.mailers.smtp.username'),
                'from' => config('mail.from.address'),
                'encryption' => config('mail.mailers.smtp.encryption'),
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'config' => [
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'username' => config('mail.mailers.smtp.username'),
                'from' => config('mail.from.address'),
                'encryption' => config('mail.mailers.smtp.encryption'),
            ]
        ], 500);
    }
});
