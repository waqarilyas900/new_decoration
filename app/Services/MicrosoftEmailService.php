<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class MicrosoftEmailService
{
    protected $clientId;
    protected $clientSecret;
    protected $tenantId;
    protected $fromEmail;
    protected $accessToken;

    public function __construct()
    {
        $this->clientId = config('services.microsoft.client_id');
        $this->clientSecret = config('services.microsoft.client_secret');
        $this->tenantId = config('services.microsoft.tenant_id');
        $this->fromEmail = config('services.microsoft.from_email');
    }

    /**
     * Get access token
     */
    protected function getAccessToken()
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        try {
            $client = new Client();
            $response = $client->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials'
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $this->accessToken = $data['access_token'];
           
            return $this->accessToken;
        } catch (\Exception $e) {
            Log::error('Microsoft Graph Authentication Error: ' . $e->getMessage());
            throw new \Exception('Failed to authenticate with Microsoft Graph');
        }
    }

    /**
     * Send email
     * 
     * @param string|array $to Recipient email(s)
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string|array|null $cc CC recipients (optional)
     * @param string|array|null $bcc BCC recipients (optional)
     * @param array $attachments Email attachments (optional)
     * @param string|null $fromEmail Custom sender email (optional, defaults to config)
     */
    public function send($to, $subject, $body, $cc = null, $bcc = null, $attachments = [], $fromEmail = null)
    {
        try {
            $token = $this->getAccessToken();
           
            $client = new Client();

            // Use provided fromEmail or default from config
            $senderEmail = $fromEmail ?? $this->fromEmail;

            // Prepare recipients
            $toRecipients = $this->prepareRecipients($to);
            $ccRecipients = $cc ? $this->prepareRecipients($cc) : [];
            $bccRecipients = $bcc ? $this->prepareRecipients($bcc) : [];

            // Prepare message
            $message = [
                'message' => [
                    'subject' => $subject,
                    'body' => [
                        'contentType' => 'HTML',
                        'content' => $body
                    ],
                    'toRecipients' => $toRecipients,
                ]
            ];

            if (!empty($ccRecipients)) {
                $message['message']['ccRecipients'] = $ccRecipients;
            }

            if (!empty($bccRecipients)) {
                $message['message']['bccRecipients'] = $bccRecipients;
            }

            // Send email
            $response = $client->post("https://graph.microsoft.com/v1.0/users/{$senderEmail}/sendMail", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'json' => $message
            ]);

            return [
                'success' => true,
                'message' => 'Email sent successfully'
            ];

        } catch (\Exception $e) {
            Log::error('Email Send Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Prepare recipients array
     */
    protected function prepareRecipients($emails)
    {
        if (is_string($emails)) {
            $emails = explode(',', $emails);
        }

        return array_map(function($email) {
            return [
                'emailAddress' => [
                    'address' => trim($email)
                ]
            ];
        }, $emails);
    }
}