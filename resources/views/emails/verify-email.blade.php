<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .button { display: inline-block; padding: 12px 24px; background: #4F46E5; color: white; text-decoration: none; border-radius: 6px; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <h2>¡Hola {{ $userName }}!</h2>
        
        <p>Gracias por registrarte en PayTo. Para completar tu registro, necesitamos que verifiques tu dirección de email.</p>
        
        <p>Hacé clic en el siguiente botón para verificar tu email:</p>
        
        <a href="{{ $verificationUrl }}" class="button">Verificar Email</a>
        
        <p>O copiá y pegá este enlace en tu navegador:</p>
        <p style="word-break: break-all; color: #4F46E5;">{{ $verificationUrl }}</p>
        
        <p><strong>Este enlace expira en 48 horas.</strong></p>
        
        <p>Si no creaste una cuenta en PayTo, podés ignorar este email.</p>
        
        <div class="footer">
            <p>Este es un email automático, por favor no respondas a este mensaje.</p>
            <p>&copy; {{ date('Y') }} PayTo - Sistema de Gestión de Facturación</p>
        </div>
    </div>
</body>
</html>
