<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir senha — Becalima007</title>
</head>
<body style="margin:0;padding:0;background-color:#0a0a0a;width:100% !important;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0a0a0a;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#121212;border:1px solid #2a2a2a;border-radius:16px;overflow:hidden;">
                    <tr>
                        <td style="height:4px;background:linear-gradient(90deg,#f5cee1 0%,#e8a0c0 50%,#f5cee1 100%);font-size:0;line-height:0;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:36px 28px 12px;background-color:#121212;">
                            <p style="margin:0 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:28px;line-height:1.2;color:#f5cee1;letter-spacing:0.04em;">
                                becalima007
                            </p>
                            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:0.18em;text-transform:uppercase;color:#999999;">
                                Redefinição de senha
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 28px 8px;background-color:#121212;font-family:Arial,Helvetica,sans-serif;color:#ffffff;">
                            <p style="margin:0 0 18px;font-size:16px;line-height:1.5;color:#ffffff;">
                                Olá, <strong style="color:#f5cee1;">{{ $greetingName }}</strong>
                            </p>
                            <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#cccccc;">
                                Recebemos um pedido para redefinir a senha da sua conta. Clique no botão abaixo para escolher uma nova senha.
                            </p>
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 18px;">
                                <tr>
                                    <td align="center" bgcolor="#f5cee1" style="border-radius:999px;background-color:#f5cee1;">
                                        <a href="{{ $resetUrl }}"
                                           target="_blank"
                                           style="display:inline-block;padding:14px 36px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#121212;text-decoration:none;">
                                            Redefinir senha
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 12px;font-size:13px;line-height:1.6;color:#888888;">
                                Este link expira em {{ $expiresInMinutes }} minutos. Se você não pediu essa alteração, ignore este e-mail.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:20px 28px 28px;background-color:#121212;font-family:Arial,Helvetica,sans-serif;">
                            <p style="margin:0;font-size:11px;line-height:1.5;color:#555555;">
                                Conteúdo destinado a maiores de 18 anos.<br>
                                © {{ date('Y') }} Becalima007
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
