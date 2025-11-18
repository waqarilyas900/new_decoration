<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\View;
use Illuminate\Http\Request;

use App\Services\MicrosoftEmailService;
use Illuminate\Support\Facades\Log;
class EmailSendController extends Controller
{

   
    protected $emailService;
    public function __construct(MicrosoftEmailService $emailService)
    {
        $this->emailService = $emailService;
    }
    public static function sendAzureEmail($to, $subject, $view, $data = [], $cc = null, $bcc = null, $fromEmail = null)
    {
        try {
            // Render the view to HTML
            $htmlBody = View::make($view, $data)->render();
            
            // Get the email service instance
            $emailService = app(MicrosoftEmailService::class);
            
            // Send email via Azure
            $result = $emailService->send(
                to: $to,
                subject: $subject,
                body: $htmlBody,
                cc: $cc,
                bcc: $bcc,
                attachments: [],
                fromEmail: $fromEmail
            );
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Azure Email Send Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage()
            ];
        }
    }

    public function sendTestEmail()
    {
       
        $result = $this->emailService->send(
            to: 'waqarilyas900@gmail.com',
            subject: 'Test Email from Laravel',
            body: '<h1>Hello!</h1><p>This is a test email sent via Microsoft Graph API.</p>'
        );

        if ($result['success']) {
            return response()->json(['message' => 'Email sent successfully!']);
        } else {
            return response()->json(['error' => $result['message']], 500);
        }
    }
}
