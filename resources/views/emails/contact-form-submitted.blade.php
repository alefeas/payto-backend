<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 8px;
        }
        .header {
            background-color: #2563eb;
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .content {
            background-color: white;
            padding: 20px;
            border-radius: 0 0 8px 8px;
        }
        .field {
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .field:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: bold;
            color: #2563eb;
            margin-bottom: 5px;
        }
        .value {
            color: #555;
            word-wrap: break-word;
        }
        .footer {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 12px;
            color: #999;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Nuevo Mensaje de Contacto</h2>
        </div>
        <div class="content">
            <div class="field">
                <div class="label">Nombre:</div>
                <div class="value">{{ $contactMessage->name }}</div>
            </div>

            <div class="field">
                <div class="label">Email:</div>
                <div class="value">
                    <a href="mailto:{{ $contactMessage->email }}">{{ $contactMessage->email }}</a>
                </div>
            </div>

            <div class="field">
                <div class="label">Mensaje:</div>
                <div class="value">{{ nl2br(e($contactMessage->message)) }}</div>
            </div>

            <div class="field">
                <div class="label">Fecha y Hora:</div>
                <div class="value">{{ $contactMessage->created_at->format('d/m/Y H:i:s') }}</div>
            </div>

            <div class="field">
                <div class="label">Estado:</div>
                <div class="value">{{ ucfirst($contactMessage->status) }}</div>
            </div>

            <div class="footer">
                <p>Este es un mensaje automático del sistema de contacto.</p>
            </div>
        </div>
    </div>
</body>
</html>
