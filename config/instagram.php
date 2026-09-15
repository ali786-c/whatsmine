<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Instagram Comment-Automation Module
    |--------------------------------------------------------------------------
    |
    | Everything the module needs lives under this namespace so the module stays
    | self-contained. Deleting this file (or the whole module) leaves the rest of
    | the application untouched.
    |
    */

    // Master switch. false = module dormant (no routes/scheduler/webhooks) while
    // keeping all files and data intact.
    'enabled' => env('INSTAGRAM_ENABLED', true),

    // Best-effort mirroring of funnel DM threads into the shared Inbox so human
    // agents can take over mid-funnel. Failures never break the funnel itself.
    'mirror_to_inbox' => env('INSTAGRAM_MIRROR_TO_INBOX', true),

    // Graph API version used for every call (graph.facebook.com/{version}).
    'api_version' => env('INSTAGRAM_GRAPH_VERSION', 'v20.0'),

    // Queue name for all module jobs (needs its own worker flag in deployment).
    'queue' => env('INSTAGRAM_QUEUE', 'instagram'),

    // Outbound sends per minute per connected IG account (self-protection).
    'rate_limit_per_minute' => (int) env('INSTAGRAM_SENDS_PER_MINUTE', 30),

    // Follow-up sends to a commenter who replied without the follow-gate
    // keyword. This budget also bounds the conversational gate loop ("no" →
    // re-ask, "yes" → keyword reminder); when it runs out the funnel closes and
    // the thread is handed to a human agent in the Inbox. Meta allows follow-ups
    // only within the 24h window opened by the user's reply.
    'max_nudges' => (int) env('INSTAGRAM_MAX_NUDGES', 2),

];
