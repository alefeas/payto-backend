<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
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
        .field {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #eeeeee;
        }
        .field:last-of-type {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .label {
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .value {
            color: #4a5568;
            word-wrap: break-word;
            font-size: 15px;
            line-height: 1.7;
        }
        .value a {
            color: #0078ff;
            text-decoration: none;
            font-weight: 500;
        }
        .value a:hover {
            text-decoration: underline;
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
            <h2 class="greeting">Nuevo Mensaje de Contacto</h2>
            
            <div class="field">
                <div class="label">De:</div>
                <div class="value">{{ $contactMessage->name }}</div>
            </div>

            <div class="field">
                <div class="label">Email:</div>
                <div class="value">
                    <a href="mailto:{{ $contactMessage->email }}">{{ $contactMessage->email }}</a>
                </div>
            </div>

            <div class="field">
                <div class="label">Asunto:</div>
                <div class="value">{{ $contactMessage->subject ?? 'Sin asunto' }}</div>
            </div>

            <div class="field">
                <div class="label">Mensaje:</div>
                <div class="value">{{ nl2br(e($contactMessage->message)) }}</div>
            </div>

            <div class="field">
                <div class="label">Fecha y hora:</div>
                <div class="value">{{ $contactMessage->created_at->format('d/m/Y H:i:s') }}</div>
            </div>

            <div class="field">
                <div class="label">Estado:</div>
                <div class="value">{{ ucfirst($contactMessage->status) }}</div>
            </div>
        </div>
        
        <div class="footer">
            <img src="{{ url('images/payto.png') }}" alt="PayTo" class="footer-logo">
            <p>Este es un mensaje automático del sistema de contacto.</p>
            <p>&copy; {{ date('Y') }} Todos los derechos reservados</p>
        </div>
    </div>
</body>
</html>
