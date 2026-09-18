<?php
$meta = \App\Modules\Core\Support\CredentialResolver::system()->meta();
$appId = $meta?->appId();
$appSecret = $meta?->appSecret();

if (!$appId || !$appSecret) {
    echo "Meta App ID or Secret is missing in the system configuration.\n";
    return;
}

echo "Checking Webhooks for App ID: {$appId}...\n";

$response = \Illuminate\Support\Facades\Http::get("https://graph.facebook.com/v20.0/{$appId}/subscriptions", [
    'access_token' => "{$appId}|{$appSecret}"
]);

if ($response->successful()) {
    $data = $response->json('data') ?? [];
    $found = false;
    foreach ($data as $subscription) {
        if ($subscription['object'] === 'whatsapp_business_account') {
            $found = true;
            echo "\nWhatsApp Business Account Webhooks found:\n";
            echo "Status: " . ($subscription['active'] ? 'Active' : 'Inactive') . "\n";
            echo "Callback URL: " . $subscription['callback_url'] . "\n";
            echo "Subscribed Fields:\n";
            foreach ($subscription['fields'] as $field) {
                echo " - " . $field['name'] . "\n";
            }
            
            $required = ['history', 'smb_app_state_sync', 'smb_message_echoes'];
            $existingFields = array_column($subscription['fields'], 'name');
            $missing = array_diff($required, $existingFields);
            
            if (empty($missing)) {
                echo "\nSUCCESS: All required coexistence webhooks are enabled!\n";
            } else {
                echo "\nWARNING: The following required coexistence webhooks are MISSING:\n";
                foreach ($missing as $m) {
                    echo " - " . $m . "\n";
                }
            }
        }
    }
    if (!$found) {
        echo "No 'whatsapp_business_account' subscriptions found for this app.\n";
    }
} else {
    echo "Failed to fetch subscriptions. Error: " . $response->body() . "\n";
}
