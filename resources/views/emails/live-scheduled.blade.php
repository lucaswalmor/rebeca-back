<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live — Becalima007</title>
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
                                Live exclusiva
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 28px 8px;background-color:#121212;font-family:Arial,Helvetica,sans-serif;color:#ffffff;">
                            <p style="margin:0 0 18px;font-size:16px;line-height:1.5;color:#ffffff;">
                                Olá, <strong style="color:#f5cee1;">{{ $greetingName }}</strong>
                            </p>
                            <p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#cccccc;">
                                A <strong style="color:#ffffff;">Becalima007</strong> agendou uma live:
                            </p>
                            <p style="margin:0 0 8px;font-size:20px;line-height:1.35;color:#ffffff;font-weight:700;">
                                {{ $titulo }}
                            </p>
                            <p style="margin:0 0 8px;font-size:14px;color:#f5cee1;">
                                {{ $when }}
                            </p>
                            <p style="margin:0 0 18px;font-size:14px;color:#999999;">
                                {{ $priceLabel }}
                            </p>

                            @if($preview !== '')
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
                                    <tr>
                                        <td style="padding:16px 18px;background-color:#1a1a1a;border-left:3px solid #f5cee1;border-radius:0 10px 10px 0;">
                                            <p style="margin:0;font-family:Georgia,'Times New Roman',serif;font-size:15px;line-height:1.55;color:#e8e8e8;font-style:italic;">
                                                “{{ $preview }}”
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
                                <tr>
                                    <td align="center" bgcolor="#f5cee1" style="border-radius:999px;background-color:#f5cee1;">
                                        <a href="{{ $liveUrl }}"
                                           target="_blank"
                                           style="display:inline-block;padding:14px 36px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#121212;text-decoration:none;">
                                            Entrar na live
                                        </a>
                                    </td>
                                </tr>
                            </table>
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
