<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
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
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        .header {
            background: #ffffff;
            padding: 32px 40px;
            text-align: center;
            border-bottom: none;
        }
        .logo {
            font-size: 40px;
            font-weight: 800;
            color: #ffffff;
            margin: 0;
            letter-spacing: -1px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .content {
            padding: 40px;
        }
        .greeting {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a1a;
            margin: 0 0 24px 0;
        }
        .content p {
            color: #4a5568;
            font-size: 16px;
            line-height: 1.7;
            margin: 16px 0;
        }
        .code-box { 
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 32px 30px;
            text-align: center;
            margin: 32px 0;
            border-radius: 8px;
        }
        .code { 
            font-size: 52px;
            font-weight: 900;
            letter-spacing: 14px;
            color: #4a5568;
            font-family: 'Arial Black', 'Arial', sans-serif;
            text-shadow: none;
            margin: 0;
        }
        .code-label {
            margin-top: 16px;
            font-size: 13px;
            color: #718096;
            font-weight: 500;
        }
        .info-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 16px 20px;
            margin: 28px 0;
            border-radius: 8px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .info-content {
            flex: 1;
        }
        .info-box p {
            margin: 0;
            color: #4a5568;
            font-size: 14px;
            line-height: 1.7;
        }
        .info-box strong {
            color: #1a1a1a;
            font-weight: 600;
        }
        .footer { 
            background: #ffffff;
            padding: 40px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        .footer p {
            margin: 8px 0;
            font-size: 13px;
            color: #718096;
            line-height: 1.6;
        }
        .footer .company {
            color: #002bff;
            font-weight: 700;
            font-size: 16px;
            margin-bottom: 12px;
        }
        .footer-logo {
            height: 40px;
            width: auto;
            display: block;
            margin: 0 auto 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{{ url('images/payto.png') }}" alt="PayTo" style="height: 50px; width: auto; display: block; margin: 0 auto;">
        </div>
        
        <div class="content">
            <h2 class="greeting">¡Hola {{ $userName }}!</h2>
            
            <p>Gracias por registrarte en PayTo. Para completar tu registro, ingresa el siguiente código de verificación en la aplicación:</p>
            
            <div class="code-box">
                <div class="code">{{ $code }}</div>
                <p class="code-label">Este código expira en 10 minutos</p>
            </div>
            
            <div class="info-box">
                <div class="info-content">
                    <p><strong>Recomendaciones de seguridad:</strong> No compartas este código con nadie. PayTo nunca te solicitará este código por teléfono o correo electrónico. Si no solicitaste este código, puedes ignorar este mensaje de forma segura.</p>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <img src="{{ url('images/payto.png') }}" alt="PayTo" class="footer-logo">
            <p>&copy; {{ date('Y') }} Todos los derechos reservados</p>
        </div>
    </div>
</body>
</html>
