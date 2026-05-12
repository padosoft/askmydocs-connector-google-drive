<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Google Drive connector configuration
|--------------------------------------------------------------------------
|
| Provider settings for `padosoft/askmydocs-connector-google-drive`.
|
| The base package merges this block under
| `config('connectors.providers.google-drive')`, so concrete connector
| code reads its config via the standard
| `config('connectors.providers.google-drive.<key>')` path.
|
| All knobs accept env-var overrides — set them in your host app's
| `.env` (see the package README §Credential setup).
|
*/

return [
    'client_id' => env('CONNECTOR_GOOGLE_DRIVE_CLIENT_ID'),
    'client_secret' => env('CONNECTOR_GOOGLE_DRIVE_CLIENT_SECRET'),
    'redirect_uri' => env(
        'CONNECTOR_GOOGLE_DRIVE_REDIRECT_URI',
        env('APP_URL', 'http://localhost').'/api/admin/connectors/google-drive/oauth/callback'
    ),
    'oauth_authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'oauth_token_url' => 'https://oauth2.googleapis.com/token',
    'oauth_revoke_url' => 'https://oauth2.googleapis.com/revoke',
    'api_base' => env('CONNECTOR_GOOGLE_DRIVE_API_BASE', 'https://www.googleapis.com/drive/v3'),
];
