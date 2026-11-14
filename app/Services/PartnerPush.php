<?php

namespace App\Services;

use App\Models\IntegrationEndpoint;
use App\Models\IntegrationOutbox;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * HIMS -> partner systems.
 *
 * When something happens in HIMS (an applicant is hired, onboarding is completed, a trainee account is
 * created, a claim is decided...), the data is saved in integration_outbox and POSTed as JSON to that
 * partner's API address (set by the Super Admin in File Exchange > Connections). If the partner is
 * offline, the delivery stays in the outbox and is retried with the "Retry" button or
 * "php artisan hims:push-retry".
 *
 * What each partner receives:
 *   HR2    employee.onboarded, employee.activated              (employee information after onboarding)
 *   HR3    trainee.account_created, applicant.onboarded        (trainee account + applicant information)
 *   HR4    claim.decided, reimbursement.decided, payroll.inputs (claims, reimbursements, payroll inputs)
 *   ADMIN  applicant.hired                                      (Administrators: hired applicants)
 */
class PartnerPush
{
    public const PARTNERS = ['HR2', 'HR3', 'HR4', 'ADMIN'];

    public const LABELS = ['HR2' => 'HR2', 'HR3' => 'HR3', 'HR4' => 'HR4', 'ADMIN' => 'Administrators'];

    public const EVENTS = [
        'HR2' => ['employee.onboarded', 'employee.activated'],
        'HR3' => ['trainee.account_created', 'applicant.onboarded'],
        'HR4' => ['claim.decided', 'reimbursement.decided', 'payroll.inputs'],
        'ADMIN' => ['applicant.hired'],
    ];

    /** Save a delivery and try to send it right away. Never throws: HIMS keeps working if a partner is down. */
    public static function queue(string $partner, string $event, ?string $ref, array $data, bool $sendNow = true): ?int
    {
        if (! in_array($partner, self::PARTNERS, true)) {
            return null;
        }
        try {
            $row = IntegrationOutbox::query()->create([
                'partner' => $partner,
                'event' => mb_substr($event, 0, 60),
                'ref' => $ref !== null ? mb_substr($ref, 0, 80) : null,
                'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => 'pending',
            ]);
            if ($sendNow) {
                self::deliver($row);
            }

            return (int) $row->id;
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }

    /** Send one delivery. Returns true when the partner answered with HTTP 2xx. */
    public static function deliver(IntegrationOutbox $row): bool
    {
        $endpoint = IntegrationEndpoint::query()->find($row->partner);
        if (! $endpoint || ! $endpoint->enabled || trim((string) $endpoint->url) === '') {
            $row->update(['status' => 'pending', 'last_error' => 'No address set for '.self::LABELS[$row->partner].' yet. It will be sent once the address is saved.']);

            return false;
        }
        $token = '';
        if ($endpoint->token_encrypted) {
            try {
                $token = Crypt::decryptString($endpoint->token_encrypted);
            } catch (Throwable) {
                $row->update(['status' => 'failed', 'last_error' => 'The saved key could not be read. Save the partner key again.']);

                return false;
            }
        }

        $body = [
            'event' => $row->event,
            'delivery_id' => (int) $row->id,
            'reference' => $row->ref,
            'source' => 'HIMS (HR1)',
            'partner' => $row->partner,
            'sent_at' => now()->toIso8601String(),
            'data' => json_decode((string) $row->payload, true) ?? [],
        ];

        try {
            $request = Http::acceptJson()->asJson()->connectTimeout((int) config("hims.push_connect_timeout", 5))->timeout((int) config("hims.push_timeout", 10))
                ->withHeaders(['X-HIMS-Event' => $row->event, 'X-HIMS-Delivery' => (string) $row->id]);
            if ($token !== '') {
                $request = $request->withToken($token)->withHeaders(['X-Integration-Key' => $token]);
            }
            $response = $request->post($endpoint->url, $body);
            $ok = $response->successful();
            $row->update([
                'attempts' => $row->attempts + 1,
                'response_code' => $response->status(),
                'status' => $ok ? 'sent' : 'failed',
                'sent_at' => $ok ? now() : $row->sent_at,
                'last_error' => $ok ? null : mb_substr('HTTP '.$response->status().': '.trim(strip_tags((string) $response->body())), 0, 500),
            ]);

            return $ok;
        } catch (Throwable $error) {
            $row->update([
                'attempts' => $row->attempts + 1,
                'status' => 'failed',
                'last_error' => mb_substr('Could not reach '.$endpoint->url.': '.$error->getMessage(), 0, 500),
            ]);

            return false;
        }
    }

    /** Send everything waiting (pending or failed), oldest first. */
    public static function retry(?string $partner = null, int $limit = 100): array
    {
        $query = IntegrationOutbox::query()->whereIn('status', ['pending', 'failed'])->orderBy('id')->limit($limit);
        if ($partner !== null) {
            $query->where('partner', $partner);
        }
        $sent = 0;
        $failed = 0;
        foreach ($query->get() as $row) {
            self::deliver($row) ? $sent++ : $failed++;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public static function saveEndpoint(string $partner, string $url, ?string $token, bool $enabled, ?int $userId): void
    {
        $values = ['url' => $url, 'enabled' => $enabled, 'updated_by' => $userId];
        if ($token !== null) {
            $values['token_encrypted'] = $token === '' ? null : Crypt::encryptString($token);
        }
        IntegrationEndpoint::query()->updateOrCreate(['partner' => $partner], $values);
    }

    /** Settings and delivery log for the Connections page (keys are never returned). */
    public static function overview(int $limit = 40): array
    {
        $endpoints = IntegrationEndpoint::query()->get()->keyBy('partner');
        $counts = IntegrationOutbox::query()->selectRaw('partner, status, COUNT(*) AS n')->groupBy('partner', 'status')->get();
        $partners = [];
        foreach (self::PARTNERS as $p) {
            $e = $endpoints->get($p);
            $c = ['pending' => 0, 'sent' => 0, 'failed' => 0];
            foreach ($counts->where('partner', $p) as $row) {
                $c[$row->status] = (int) $row->n;
            }
            $partners[] = [
                'partner' => $p,
                'label' => self::LABELS[$p],
                'events' => self::EVENTS[$p],
                'url' => $e?->url ?? '',
                'hasToken' => (bool) ($e?->token_encrypted),
                'enabled' => $e ? (bool) $e->enabled : false,
                'counts' => $c,
            ];
        }
        $log = IntegrationOutbox::query()->orderByDesc('id')->limit($limit)
            ->get(['id', 'partner', 'event', 'ref', 'status', 'attempts', 'response_code', 'last_error', 'created_at', 'sent_at']);

        return ['partners' => $partners, 'log' => $log];
    }
}
