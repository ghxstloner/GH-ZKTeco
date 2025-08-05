<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Email - CrewManager</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        .header {
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: bold;
        }
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 16px;
        }
        .content {
            padding: 40px 30px;
        }
        .pin-container {
            background-color: #f1f5f9;
            border: 2px dashed #3b82f6;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            margin: 30px 0;
        }
        .pin-label {
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }
        .pin-code {
            font-size: 36px;
            font-weight: bold;
            color: #1e3a8a;
            letter-spacing: 8px;
            font-family: 'Courier New', monospace;
        }
        .info-box {
            background-color: #fff7ed;
            border-left: 4px solid #f59e0b;
            padding: 20px;
            margin: 30px 0;
            border-radius: 8px;
        }
        .info-box h3 {
            color: #92400e;
            margin: 0 0 10px 0;
            font-size: 16px;
        }
        .info-box p {
            color: #92400e;
            margin: 0;
            line-height: 1.6;
        }
        .crew-info {
            background-color: #eff6ff;
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
        }
        .crew-info strong {
            color: #1e3a8a;
        }
        .footer {
            background-color: #f8fafc;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
        }
        .footer p {
            color: #64748b;
            margin: 5px 0;
            font-size: 14px;
        }
        .logo {
            font-size: 24px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">✈️</div>
            <h1>CrewManager</h1>
            <p>Sistema de Gestión de Tripulación - AITSA</p>
        </div>

        <div class="content">
            <h2 style="color: #1e293b; margin-top: 0;">Verificación de Correo Electrónico</h2>

            <p style="color: #475569; line-height: 1.6; font-size: 16px;">
                Hola, has solicitado registrarte en CrewManager con el siguiente Crew ID:
            </p>

            <div class="crew-info">
                <strong>Crew ID:</strong> {{ $crew_id }}
            </div>

            <p style="color: #475569; line-height: 1.6; font-size: 16px;">
                Para completar tu registro, por favor ingresa el siguiente código de verificación de 6 dígitos en la aplicación:
            </p>

            <div class="pin-container">
                <div class="pin-label">Código de Verificación</div>
                <div class="pin-code">{{ $pin }}</div>
            </div>

            <div class="info-box">
                <h3>⚠️ Información Importante</h3>
                <p>
                    • Este código expira en <strong>15 minutos</strong><br>
                    • No compartas este código con nadie<br>
                    • Si no solicitaste este registro, puedes ignorar este email
                </p>
            </div>

            <p style="color: #475569; line-height: 1.6; font-size: 16px;">
                Una vez verificado tu email, tu solicitud será revisada por nuestro equipo y recibirás una notificación sobre el estado de tu registro.
            </p>
        </div>

        <div class="footer">
            <p><strong>Crew Manager - AITSA</strong></p>
            <p>Sistema de Gestión de Tripulación</p>
            <p style="margin-top: 20px; color: #94a3b8; font-size: 12px;">
                Este es un email automático, por favor no respondas a este mensaje.
            </p>
        </div>
    </div>
</body>
</html>
