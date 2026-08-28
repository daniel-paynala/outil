<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $count === 1 ? 'Nouveau document' : "$count nouveaux documents" }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f5f5f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#1c1917;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f5f5f4;">
        <tr>
            <td align="center" style="padding:48px 16px;">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background-color:#ffffff;border-radius:12px;border:1px solid #e7e5e4;">

                    {{-- Header --}}
                    <tr>
                        <td style="padding:32px 40px 0 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                                <tr>
                                    <td style="font-size:13px;font-weight:600;letter-spacing:1.5px;color:#dc2626;text-transform:uppercase;">
                                        Arche
                                    </td>
                                    <td align="right" style="font-size:11px;letter-spacing:0.5px;color:#a8a29e;text-transform:uppercase;">
                                        @if ($count === 1) Nouveau document @else {{ $count }} nouveaux documents @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Greeting --}}
                    <tr>
                        <td style="padding:24px 40px 0 40px;">
                            <h1 style="margin:0;font-size:22px;font-weight:600;line-height:1.3;color:#1c1917;letter-spacing:-0.02em;">
                                Bonjour {{ $recipientName }},
                            </h1>
                            <p style="margin:12px 0 0 0;font-size:15px;line-height:1.6;color:#44403c;">
                                @if ($count === 1)
                                    Un nouveau document a été ajouté au projet <strong style="color:#1c1917;">{{ $project['project_name'] }}</strong>.
                                @else
                                    <strong style="color:#1c1917;">{{ $count }} nouveaux documents</strong> ont été ajoutés au projet <strong style="color:#1c1917;">{{ $project['project_name'] }}</strong>.
                                @endif
                            </p>
                        </td>
                    </tr>

                    {{-- Documents list --}}
                    <tr>
                        <td style="padding:24px 40px 8px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fafaf9;border:1px solid #e7e5e4;border-left:3px solid {{ $project['project_color'] ?? '#dc2626' }};border-radius:8px;">
                                <tr>
                                    <td style="padding:14px 20px 6px 20px;">
                                        <p style="margin:0;font-size:11px;letter-spacing:0.5px;color:#a8a29e;text-transform:uppercase;font-weight:500;">
                                            {{ $project['project_name'] }}
                                        </p>
                                    </td>
                                </tr>
                                @foreach ($documents as $doc)
                                <tr>
                                    <td style="padding:6px 20px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="font-size:14px;font-weight:500;color:#1c1917;word-break:break-word;">
                                                    📎 {{ $doc['name'] }}
                                                </td>
                                                <td align="right" style="font-size:11px;color:#78716c;white-space:nowrap;padding-left:8px;">
                                                    @if (!empty($doc['size_bytes']))
                                                        @php
                                                            $size = (int) $doc['size_bytes'];
                                                            if ($size < 1024) $human = $size.' o';
                                                            elseif ($size < 1024 * 1024) $human = number_format($size / 1024, 0, ',', ' ').' Ko';
                                                            elseif ($size < 1024 * 1024 * 1024) $human = number_format($size / 1024 / 1024, 1, ',', ' ').' Mo';
                                                            else $human = number_format($size / 1024 / 1024 / 1024, 1, ',', ' ').' Go';
                                                        @endphp
                                                        {{ $human }}
                                                    @endif
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endforeach
                                <tr>
                                    <td style="padding:0 20px 14px 20px;"></td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- CTA --}}
                    <tr>
                        <td align="center" style="padding:16px 40px 8px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-radius:8px;background-color:#dc2626;">
                                        <a href="{{ $documentsUrl }}"
                                           style="display:inline-block;padding:13px 28px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;letter-spacing:-0.01em;">
                                            Voir les documents
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Fallback --}}
                    <tr>
                        <td style="padding:8px 40px 24px 40px;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#a8a29e;text-align:center;">
                                Ou ouvre ce lien dans ton navigateur :
                                <a href="{{ $documentsUrl }}" style="color:#dc2626;text-decoration:underline;word-break:break-all;">{{ $documentsUrl }}</a>
                            </p>
                        </td>
                    </tr>

                    {{-- Notice --}}
                    <tr>
                        <td style="padding:16px 40px 24px 40px;border-top:1px solid #e7e5e4;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#a8a29e;">
                                Tu reçois cet email car tu as activé les notifications par email pour les documents de projet. Les notifications sont regroupées : tu reçois un seul email récapitulant les fichiers ajoutés sur une fenêtre de 5 minutes. Tu peux désactiver ces emails à tout moment depuis tes paramètres dans Arche.
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
