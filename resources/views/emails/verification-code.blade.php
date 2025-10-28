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
        .code-box { 
            background: #ebf5fb;
            border: 2px solid #5dade2;
            border-radius: 3px;
            padding: 35px 25px;
            text-align: center;
            margin: 35px 0;
        }
        .code { 
            font-size: 40px;
            font-weight: 700;
            letter-spacing: 10px;
            color: #1a5490;
            font-family: Verdana, Arial, sans-serif;
        }
        .code-label {
            margin-top: 15px;
            font-size: 13px;
            color: #5dade2;
            font-weight: 400;
            letter-spacing: 0.5px;
        }
        .info-box {
            background: #f8f9fa;
            border-left: 3px solid #5dade2;
            padding: 20px 22px;
            margin: 30px 0;
        }
        .info-box p {
            margin: 0 0 12px 0;
            color: #2c3e50;
            font-size: 14px;
        }
        .info-box p:last-child {
            margin-bottom: 0;
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
            
            <p>Gracias por registrarte en PayTo. Para completar tu registro, ingresá el siguiente código de verificación en la aplicación:</p>
            
            <div class="code-box">
                <div class="code">{{ $code }}</div>
                <p class="code-label">Este código expira en 10 minutos</p>
            </div>
            
            <div class="info-box">
                <p><strong>Recomendaciones de seguridad:</strong> No compartas este código con nadie. PayTo nunca te solicitará este código por teléfono o correo electrónico. Si no solicitaste este código, podés ignorar este mensaje de forma segura.</p>
            </div>
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
