<?php

namespace App\Console\Commands;

use App\Modules\Whatsapp\Jobs\ReseedDefaultEcommerceTemplatesJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Console\Command;

class WhatsappReseedTemplatesCommand extends Command
{
    protected $signature = 'whatsapp:reseed-templates
                            {--waba= : Specific internal WABA row id (optional, processes all if omitted)}
                            {--sync : Run the reseed inline instead of dispatching to the whatsapp queue}';

    protected $description = 'Re-submit the default ecommerce templates (fix rejected ones, create missing ones) for one or all WABAs';

    public function handle(): int
    {
        $query = WhatsappBusinessAccount::query()->orderBy('id');

        if ($wabaId = $this->option('waba')) {
            $query->where('id', $wabaId);
        }

        $wabas = $query->get();

        if ($wabas->isEmpty()) {
            $this->error('No WhatsApp business accounts found'.($wabaId ? " for id {$wabaId}" : '').'.');
            return self::FAILURE;
        }

        foreach ($wabas as $waba) {
            $label = "{$waba->display_name} (waba_id {$waba->waba_id})";

            if ($this->option('sync')) {
                $this->line("Reseeding {$label} inline…");
                (new ReseedDefaultEcommerceTemplatesJob($waba->id))->handle();
                $this->info("Done: {$label}");
            } else {
                ReseedDefaultEcommerceTemplatesJob::dispatch($waba->id)->onQueue('whatsapp');
                $this->info("Dispatched reseed job for {$label} on the whatsapp queue.");
            }
        }

        $this->newLine();
        $this->line('Rejected templates are re-submitted via Meta\'s edit endpoint (status resets to PENDING).');
        $this->line('Check the Templates page / logs for review outcomes. Meta reviews can take up to 24 hours.');

        return self::SUCCESS;
    }
}
