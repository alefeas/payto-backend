<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { 
            font-family: Georgia, 'Times New Roman', serif;
            line-height: 1.8; 
            color: #2c3e50;
            background-color: #ecf0f1;
            margin: 0;
            padding: 30px 15px;
        }
        .container { 
            max-width: 580px; 
            margin: 0 auto; 
            background: #ffffff;
            border-radius: 2px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        .header {
            background: #3498db;
            padding: 30px 40px;
            border-bottom: 4px solid #2980b9;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            letter-spacing: 0.5px;
            font-family: Arial, sans-serif;
        }
        .content {
            padding: 45px 40px;
        }
        .greeting {
            font-size: 18px;
            font-weight: 400;
            color: #2c3e50;
            margin-bottom: 25px;
        }
        .content p {
            color: #34495e;
            font-size: 15px;
            line-height: 1.8;
            margin: 18px 0;
        }
        .button-container {
            text-align: center;
            margin: 40px 0;
        }
        .button { 
            display: inline-block;
            padding: 14px 35px;
            background: #3498db;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 3px;
            font-weight: 400;
            font-size: 15px;
            letter-spacing: 0.5px;
            border: 2px solid #3498db;
        }
        .link-box {
            background: #f8f9fa;
            border: 1px solid #e1e8ed;
            border-radius: 3px;
            padding: 18px;
            margin: 25px 0;
            word-break: break-all;
        }
        .link-box p {
            margin: 0;
            font-size: 12px;
            color: #5dade2;
            font-family: 'Courier New', monospace;
            line-height: 1.6;
        }
        .info-box { 
            background: #ebf5fb;
            border-left: 3px solid #5dade2;
            padding: 18px 20px;
            margin: 30px 0;
        }
        .info-box p {
            margin: 0;
            color: #2c3e50;
            font-size: 14px;
        }
        .info-box strong {
            color: #2980b9;
            font-weight: 600;
        }
        .footer { 
            background: #f8f9fa;
            padding: 30px 40px;
            text-align: center;
            border-top: 1px solid #e1e8ed;
        }
        .footer p {
            margin: 10px 0;
            font-size: 12px;
            color: #7f8c8d;
            line-height: 1.6;
        }
        .footer .company {
            color: #5dade2;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>PayTo</h1>
        </div>
        
        <div class="content">
            <p class="greeting">Hola {{ $userName }},</p>
            
            <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta en PayTo.</p>
            
            <p>Para continuar con el proceso, hacé clic en el siguiente botón:</p>
            
            <div class="button-container">
                <a href="{{ $resetUrl }}" class="button">Restablecer Contraseña</a>
            </div>
            
            <p style="font-size: 13px; color: #7f8c8d;">Si el botón no funciona, copiá y pegá el siguiente enlace en tu navegador:</p>
            <div class="link-box">
                <p>{{ $resetUrl }}</p>
            </div>
            
            <div class="info-box">
                <p><strong>Nota importante:</strong> Este enlace expirará en 1 hora por razones de seguridad.</p>
            </div>
            
            <p style="color: #7f8c8d; font-size: 14px;">Si no solicitaste este cambio, podés ignorar este mensaje. Tu contraseña permanecerá sin cambios.</p>
        </div>
        
        <div class="footer">
            <p>Este es un mensaje automático. Por favor, no respondas a este correo.</p>
            <p class="company">PayTo</p>
            <p>Sistema de Gestión de Facturación</p>
            <p>&copy; {{ date('Y') }} Todos los derechos reservados</p>
        </div>
    </div>
</body>
</html>
