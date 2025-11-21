<?php

namespace App\Jobs;

use App\Services\BrevoEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPasswordResetEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private string $email,
        private string $userName,
        private string $resetUrl
    ) {}

    public function handle(): void
    {
        try {
            $brevoService = new BrevoEmailService();
            $htmlContent = view('emails.reset-password', [
                'userName' => $this->userName,
                'resetUrl' => $this->resetUrl,
            ])->render();

            $brevoService->send(
                $this->email,
                'Recuperar contraseña - PayTo',
                $htmlContent
            );
        } catch (\Exception $e) {
            \Log::error('Error sending password reset email', [
                'email' => $this->email,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
