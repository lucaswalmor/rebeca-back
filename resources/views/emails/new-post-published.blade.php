<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Novo post — Becalima007</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td { font-family: Arial, Helvetica, sans-serif !important; }
    </style>
    <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#0a0a0a;width:100% !important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
    <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">
        A Becalima007 publicou um conteúdo novo só para assinantes.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0a0a0a;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#121212;border:1px solid #2a2a2a;border-radius:16px;overflow:hidden;">
                    {{-- Top accent bar --}}
                    <tr>
                        <td style="height:4px;background:linear-gradient(90deg,#f5cee1 0%,#e8a0c0 50%,#f5cee1 100%);font-size:0;line-height:0;">&nbsp;</td>
                    </tr>

                    {{-- Brand header --}}
                    <tr>
                        <td align="center" style="padding:36px 28px 12px;background-color:#121212;">
                            <p style="margin:0 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:28px;line-height:1.2;color:#f5cee1;letter-spacing:0.04em;">
                                becalima007
                            </p>
                            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:0.18em;text-transform:uppercase;color:#999999;">
                                Conteúdo exclusivo
                            </p>
                        </td>
                    </tr>

                    {{-- Divider --}}
                    <tr>
                        <td style="padding:16px 28px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-top:1px solid #2a2a2a;font-size:0;line-height:0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:28px 28px 8px;background-color:#121212;font-family:Arial,Helvetica,sans-serif;color:#ffffff;">
                            <p style="margin:0 0 18px;font-size:16px;line-height:1.5;color:#ffffff;">
                                Olá, <strong style="color:#f5cee1;">{{ $greetingName }}</strong>
                            </p>
                            <p style="margin:0 0 18px;font-size:15px;line-height:1.6;color:#cccccc;">
                                Tem novidade pra você. A <strong style="color:#ffffff;">Becalima007</strong> acabou de soltar um post novo no site.
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

                            <p style="margin:0 0 28px;font-size:14px;line-height:1.55;color:#999999;">
                                Entre agora e confira o conteúdo completo — exclusivo para assinantes.
                            </p>

                            {{-- CTA --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto 8px;">
                                <tr>
                                    <td align="center" bgcolor="#f5cee1" style="border-radius:999px;background-color:#f5cee1;">
                                        <a href="{{ $homeUrl }}"
                                           target="_blank"
                                           style="display:inline-block;padding:14px 36px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#121212;text-decoration:none;letter-spacing:0.02em;">
                                            Ver novo post
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Spacer --}}
                    <tr>
                        <td style="padding:12px 28px 0;background-color:#121212;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-top:1px solid #2a2a2a;font-size:0;line-height:0;">&nbsp;</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" style="padding:20px 28px 28px;background-color:#121212;font-family:Arial,Helvetica,sans-serif;">
                            <p style="margin:0 0 10px;font-size:12px;line-height:1.5;color:#777777;">
                                Você recebe este e-mail porque ativou as notificações em
                                <span style="color:#f5cee1;">Minha Conta → Notificações</span>.
                            </p>
                            <p style="margin:0;font-size:11px;line-height:1.5;color:#555555;">
                                Conteúdo destinado a maiores de 18 anos.<br>
                                © {{ date('Y') }} Becalima007 ·
                                <a href="{{ $homeUrl }}" style="color:#888888;text-decoration:underline;">becalima007.com.br</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
