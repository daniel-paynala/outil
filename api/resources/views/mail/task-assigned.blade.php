<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tâche attribuée</title>
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
                                        Tâche attribuée
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Greeting --}}
                    <tr>
                        <td style="padding:24px 40px 0 40px;">
                            <h1 style="margin:0;font-size:22px;font-weight:600;line-height:1.3;color:#1c1917;letter-spacing:-0.02em;">
                                Bonjour {{ $assigneeName }},
                            </h1>
                            <p style="margin:12px 0 0 0;font-size:15px;line-height:1.6;color:#44403c;">
                                @if ($assignerName)
                                    <strong style="color:#1c1917;">{{ $assignerName }}</strong> vient de t'attribuer une tâche sur
                                @else
                                    Une nouvelle tâche t'a été attribuée sur
                                @endif
                                <strong style="color:#1c1917;">{{ $task['project_name'] }}</strong>.
                            </p>
                        </td>
                    </tr>

                    {{-- Task card --}}
                    <tr>
                        <td style="padding:24px 40px 8px 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#fafaf9;border:1px solid #e7e5e4;border-left:3px solid {{ $task['project_color'] ?? '#dc2626' }};border-radius:8px;">
                                <tr>
                                    <td style="padding:18px 20px 6px 20px;">
                                        <p style="margin:0;font-size:11px;letter-spacing:0.5px;color:#a8a29e;text-transform:uppercase;font-weight:500;">
                                            {{ $task['project_name'] }}
                                            @if (!empty($task['column_name']))
                                                · {{ $task['column_name'] }}
                                            @endif
                                        </p>
                                        <h2 style="margin:6px 0 0 0;font-size:17px;font-weight:600;line-height:1.4;color:#1c1917;letter-spacing:-0.01em;">
                                            {{ $task['title'] }}
                                        </h2>
                                    </td>
                                </tr>

                                @if (!empty($task['description']))
                                <tr>
                                    <td style="padding:8px 20px 8px 20px;">
                                        <p style="margin:0;font-size:14px;line-height:1.55;color:#57534e;white-space:pre-wrap;">{{ \Illuminate\Support\Str::limit($task['description'], 240) }}</p>
                                    </td>
                                </tr>
                                @endif

                                @if (!empty($task['priority']) || !empty($task['due_date']))
                                <tr>
                                    <td style="padding:6px 20px 18px 20px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                @if (!empty($task['priority']))
                                                    @php
                                                        $priorityColors = [
                                                            'urgent' => ['bg' => '#fef2f2', 'fg' => '#dc2626'],
                                                            'high'   => ['bg' => '#fff7ed', 'fg' => '#d97706'],
                                                            'medium' => ['bg' => '#eff6ff', 'fg' => '#2563eb'],
                                                            'low'    => ['bg' => '#f5f5f4', 'fg' => '#78716c'],
                                                        ];
                                                        $pc = $priorityColors[$task['priority']] ?? $priorityColors['low'];
                                                    @endphp
                                                    <td style="padding:6px 10px;background-color:{{ $pc['bg'] }};color:{{ $pc['fg'] }};border-radius:6px;font-size:11px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;">
                                                        {{ $task['priority'] }}
                                                    </td>
                                                    <td style="width:8px;"></td>
                                                @endif
                                                @if (!empty($task['due_date']))
                                                    <td style="padding:6px 10px;background-color:#f5f5f4;color:#57534e;border-radius:6px;font-size:12px;font-weight:500;">
                                                        Échéance · {{ \Carbon\Carbon::parse($task['due_date'])->locale('fr')->isoFormat('D MMM YYYY') }}
                                                    </td>
                                                @endif
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @endif
                            </table>
                        </td>
                    </tr>

                    {{-- CTA --}}
                    <tr>
                        <td align="center" style="padding:16px 40px 8px 40px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="border-radius:8px;background-color:#dc2626;">
                                        <a href="{{ $taskUrl }}"
                                           style="display:inline-block;padding:13px 28px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;letter-spacing:-0.01em;">
                                            Ouvrir la tâche
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
                                <a href="{{ $taskUrl }}" style="color:#dc2626;text-decoration:underline;word-break:break-all;">{{ $taskUrl }}</a>
                            </p>
                        </td>
                    </tr>

                    {{-- Notice --}}
                    <tr>
                        <td style="padding:16px 40px 24px 40px;border-top:1px solid #e7e5e4;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#a8a29e;">
                                Tu reçois cet email car tu as activé les notifications par email pour les attributions de tâches. Tu peux désactiver ces emails à tout moment depuis tes paramètres dans Arche.
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
