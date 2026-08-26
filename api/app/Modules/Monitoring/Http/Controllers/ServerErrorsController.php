<?php

namespace App\Modules\Monitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dernières erreurs du serveur, rendues consultables depuis l'app.
 *
 * Jusqu'ici elles vivaient dans les logs d'un conteneur : pour savoir pourquoi
 * quelque chose avait échoué, il fallait un accès SSH, le bon nom de conteneur
 * et la bonne commande. Autant dire que personne ne regardait, et que les
 * pannes se découvraient par leurs symptômes plusieurs jours plus tard.
 *
 * Deux sources, qui ne disent pas la même chose :
 *
 *  - **`failed_jobs`** : un traitement de fond qui a épuisé ses tentatives.
 *    C'est la source la plus fiable, parce qu'une ligne y est écrite même
 *    quand personne ne regarde.
 *  - **Le journal applicatif** : les exceptions des requêtes HTTP, qui ne
 *    laissent aucune autre trace.
 *
 * ## Ce qui est expurgé, et pourquoi
 *
 * Une trace d'exception contient volontiers un jeton d'authentification, une
 * chaîne de connexion ou une clé d'API — c'est justement ce qui rend les logs
 * sensibles. Exposer ces lignes dans l'app les sortirait du serveur. Elles
 * passent donc par un masquage avant d'être rendues, et l'accès reste réservé
 * aux comptes administrateurs.
 */
class ServerErrorsController extends Controller
{
    /** Au-delà, on noie l'information récente sous l'ancienne. */
    private const LIMIT = 25;

    /** Dernier segment du journal à lire — un fichier de log peut peser lourd. */
    private const LOG_TAIL_BYTES = 256 * 1024;

    public function show(): JsonResponse
    {
        $failed = $this->failedJobs();
        $logged = $this->logEntries();

        return response()->json([
            'failed_jobs' => $failed,
            'log' => $logged,
            'status' => ($failed === [] && $logged === []) ? 'ok' : 'warn',
            'log_readable' => $this->logPath() !== null,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function failedJobs(): array
    {
        try {
            return DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(self::LIMIT)
                ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->map(fn ($row) => [
                    'id' => $row->uuid,
                    'queue' => $row->queue,
                    'job' => $this->jobName($row->payload),
                    'message' => $this->redact($this->firstLine($row->exception)),
                    'at' => $row->failed_at,
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Entrées de niveau erreur du journal du jour.
     *
     * On ne lit que la fin du fichier : un journal de production se compte en
     * dizaines de mégaoctets, et seule la période récente a une valeur de
     * diagnostic.
     *
     * @return array<int, array<string, mixed>>
     */
    private function logEntries(): array
    {
        $path = $this->logPath();
        if ($path === null) {
            return [];
        }

        try {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return [];
            }

            $size = filesize($path) ?: 0;
            if ($size > self::LOG_TAIL_BYTES) {
                fseek($handle, -self::LOG_TAIL_BYTES, SEEK_END);
                fgets($handle); // rejoindre le début de ligne suivant
            }

            $entries = [];
            while (($line = fgets($handle)) !== false) {
                // Format Laravel : « [2026-08-27 09:12:44] production.ERROR: … »
                if (! preg_match('/^\[([^\]]+)\]\s+\S+\.(ERROR|CRITICAL|ALERT|EMERGENCY):\s*(.*)$/', $line, $m)) {
                    continue;
                }
                $entries[] = [
                    'at' => $m[1],
                    'level' => $m[2],
                    'message' => $this->redact(mb_substr(trim($m[3]), 0, 400)),
                ];
            }
            fclose($handle);

            return array_slice(array_reverse($entries), 0, self::LIMIT);
        } catch (Throwable) {
            return [];
        }
    }

    private function logPath(): ?string
    {
        foreach ([
            storage_path('logs/laravel-'.now()->format('Y-m-d').'.log'),
            storage_path('logs/laravel.log'),
        ] as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Masque ce qui ne doit pas quitter le serveur.
     *
     * Les motifs visent la forme des secrets, pas leur nom : un jeton porteur
     * reste reconnaissable même quand la clé qui le portait a été renommée.
     */
    private function redact(string $text): string
    {
        return (string) preg_replace(
            [
                '/\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\b/', // JWT
                '/\b(gh[pousr]_[A-Za-z0-9]{20,})\b/',                                // jeton GitHub
                '/(password|secret|token|key|authorization)["\'\s:=]+[^\s,;"\']{6,}/i',
                '/\b[\w.+-]+@[\w-]+\.[\w.]+\b/',                                     // adresses
                '/(postgres(?:ql)?:\/\/)[^@\s]+@/',                                  // chaîne de connexion
            ],
            ['[jeton masqué]', '[jeton masqué]', '$1=[masqué]', '[adresse masquée]', '$1[identifiants masqués]@'],
            $text,
        );
    }

    private function firstLine(?string $text): string
    {
        return trim(strtok((string) $text, "\n") ?: '');
    }

    private function jobName(?string $payload): string
    {
        $decoded = json_decode((string) $payload, true);

        return $decoded['displayName'] ?? 'inconnu';
    }
}
