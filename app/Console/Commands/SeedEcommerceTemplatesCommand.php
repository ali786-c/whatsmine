<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Jobs\SeedDefaultEcommerceTemplatesJob;

class SeedEcommerceTemplatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ecommerce:seed-templates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed default e-commerce WhatsApp templates for all existing connected WABAs.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to seed e-commerce templates for existing users...');
        
        $wabas = WhatsappBusinessAccount::all();
        $count = 0;

        foreach ($wabas as $waba) {
            // Dispatch the job synchronously to execute immediately during the command
            SeedDefaultEcommerceTemplatesJob::dispatchSync($waba->id);
            $count++;
        }

        $this->info("Successfully seeded templates for {$count} WhatsApp Business Accounts!");
    }
}
