<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use App\Modules\Ecommerce\Services\PayloadNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Imports/refreshes store products + inventory levels, one page per invocation,
 * chaining the next page via re-dispatch. Sets products_synced_at on completion.
 */
class SyncStoreProductsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $storeId,
        public readonly ?string $cursor = null,
    ) {}

    public function handle(PayloadNormalizer $normalizer): void
    {
        \App\Services\ShopifyLogger::log('Product Sync Job Started', ['store_id' => $this->storeId, 'cursor' => $this->cursor]);

        try {
            $store = EcommerceStore::find($this->storeId);
            if (! $store) {
                \App\Services\ShopifyLogger::log('Product Sync Aborted', ['store_id' => $this->storeId, 'reason' => 'Store not found in DB'], 'warning');
                return;
            }

            if ($store->platform === 'shopify') {
                \App\Services\ShopifyLogger::log('Fetching Products from Shopify', ['domain' => $store->domain, 'cursor' => $this->cursor]);
            }

            $page = StoreClientFactory::for($store)->fetchProducts($this->cursor);

            $syncedCount = 0;
            foreach ($page['products'] as $raw) {
                $product = $normalizer->mapProduct($store->platform, $raw);
                if (($product['external_id'] ?? '') === '') {
                    continue;
                }

                EcommerceProduct::updateOrCreate(
                    ['store_id' => $store->id, 'external_id' => $product['external_id']],
                    array_merge($product, ['workspace_id' => $store->workspace_id]),
                );
                $syncedCount++;
            }

            \App\Services\ShopifyLogger::log('Product Sync Page Completed', ['store_id' => $this->storeId, 'products_synced_this_page' => $syncedCount]);

            if ($page['next'] !== null && $page['next'] !== $this->cursor) {
                self::dispatch($store->id, $page['next']);
                return;
            }

            $store->update(['products_synced_at' => now()]);
            \App\Services\ShopifyLogger::log('Product Sync Fully Completed', ['store_id' => $this->storeId]);

        } catch (\Throwable $e) {
            \App\Services\ShopifyLogger::log('Product Sync Job Exception', ['store_id' => $this->storeId, 'error' => $e->getMessage()], 'error');
            throw $e;
        }
    }
}
