<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation Arche</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#1c1917;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f5f4;">
        <tr>
            <td align="center" style="padding:48px 16px;">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background-color:#ffffff;border-radius:12px;border:1px solid #e7e5e4;">

                    {{-- Header --}}
                    <tr>
                        <td style="padding:32px 40px 0 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="font-size:13px;font-weight:600;letter-spacing:1.5px;color:#dc2626;text-transform:uppercase;">
                                        Arche
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:24px 40px 8px 40px;">
                            <h1 style="margin:0;font-size:24px;font-weight:600;line-height:1.3;color:#1c1917;letter-spacing:-0.02em;">
                                Bienvenue {{ $name }},
                            </h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 40px 24px 40px;">
                            <p style="margin:0;font-size:15px;line-height:1.6;color:#44403c;">
                                @if ($inviterName)
                                    {{ $inviterName }} t'a invité à rejoindre <strong style="color:#1c1917;">Arche</strong>, la plateforme de gestion de projets de Paynala.
                                @else
                                    Tu as été invité à rejoindre <strong style="color:#1c1917;">Arche</strong>, la plateforme de gestion de projets de Paynala.
                                @endif
                            </p>
                            <p style="margin:16px 0 0 0;font-size:15px;line-height:1.6;color:#44403c;">
                                Pour activer ton compte, clique sur le bouton ci-dessous et choisis ton mot de passe.
                            </p>
                        </td>
                    </tr>

                    {{-- CTA --}}
                    <tr>
                        <td align="center" style="padding:8px 40px 24px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-radius:8px;background-color:#dc2626;">
                                        <a href="{{ $actionLink }}"
                                           style="display:inline-block;padding:14px 32px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;letter-spacing:-0.01em;">
                                            Activer mon compte
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Fallback link --}}
                    <tr>
                        <td style="padding:0 40px 24px 40px;">
                            <p style="margin:0;font-size:13px;line-height:1.5;color:#78716c;">
                                Ou copie-colle ce lien dans ton navigateur :
                            </p>
                            <p style="margin:6px 0 0 0;font-size:12px;line-height:1.5;word-break:break-all;">
                                <a href="{{ $actionLink }}" style="color:#dc2626;text-decoration:underline;">{{ $actionLink }}</a>
                            </p>
                        </td>
                    </tr>

                    {{-- Notice --}}
                    <tr>
                        <td style="padding:16px 40px 24px 40px;border-top:1px solid #e7e5e4;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#a8a29e;">
                                Ce lien d'invitation est valable 24 heures. Si tu n'attendais pas cette invitation, tu peux ignorer ce message — aucun compte ne sera créé sans ton action.
                            </p>
                        </td>
                    </tr>

                </table>

                {{-- Footer --}}
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;margin-top:24px;">
                    <tr>
                        <td align="center" style="font-size:11px;line-height:1.5;color:#a8a29e;">
                            Arche · Paynala<br>
                            Cet email a été envoyé automatiquement, merci de ne pas y répondre.
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
