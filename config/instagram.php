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

    // Real follow verification through the Graph User Profile API
    // (is_user_follow_business) once the participant has replied in the DM
    // thread. true  = verify: keyword/YES replies only deliver when the API
    //                 confirms the follow; verified non-followers get the ask
    //                 repeated and the funnel never completes on a lie.
    //         false = trust the user's YES (legacy behaviour, no extra Graph call).
    // Every inconclusive case (no DM consent yet, Graph/network error, missing
    // field) FAILS OPEN — an unknown must never block a real lead.
    'follow_check' => env('INSTAGRAM_FOLLOW_CHECK', true),

    // Follow-up sends to a commenter who replied without the follow-gate
    // keyword ("haha", questions, gibberish). When it runs out the funnel
    // closes and the thread is handed to a human agent in the Inbox. Meta
    // allows follow-ups only within the 24h window opened by the user's reply.
    'max_nudges' => (int) env('INSTAGRAM_MAX_NUDGES', 2),

    // Budget for the follow-gate loop specifically ("I followed" taps by
    // unverified users, "no"/"Not yet" answers). These are deliberate user
    // actions, so they get more chances than generic keyword nudges. On
    // exhaustion one final handoff message is sent (never silence), then the
    // thread closes for a human agent.
    'gate_max_nudges' => (int) env('INSTAGRAM_GATE_MAX_NUDGES', 4),

];
