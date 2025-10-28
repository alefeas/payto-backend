<?php

namespace App\Services;

use SendinBlue\Client\Configuration;
use SendinBlue\Client\Api\TransactionalEmailsApi;
use SendinBlue\Client\Model\SendSmtpEmail;
use GuzzleHttp\Client;

class BrevoEmailService
{
    private TransactionalEmailsApi $apiInstance;

    public function __construct()
    {
        $config = Configuration::getDefaultConfiguration()->setApiKey('api-key', config('services.brevo.key'));
        $this->apiInstance = new TransactionalEmailsApi(new Client(), $config);
    }

    public function send(string $to, string $subject, string $htmlContent, string $fromName = 'PayTo'): void
    {
        $sendSmtpEmail = new SendSmtpEmail([
            'subject' => $subject,
            'sender' => ['name' => $fromName, 'email' => config('mail.from.address')],
            'to' => [['email' => $to]],
            'htmlContent' => $htmlContent
        ]);

        $this->apiInstance->sendTransacEmail($sendSmtpEmail);
    }
}
