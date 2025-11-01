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
        .code-box { 
            background: linear-gradient(135deg, #002bff 0%, #0078ff 100%);
            border-radius: 12px;
            padding: 40px 30px;
            text-align: center;
            margin: 32px 0;
            box-shadow: 0 4px 12px rgba(0, 43, 255, 0.3);
        }
        .code { 
            font-size: 48px;
            font-weight: 700;
            letter-spacing: 12px;
            color: #ffffff;
            font-family: 'Courier New', monospace;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .code-label {
            margin-top: 16px;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
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
            
            <p>Gracias por registrarte en PayTo. Para completar tu registro, ingresa el siguiente código de verificación en la aplicación:</p>
            
            <div class="code-box">
                <div class="code">{{ $code }}</div>
                <p class="code-label">Este código expira en 10 minutos</p>
            </div>
            
            <div class="info-box">
                <p><strong>Recomendaciones de seguridad:</strong> No compartas este código con nadie. PayTo nunca te solicitará este código por teléfono o correo electrónico. Si no solicitaste este código, puedes ignorar este mensaje de forma segura.</p>
            </div>
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
