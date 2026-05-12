<h1 align="center">askmydocs-connector-google-drive</h1>

<p align="center">
  <strong>Google Drive connector for AskMyDocs — OAuth2 + multi-mime sync + delta-cursor incremental.</strong><br/>
  Drop-in Laravel package. <code>composer require</code> it from any AskMyDocs install and the Drive connector appears in the admin UI on the next request.
</p>

<p align="center">
  <a href="https://github.com/padosoft/askmydocs-connector-google-drive/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-google-drive/tests.yml?branch=main&label=tests"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-google-drive"><img alt="Packagist version" src="https://img.shields.io/packagist/v/padosoft/askmydocs-connector-google-drive.svg?label=packagist"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-google-drive"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/padosoft/askmydocs-connector-google-drive.svg?label=downloads"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
</p>

---

## Table of contents

1. [Why this package](#why-this-package)
2. [Features](#features)
3. [AI vibe-coding pack included](#-ai-vibe-coding-pack-included)
4. [Architecture at a glance](#architecture-at-a-glance)
5. [Installation](#installation)
6. [Credential setup (junior-proof, step by step)](#credential-setup-junior-proof-step-by-step)
7. [Activation inside AskMyDocs](#activation-inside-askmydocs)
8. [What gets ingested](#what-gets-ingested)
9. [Sync semantics](#sync-semantics)
10. [Testing](#testing)
11. [Live testsuite](#live-testsuite)
12. [Troubleshooting](#troubleshooting)
13. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge compilation system. Out of the box it ingests markdown from disk, the chat UI, an HTTP API, and a Git-driven workflow — but most of the knowledge people actually want to query lives in Google Drive.

This package is the smallest possible surface for shipping that integration:

- A `GoogleDriveConnector` that implements `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`.
- Multi-mime full sync: markdown, plain text, PDF, and Google Docs (the latter exported as markdown via `files.export`).
- Delta-cursor incremental sync via `changes.list` + `newStartPageToken`.
- Token refresh built-in.
- Best-effort `oauth/revoke` on disconnect.
- A composer.json that auto-registers via `extra.askmydocs.connectors`. Zero edits to host app config required.

> **`composer require padosoft/askmydocs-connector-google-drive`. Done.**

## Features

- 🔌 **Zero-config installation** — composer-extra discovery auto-registers the connector at boot.
- 🔐 **OAuth2 with offline access + consent prompt** — full refresh-token round-trip so long-lived syncs survive `access_token` expiry without operator interaction.
- 🗂️ **Multi-mime** — `text/markdown`, `text/plain`, `application/pdf`, and Google Docs (`application/vnd.google-apps.document`, exported as markdown).
- ♻️ **Delta-cursor incremental** — `changes.list` with `pageToken` persisted in `connector_credentials.extra_json.changes_page_token`. Daily syncs cost one round-trip on quiet accounts.
- 🗑️ **Removal events drive real deletes** — `change.removed=true` → `softDeleteByRemoteId('drive_file_id', ...)` so trashed Drive files disappear from RAG.
- 🧠 **Source-aware metadata** — file id, mime, modified time, owner email, folder path (parent ids), revision id, permission ids — all routed under `metadata.converter_hints.google_drive` so chunkers and rerankers can read them.
- 🧩 **Synthetic vendor MIME for Office formats** — Google Docs / Sheets / Slides emit `application/vnd.google-apps.*` so host-side chunkers (OfficeDocChunker) can route on them. Plain markdown / pdf / text pass their native MIME through.
- 🚦 **Best-effort revoke** — `disconnect()` calls Google's revoke endpoint, but always clears local credentials even if the network call fails (operators must be able to disconnect locally).
- 🏢 **Per-tenant isolated** — every credential read and ingestion dispatch is scoped to the active `TenantContext`.
- 🧪 **Test-friendly** — `Http::fake()` feature tests for the connector; opt-in live test that hits `www.googleapis.com/drive/v3` when `CONNECTOR_GOOGLE_DRIVE_LIVE=1`.

## 🚀 AI vibe-coding pack included

This package was built with a vibe-coding pack of Claude Code skills and rules (`.claude/` directory in the parent AskMyDocs repo) that codify the architectural invariants — the IoC contract that keeps this package standalone-agnostic, the Drive API quirks the connector navigates, the multi-mime routing rules, the `changes.list` cursor lifecycle.

If you're using Claude Code to fork or extend this package, point the agent at the parent repo's `.claude/` pack and it stays inside the invariants automatically.

## Architecture at a glance

```
                ┌──────────────────────────┐
Composer        │ padosoft/askmydocs-      │
require ───────▶│ connector-google-drive   │
                │ (this package)           │
                └────────────┬─────────────┘
                             │
                             │ auto-registered via composer
                             │ extra.askmydocs.connectors
                             ▼
                ┌──────────────────────────────┐
                │ padosoft/askmydocs-connector-│
                │ base v1.1.0+                 │
                │ ConnectorRegistry            │
                └────────────┬─────────────────┘
                             │
                             │ resolves GoogleDriveConnector
                             ▼
                ┌──────────────────────────────┐
                │ GoogleDriveConnector::sync*  │
                │  • GET /files (mime filter)  │
                │  • files.export (gdoc → md)  │
                │  • files.get?alt=media       │
                │  • changes.list cursor       │
                │  • SourceAwareMetadata       │
                └────────────┬─────────────────┘
                             │
                             │ ConnectorIngestionContract
                             │ (IoC bridge — host implements)
                             ▼
                ┌──────────────────────────────┐
                │ Host app (AskMyDocs):        │
                │  • Storage::put → KB disk    │
                │  • IngestDocumentJob         │
                │  • kb_canonical_audit row    │
                │  • PII redactor at boundary  │
                └──────────────────────────────┘
```

The IoC bridge is the key design decision: this package never imports `App\Jobs\IngestDocumentJob`, `App\Models\KnowledgeDocument`, or any other host class. It dispatches every host-side concern through `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`. The host binds its own implementation in a service provider; this package stays standalone-agnostic so it can run inside AskMyDocs Community Edition, AskMyDocs Pro, or any third-party Laravel app that wants Drive-backed RAG.

## Installation

```bash
composer require padosoft/askmydocs-connector-google-drive
```

The package follows Laravel's auto-discovery convention so no manual provider registration is required. After install, run:

```bash
php artisan vendor:publish --tag=connector-google-drive-config   # optional — for env-var overrides
php artisan vendor:publish --tag=connector-google-drive-assets   # optional — copies google-drive.svg to public/connectors
```

The `connector-base` migrations ship in the parent package (`padosoft/askmydocs-connector-base`) and auto-load via its service provider; no extra `migrate` step is needed.

## Credential setup (junior-proof, step by step)

Google Drive uses OAuth2 with Google Cloud's consent platform. You need a client_id, client_secret, and a registered redirect URI. Follow EVERY step — skipping a scope or forgetting to add a test user is the single most common cause of `403 access_denied` later.

### 1. Create the Google Cloud project

1. Open <https://console.cloud.google.com/> in your browser. Sign in with your Google account.
2. In the top header next to the Google Cloud logo, click the **project selector dropdown** (it says either "Select a project" or the name of your last project).
3. In the dialog that opens, click **"NEW PROJECT"** (top-right, blue button).
4. Set **Project name**: `askmydocs-prod` (or any label that makes sense for your tenant). Leave **Organization** as "No organization" if your account isn't part of a Google Workspace org. Click **"CREATE"**. Wait ~20 seconds for the project to spin up.
5. After creation, the top header should auto-switch to your new project. If it doesn't, click the project selector and pick it.

### 2. Enable the Drive API

1. In the **left sidebar** (click the ☰ hamburger menu at the top-left to reveal it), click **"APIs & Services"** → **"Library"**. The URL becomes <https://console.cloud.google.com/apis/library>.
2. In the search box, type `Google Drive API` and press Enter.
3. Click the **"Google Drive API"** result card.
4. Click the blue **"ENABLE"** button. Wait ~15 seconds. The page should update to show "API enabled".

### 3. Configure the OAuth consent screen

1. Left sidebar → **"APIs & Services"** → **"OAuth consent screen"**.
2. **User Type**: select **External** (Internal is only available with a Workspace org). Click **"CREATE"**.
3. **App information**:
    - App name: `AskMyDocs`
    - User support email: your email
    - Developer contact email: your email
    - Leave everything else blank. Click **"SAVE AND CONTINUE"**.
4. **Scopes**: click **"ADD OR REMOVE SCOPES"**. Search and tick:
    - `https://www.googleapis.com/auth/drive.readonly` — read-only access to file metadata + content
    - `https://www.googleapis.com/auth/drive.metadata.readonly` — read-only metadata-only fallback
    Click **"UPDATE"** at the bottom of the dialog, then **"SAVE AND CONTINUE"**.
5. **Test users** (only while your app is in "Testing" mode): click **"+ ADD USERS"**. Add the email of every user who'll install the connector. Click **"ADD"**, then **"SAVE AND CONTINUE"**.
6. Review the summary, click **"BACK TO DASHBOARD"**.

### 4. Create the OAuth client credentials

1. Left sidebar → **"APIs & Services"** → **"Credentials"**.
2. Click **"+ CREATE CREDENTIALS"** (top of the page) → **"OAuth client ID"**.
3. **Application type**: **Web application**.
4. **Name**: `AskMyDocs Web Client`.
5. **Authorized redirect URIs**: click **"+ ADD URI"** and paste your AskMyDocs callback URL (e.g. `https://your-app.example.com/api/admin/connectors/google-drive/oauth/callback`). Add as many as you have environments (staging, prod). Trailing slashes matter — make sure they match `CONNECTOR_GOOGLE_DRIVE_REDIRECT_URI` exactly.
6. Click **"CREATE"**. A modal opens with the **Client ID** and **Client secret**. Copy both.

### 5. Write credentials to `.env`

In your AskMyDocs host app's `.env`:

```dotenv
CONNECTOR_GOOGLE_DRIVE_CLIENT_ID=<your-client-id>.apps.googleusercontent.com
CONNECTOR_GOOGLE_DRIVE_CLIENT_SECRET=<your-client-secret>
CONNECTOR_GOOGLE_DRIVE_REDIRECT_URI=https://your-app.example.com/api/admin/connectors/google-drive/oauth/callback
# Optional override (only set if you need to point at a non-default Drive endpoint, e.g. a mock).
# CONNECTOR_GOOGLE_DRIVE_API_BASE=https://www.googleapis.com/drive/v3
```

### 6. Verify

The full OAuth round-trip is exercised once you click **Install** in the admin UI (next section). To validate credentials beforehand, run the live test with a manually-minted access token (see [Live testsuite](#live-testsuite)).

### 7. Common errors

- `403 access_denied — User has not granted the application "..." access to scope` — Test user not added (step 3.5) OR a scope is missing (step 3.4).
- `redirect_uri_mismatch` — The redirect URI in `.env` doesn't byte-for-byte match what's in the Cloud Console (step 4.5). Trailing slashes and `http` vs `https` count.
- `invalid_grant — Token has been revoked` — Refresh token was revoked at the Google end. The user must reinstall.

## Activation inside AskMyDocs

After `composer require` + the env vars above:

1. Run the host app's admin UI.
2. Navigate to **Settings → Connectors**.
3. The **Google Drive** card appears with an **Install** button.
4. Click **Install** → browser redirects to Google → operator picks an account + grants the requested scopes → returns to the admin UI → status flips to `active`.
5. The first full sync fires within the cadence window (default 15 minutes; configurable via `CONNECTOR_DEFAULT_SYNC_CADENCE_MINUTES`). To trigger immediately, click **Sync now**.

## What gets ingested

For every Drive file matching the multi-mime filter:

| Drive MIME | Output extension | Persisted MIME | Effective MIME (for host chunker dispatch) |
|---|---|---|---|
| `text/markdown` | `.md` | `text/markdown` | `text/markdown` |
| `text/plain` | `.txt` | `text/plain` | `text/plain` |
| `application/pdf` | `.pdf` | `application/pdf` | `application/pdf` |
| `application/vnd.google-apps.document` | `.md` (exported) | `text/markdown` | `application/vnd.google-apps.document` |

The Google Doc rows use the synthetic vendor MIME so a host's Office-document-aware chunker routes on them; other types pass through.

Metadata captured under `metadata.converter_hints.google_drive`:

- `file_id`, `mime_type`, `native_mime`
- `modified_time`
- `owner` (first owner email) + `owners` (all owner emails)
- `folder_path` (parent IDs joined by `/`)
- `revision_id`, `shared_with` (permission IDs)

Plus the standard `_derived` reranker block (`search_tags`, `status_active`, `recency_bucket`, `owner`).

## Sync semantics

- **Full sync** — `GET /files` filtered to the four MIMEs above, paginated via `nextPageToken`. Every hit downloads + persists + dispatches one ingestion. After the full walk completes, the connector calls `/changes/startPageToken` and persists the result so the next incremental run has a cursor anchor.
- **Incremental sync** — when a `changes_page_token` exists in `extra_json`, the connector walks `GET /changes` with that token. Each change is either:
  - `removed: true` → routed to `ConnectorIngestionContract::softDeleteByRemoteId('drive_file_id', ...)` so the host's deletion service finds and soft-deletes the matching `knowledge_documents` row.
  - File-changed → re-downloads + re-ingests (idempotent on the host side via the `SHA-256` document hash).
  When the API returns `newStartPageToken` (we've caught up to "now"), the connector persists it for the next run.
- **Token refresh** — `refreshTokenIfExpired()` checks if the access token is still valid; if not, it uses the stored refresh token to mint a fresh access token, persists the rotation, and emits a `token_refreshed` audit row.
- **Disconnect** — POSTs to Google's `oauth2/revoke` endpoint best-effort (any network failure is logged but does NOT block the local credential clear), then clears the local credential row. Operators can always disconnect locally even when the Google revoke endpoint is unreachable.

## Testing

```bash
composer install
vendor/phpunit/phpunit/phpunit
```

The suite has three flavours:

| Suite     | What it covers                                                                                  | Network |
|-----------|-------------------------------------------------------------------------------------------------|---------|
| Unit      | (Currently no unit suite — the package's pure-PHP surface is small enough that everything       | None    |
|           | is exercised by Feature tests.)                                                                  |         |
| Feature   | `GoogleDriveConnector` against `Http::fake()`. ~12 scenarios incl. OAuth round-trip, token       | None    |
|           | refresh, full sync, incremental sync, soft-delete-on-removal, PDF passthrough, health.           |         |
| Live      | Opt-in — actually hits `www.googleapis.com/drive/v3`. Skipped unless                              | Real    |
|           | `CONNECTOR_GOOGLE_DRIVE_LIVE=1`.                                                                 |         |

CI runs Default (Unit + Feature) against PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

## Live testsuite

The live suite is **opt-in** so CI never pays for real API calls. To run it:

```bash
export CONNECTOR_GOOGLE_DRIVE_LIVE=1
export CONNECTOR_GOOGLE_DRIVE_TOKEN=<ya29.your-access-token>
vendor/phpunit/phpunit/phpunit --testsuite=Live
```

Mint a short-lived access token via the OAuth Playground (<https://developers.google.com/oauthplayground>):

1. Open the Playground, click the gear icon → tick **"Use your own OAuth credentials"** and paste your Client ID + Client secret from step 4.6 above.
2. In the left column, scroll to **"Drive API v3"** → tick `https://www.googleapis.com/auth/drive.readonly`.
3. Click **"Authorize APIs"** → sign in with a test-user account → approve.
4. On **Step 2**, click **"Exchange authorization code for tokens"**.
5. Copy the **Access token** (expires in ~1 hour).

The live test calls `/files?pageSize=1` and `/about?fields=user` once each to validate credentials and the response-shape contract.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `403 access_denied` during OAuth | Test user not added, or app still in Testing mode and not the user signed in | Add the user under OAuth consent screen → Test users; OR move the app to Production once you've passed Google's verification process |
| `redirect_uri_mismatch` | Trailing slash / scheme mismatch | Compare `.env` `CONNECTOR_GOOGLE_DRIVE_REDIRECT_URI` byte-for-byte with the Cloud Console entry |
| Files don't appear after install | The multi-mime filter excludes the file's MIME | Open the file in Drive — if it's a non-supported format (e.g. Sheets, Slides, custom binary), the v1.0 connector doesn't ingest it. Sheets / Slides support is on the roadmap. |
| Deleted files keep appearing in RAG | The host's `ConnectorIngestionContract::softDeleteByRemoteId()` implementation isn't actually finding documents by `drive_file_id` metadata | Verify the host stamps `drive_file_id` in `knowledge_documents.metadata` at ingest time + has a tenant-scoped JSON lookup query — see the AskMyDocs host bridge for the reference impl. |
| `invalid_grant — Token has been revoked` | User revoked the integration at the Google end (Account → Security → Third-party access) | Reinstall the connector |

## License

Apache-2.0 — see [LICENSE](LICENSE).

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.
