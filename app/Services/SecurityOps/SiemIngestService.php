<?php

namespace App\Services\SecurityOps;

use App\Models\SecurityAlert;
use App\Models\SiemIntegration;
use App\Models\SiemSignal;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Ingests a SIEM/SOAR webhook delivery into the connector's bank.
 *
 * Authentication: X-Atheris-Timestamp (unix seconds) and
 * X-Atheris-Signature: sha256=<hex HMAC-SHA256 of "{timestamp}.{raw body}" keyed with the connector's
 * webhook secret>. Deliveries outside the tolerance window are rejected as replays.
 *
 * Payload keys whose name suggests personal or card data are masked before storage. Signals at or
 * above config('secops.siem_auto_alert_severities') raise a Security Alert for triage.
 */
class SiemIngestService
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    private const PII_KEY = '/(bvn|nin|account_?(no|number)|card|pan|phone|msisdn|email|dob|password|secret|token)/i';

    public function verify(SiemIntegration $integration, string $rawBody, ?string $timestamp, ?string $signature): bool
    {
        if (! $integration->webhook_secret || ! $timestamp || ! $signature || ! ctype_digit($timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > config('secops.siem_signature_tolerance_seconds', 300)) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $integration->webhook_secret);

        return hash_equals($expected, $signature);
    }

    public static function sign(string $secret, string $timestamp, string $rawBody): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /** @return array{signal: SiemSignal, alert: ?SecurityAlert, duplicate: bool} */
    public function ingest(SiemIntegration $integration, array $payload): array
    {
        $externalId = (string) (Arr::get($payload, 'external_id') ?? Arr::get($payload, 'id') ?? '');
        $severity = strtolower((string) Arr::get($payload, 'severity', 'medium'));
        $severity = in_array($severity, self::SEVERITIES, true) ? $severity : 'medium';

        if ($externalId !== '') {
            $existing = SiemSignal::withoutGlobalScopes()
                ->where('organization_id', $integration->organization_id)
                ->where('provider', $integration->provider)
                ->where('external_id', $externalId)->first();
            if ($existing) {
                return ['signal' => $existing, 'alert' => null, 'duplicate' => true];
            }
        }

        $received = Arr::get($payload, 'received_at');
        $signal = SiemSignal::create([
            'organization_id' => $integration->organization_id,
            'provider' => $integration->provider,
            // Deliveries without their own id get one, so the signal stays addressable.
            'external_id' => $externalId !== '' ? mb_substr($externalId, 0, 191) : 'ATH-'.Str::upper(Str::random(12)),
            'severity' => $severity,
            'title' => mb_substr((string) Arr::get($payload, 'title', 'Untitled SIEM signal'), 0, 255),
            'payload' => $this->redact(Arr::except($payload, ['title', 'severity', 'external_id', 'id'])),
            'received_at' => $received ? Carbon::parse($received) : now(),
        ]);

        $integration->forceFill(['last_signal_at' => now(), 'status' => 'active'])->save();

        $alert = in_array($severity, config('secops.siem_auto_alert_severities', []), true)
            ? $this->raiseAlert($signal, $integration)
            : null;

        return ['signal' => $signal, 'alert' => $alert, 'duplicate' => false];
    }

    /** Raise a Security Alert from a signal (also used by the manual "Raise alert" action). */
    public function raiseAlert(SiemSignal $signal, SiemIntegration|string $source): SecurityAlert
    {
        $orgId = $signal->organization_id;
        $label = $source instanceof SiemIntegration
            ? (SiemIntegration::PROVIDERS[$source->provider] ?? $source->provider)
            : $source;

        $alert = SecurityAlert::create([
            'organization_id' => $orgId,
            'alert_id_code' => SecurityAlert::generateNextCode($orgId),
            'title' => $signal->title,
            'description' => "Raised from {$label} signal ".($signal->external_id ?: "#{$signal->id}").'.',
            'source' => $label,
            'severity' => in_array($signal->severity, SecurityAlert::SEVERITIES, true) ? $signal->severity : 'medium',
            'status' => 'new',
            'raw_data' => $signal->payload,
            'received_at' => $signal->received_at ?? now(),
        ]);

        $signal->forceFill(['security_alert_id' => $alert->id])->save();

        return $alert;
    }

    public function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->redact($value);
            } elseif (is_string($key) && preg_match(self::PII_KEY, $key) && $value !== null && $value !== '') {
                $str = (string) $value;
                $payload[$key] = strlen($str) > 4 ? str_repeat('•', 4).substr($str, -2) : '••••';
            }
        }

        return $payload;
    }
}
