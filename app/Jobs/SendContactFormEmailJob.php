<?php

namespace App\Jobs;

use App\Services\BrevoEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendContactFormEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $adminEmail,
        private array $contactData
    ) {}

    public function handle(): void
    {
        try {
            $brevoService = new BrevoEmailService();
            $htmlContent = view('emails.contact-form-submitted', [
                'contactMessage' => (object) $this->contactData,
            ])->render();

            $brevoService->send(
                $this->adminEmail,
                'Nuevo mensaje de contacto - PayTo',
                $htmlContent
            );
        } catch (\Exception $e) {
            \Log::error('Error sending contact form email', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
