<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class BrevoEmailService
{
    private string $apiKey;
    private string $apiUrl = 'https://api.resend.com/emails';
    private Client $client;

    public function __construct()
    {
        $this->apiKey = config('services.resend.key') ?? env('RESEND_KEY');
        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    public function send(string $to, string $subject, string $htmlContent, string $fromName = null, string $fromEmail = null): bool
    {
        try {
            $fromEmail = $fromEmail ?? config('mail.from.address');
            $fromName = $fromName ?? config('mail.from.name');

            $payload = [
                'from' => $fromName ? "$fromName <$fromEmail>" : $fromEmail,
                'to' => $to,
                'subject' => $subject,
                'html' => $htmlContent,
            ];

            \Log::info('Sending email', [
                'to' => $to,
                'subject' => $subject,
                'from' => $fromEmail,
            ]);

            $response = $this->client->post($this->apiUrl, [
                'headers' => [
                    'Authorization' => "Bearer $this->apiKey",
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            \Log::info('Email sent successfully', [
                'to' => $to,
                'status' => $statusCode,
            ]);

            return $statusCode === 200;
        } catch (GuzzleException $e) {
            \Log::error('Email send error', [
                'error' => $e->getMessage(),
                'to' => $to,
                'subject' => $subject,
                'code' => $e->getCode(),
            ]);
            return false;
        } catch (\Exception $e) {
            \Log::error('Email send exception', [
                'error' => $e->getMessage(),
                'to' => $to,
                'subject' => $subject,
            ]);
            return false;
        }
    }
}
