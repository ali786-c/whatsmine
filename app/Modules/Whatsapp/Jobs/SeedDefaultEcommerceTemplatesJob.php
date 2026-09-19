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

        foreach (ReseedDefaultEcommerceTemplatesJob::defaultTemplates() as $t) {
            $problems = TemplateValidator::problems($t);
            if ($problems !== []) {
                Log::error("SeedDefaultEcommerceTemplatesJob: skipping {$t['name']}, payload invalid", ['problems' => $problems]);
                continue;
            }

            $existing = WhatsappTemplate::where('waba_id', $waba->waba_id)
                ->where('name', $t['name'])
                ->first();

            if ($existing && $existing->meta_template_id) {
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
}
