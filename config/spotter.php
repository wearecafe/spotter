<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo gym
    |--------------------------------------------------------------------------
    |
    | Turns on /demo: one-click sign-in to North Shore Strength, a fictional
    | gym with an owner, a coach and clients, rebuilt every night by the
    | scheduler. Demo accounts can't sign in with a password or change their
    | account settings, never send email, and use rule-based inbox drafts
    | for anything visitors do (AI drafts are made once, at rebuild time).
    | Safe alongside real teams.
    |
    */

    'demo' => (bool) env('SPOTTER_DEMO', false),

];
