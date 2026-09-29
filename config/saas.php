<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App branding
    |--------------------------------------------------------------------------
    | Used by LandingLayout and email templates.
    | Override these values in config/app.php (app.name) or via .env APP_NAME.
    */
    'app_name' => env('APP_NAME', 'wa.careerinpak.com'),
    'tagline' => env('SAAS_TAGLINE', 'Customer messaging on WhatsApp'),
    'support_email' => env('SAAS_SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS', 'support@example.com')),
    // External help/documentation URL shown in the client "Help & Docs" nav
    // item. Leave blank to hide the link. Configure in the admin panel or .env.
    'docs_url' => env('SAAS_DOCS_URL', ''),
    // Standalone end-user documentation website (docs/user-guide). Leave blank
    // to hide the "User Guide" nav item. Point to a static-hosted URL, e.g.
    // https://docs.example.com or wherever docs/user-guide is uploaded.
    'user_guide_url' => env('SAAS_USER_GUIDE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Marketing / Landing page
    |--------------------------------------------------------------------------
    */
    'marketing' => [
        'nav' => [
            ['label' => 'Features', 'href' => '#features'],
            ['label' => 'Pricing', 'href' => '/pricing'],
        ],
        'footer_links' => [
            ['label' => 'Privacy', 'href' => '/privacy'],
            ['label' => 'Terms', 'href' => '/terms'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding defaults (overridden per-Client in Phase 5)
    |--------------------------------------------------------------------------
    */
    'branding' => [
        'primary_color' => env('SAAS_PRIMARY_COLOR', '#467235'),
        'logo_path' => env('SAAS_LOGO_PATH', null),
    ],

];
