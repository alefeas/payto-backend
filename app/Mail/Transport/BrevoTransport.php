<?php

namespace App\Mail\Transport;

use Illuminate\Mail\Transport\Transport;
use Swift_Mime_SimpleMessage;
use Brevo\Client\Configuration;
use Brevo\Client\Api\TransactionalEmailsApi;
use Brevo\Client\Model\SendSmtpEmail;

class BrevoTransport extends Transport
{
    protected $apiKey;

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);

        try {
            $config = Configuration::getDefaultConfiguration()->setApiKey('api-key', $this->apiKey);
            $apiInstance = new TransactionalEmailsApi(null, $config);

            $sendSmtpEmail = new SendSmtpEmail();
            $sendSmtpEmail->setSubject($message->getSubject());
            $sendSmtpEmail->setHtmlContent($message->getBody());

            // From
            $from = $message->getFrom();
            $fromEmail = key($from);
            $fromName = current($from);
            $sendSmtpEmail->setFrom(['email' => $fromEmail, 'name' => $fromName]);

            // To
            $to = [];
            foreach ($message->getTo() as $email => $name) {
                $to[] = ['email' => $email, 'name' => $name];
            }
            $sendSmtpEmail->setTo($to);

            // CC
            if ($message->getCc()) {
                $cc = [];
                foreach ($message->getCc() as $email => $name) {
                    $cc[] = ['email' => $email, 'name' => $name];
                }
                $sendSmtpEmail->setCc($cc);
            }

            // BCC
            if ($message->getBcc()) {
                $bcc = [];
                foreach ($message->getBcc() as $email => $name) {
                    $bcc[] = ['email' => $email, 'name' => $name];
                }
                $sendSmtpEmail->setBcc($bcc);
            }

            $apiInstance->sendTransacEmail($sendSmtpEmail);

            $this->afterSendPerformed($message);

            return $this->numberOfRecipients($message);
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
