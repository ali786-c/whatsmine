<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Modules\Whatsapp\Services\TemplateValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Seeds the default ecommerce templates on a NEW WABA and auto-submits
 * them to Meta for approval. Definitions live in
 * ReseedDefaultEcommerceTemplatesJob::defaultTemplates() so new-WABA
 * seeding and manual reseeding can never drift apart.
 */
class SeedDefaultEcommerceTemplatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $wabaId) {}

    public function handle(): void
    {
        $waba = WhatsappBusinessAccount::find($this->wabaId);
        if (! $waba) {
            return;
        }

        $client = CloudApiClient::forWorkspace($waba->workspace_id);
        if (! $client) {
            Log::warning('SeedDefaultEcommerceTemplatesJob: No Cloud API Client configured.');
            return;
        }

        // Names that already exist on Meta (created via WhatsApp Manager or a
        // previous install). Re-creating them would burn the 100-templates/hour
        // quota on a guaranteed "duplicate name" rejection.
        $metaNames = collect($client->fetchTemplates($waba->waba_id))->pluck('name')->all();

        foreach (ReseedDefaultEcommerceTemplatesJob::defaultTemplates($waba->workspace_id) as $t) {
            $problems = TemplateValidator::problems($t);
            if ($problems !== []) {
                Log::error("SeedDefaultEcommerceTemplatesJob: skipping {$t['name']}, payload invalid", ['problems' => $problems]);
                continue;
            }

            $existing = WhatsappTemplate::where('waba_id', $waba->waba_id)
                ->where('name', $t['name'])
                ->where('language', 'en_US')
                ->first();

            if ($existing && $existing->meta_template_id) {
                continue;
            }

            if (in_array($t['name'], $metaNames, true)) {
                $this->backfillLocalRecord($waba, $t, $existing);
                continue;
            }

            try {
                $metaPayload = [
                    'name' => $t['name'],
                    'language' => 'en_US',
                    'category' => $t['category'],
                    'components' => $t['components'],
                ];

                $resp = $client->submitTemplate($waba->waba_id, $metaPayload);
                $status = 'PENDING';
                $metaId = null;
                $rejectionReason = null;

                if ($resp->successful()) {
                    $metaId = $resp->json('id');
                } else {
                    $status = 'REJECTED';
                    $rejectionReason = $resp->json('error.error_user_msg') ?? $resp->json('error.message') ?? 'Meta rejected template.';
                    Log::warning("Failed to seed template {$t['name']}", ['error' => $rejectionReason]);
                }

                if ($existing) {
                    $existing->update([
                        'status' => $status,
                        'meta_template_id' => $metaId,
                        'rejection_reason' => $rejectionReason,
                        'components' => $t['components'],
                    ]);
                } else {
                    WhatsappTemplate::create([
                        'workspace_id' => $waba->workspace_id,
                        'waba_id' => $waba->waba_id,
                        'name' => $t['name'],
                        'language' => 'en_US',
                        'category' => $t['category'],
                        'status' => $status,
                        'components' => $t['components'],
                        'meta_template_id' => $metaId,
                        'rejection_reason' => $rejectionReason,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Exception seeding template {$t['name']}: " . $e->getMessage());
            }
        }
    }

    /**
     * The template exists on Meta but our local record is missing or has no
     * Meta id (fresh DB, demo ghosts, partial sync). Record it locally as
     * PENDING; "Sync from Meta" will reconcile the real status.
     */
    private function backfillLocalRecord(WhatsappBusinessAccount $waba, array $t, ?WhatsappTemplate $existing): void
    {
        $payload = [
            'status' => 'PENDING',
            'components' => $t['components'],
            'rejection_reason' => null,
        ];

        try {
            if ($existing) {
                $existing->update($payload);
            } else {
                WhatsappTemplate::create($payload + [
                    'workspace_id' => $waba->workspace_id,
                    'waba_id' => $waba->waba_id,
                    'name' => $t['name'],
                    'language' => 'en_US',
                    'category' => $t['category'],
                ]);
            }

            Log::info("SeedDefaultEcommerceTemplatesJob: {$t['name']} already exists on Meta — local record backfilled.");
        } catch (\Exception $e) {
            Log::error("SeedDefaultEcommerceTemplatesJob: backfill failed for {$t['name']}: ".$e->getMessage());
        }
    }
}
