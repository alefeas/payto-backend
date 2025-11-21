<?php

namespace App\Jobs;

use App\Services\BrevoEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendVerificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $email,
        private string $userName,
        private string $code
    ) {}

    public function handle(): void
    {
        try {
            $brevoService = new BrevoEmailService();
            $htmlContent = view('emails.verification-code', [
                'userName' => $this->userName,
                'code' => $this->code,
            ])->render();

            $brevoService->send(
                $this->email,
                'Código de verificación - PayTo',
                $htmlContent
            );
        } catch (\Exception $e) {
            \Log::error('Error sending verification email', [
                'email' => $this->email,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
