<?php

namespace App\Services;

use App\Mail\AlertMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers an operational alert to every configured channel: email first,
 * then Slack if a webhook is set (config/alerts.php).
 *
 * One channel failing never stops the others; each result is returned so the
 * incident log records what actually went out.
 */
class AlertNotifier
{
    /** @return array<string, string> channel => 'sent' | 'skipped' | 'failed: …' */
    public function send(string $subject, string $message): array
    {
        $results = [];

        $recipients = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('alerts.mail_to')),
        )));

        if ($recipients === []) {
            $results['mail'] = 'skipped';
        } else {
            try {
                Mail::to($recipients)->send(new AlertMail($subject, $message));
                $results['mail'] = 'sent';
            } catch (Throwable $e) {
                $results['mail'] = 'failed: '.$e->getMessage();
            }
        }

        $webhook = (string) config('alerts.slack_webhook');

        if ($webhook === '') {
            $results['slack'] = 'skipped';
        } else {
            try {
                $response = Http::timeout(10)->post($webhook, ['text' => "*{$subject}*\n{$message}"]);
                $results['slack'] = $response->successful() ? 'sent' : 'failed: HTTP '.$response->status();
            } catch (Throwable $e) {
                $results['slack'] = 'failed: '.$e->getMessage();
            }
        }

        Log::warning("[alert] {$subject}", ['message' => $message, 'channels' => $results]);

        return $results;
    }
}
