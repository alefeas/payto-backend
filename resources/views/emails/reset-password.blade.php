<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6; 
            color: #1a1a1a;
            background: linear-gradient(135deg, #002bff 0%, #0078ff 50%, #0000d4 100%);
            margin: 0;
            padding: 40px 20px;
        }
        .container { 
            max-width: 600px; 
            margin: 0 auto; 
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        .header {
            background: #ffffff;
            padding: 40px 40px 30px;
            text-align: center;
            border-bottom: 1px solid #eeeeee;
        }
        .logo {
            font-size: 36px;
            font-weight: 700;
            color: #002bff;
            margin: 0;
            letter-spacing: -1px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .content {
            padding: 40px;
        }
        .greeting {
            font-size: 24px;
            font-weight: 600;
            color: #1a1a1a;
            margin: 0 0 20px 0;
        }
        .content p {
            color: #4a5568;
            font-size: 16px;
            line-height: 1.6;
            margin: 16px 0;
        }
        .button-container {
            text-align: center;
            margin: 36px 0;
        }
        .button { 
            display: inline-block;
            padding: 16px 40px;
            background: #002bff;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(0, 43, 255, 0.4);
        }
        .link-box {
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px 20px;
            margin: 24px 0;
            word-break: break-all;
        }
        .link-box p {
            margin: 0;
            font-size: 13px;
            color: #002bff;
            font-family: 'Courier New', monospace;
            line-height: 1.5;
        }
        .info-box { 
            background: #f7fafc;
            border-left: 4px solid #002bff;
            border-radius: 8px;
            padding: 20px 24px;
            margin: 28px 0;
        }
        .info-box p {
            margin: 0;
            color: #4a5568;
            font-size: 14px;
            line-height: 1.6;
        }
        .info-box strong {
            color: #002bff;
            font-weight: 600;
        }
        .footer { 
            background: #f7fafc;
            padding: 32px 40px;
            text-align: center;
            border-top: 1px solid #eeeeee;
        }
        .footer p {
            margin: 8px 0;
            font-size: 13px;
            color: #718096;
            line-height: 1.5;
        }
        .footer .company {
            color: #002bff;
            font-weight: 600;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="logo">PayTo</h1>
        </div>
        
        <div class="content">
            <h2 class="greeting">¡Hola {{ $userName }}!</h2>
            
            <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta en PayTo.</p>
            
            <p>Para continuar con el proceso, haz clic en el siguiente botón:</p>
            
            <div class="button-container">
                <a href="{{ $resetUrl }}" class="button">Restablecer Contraseña</a>
            </div>
            
            <p style="font-size: 14px; color: #718096;">Si el botón no funciona, copia y pega el siguiente enlace en tu navegador:</p>
            <div class="link-box">
                <p>{{ $resetUrl }}</p>
            </div>
            
            <div class="info-box">
                <p><strong>Nota importante:</strong> Este enlace expirará en 1 hora por razones de seguridad.</p>
            </div>
            
            <p style="color: #718096; font-size: 14px;">Si no solicitaste este cambio, puedes ignorar este mensaje. Tu contraseña permanecerá sin cambios.</p>
        </div>
        
        <div class="footer">
            <p class="company">PayTo</p>
            <p>Sistema de Gestión de Facturación</p>
            <p>Este es un mensaje automático. Por favor, no respondas a este correo.</p>
            <p>&copy; {{ date('Y') }} Todos los derechos reservados</p>
        </div>
    </div>
</body>
</html>
