# Changelog

All notable changes to `padosoft/askmydocs-connector-google-drive` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v1.0.0 — Initial release (2026-05-12)

Initial extraction from the AskMyDocs v4.5 inline connector framework into a standalone, reusable Laravel package.

### Added

- `GoogleDriveConnector` — full `ConnectorInterface` implementation (`key`, `displayName`, `iconUrl`, `oauthScopes`, `initiateOAuth`, `handleOAuthCallback`, `refreshTokenIfExpired`, `syncFull`, `syncIncremental`, `disconnect`, `health`).
- Multi-mime ingestion: `text/markdown`, `text/plain`, `application/pdf`, `application/vnd.google-apps.document` (the Google Doc is exported as markdown via `files.export`). Google Doc emits the synthetic vendor MIME `application/vnd.google-apps.document` so host-side chunkers can route on it; other types pass their native MIME through.
- Delta-cursor incremental sync via `changes.list` + `pageToken` persisted in `connector_credentials.extra_json.changes_page_token`. `newStartPageToken` advances the cursor for the next run.
- `change.removed=true` events route through `ConnectorIngestionContract::softDeleteByRemoteId('drive_file_id', ...)` so trashed Drive files disappear from RAG.
- `refreshTokenIfExpired()` — minimal token-refresh implementation that uses the stored refresh token to mint a fresh access token, persists the rotation, and emits a `token_refreshed` audit row.
- Best-effort `disconnect()` — POSTs to Google's revoke endpoint but always clears local credentials even when the network call fails.
- `GoogleDriveServiceProvider` — merges this package's `config/google-drive.php` under `connectors.providers.google-drive`; publishes the config + brand SVG under `connector-google-drive-config` and `connector-google-drive-assets` tags.
- `composer.json::extra.askmydocs.connectors` — auto-registers the connector with the base package's registry on `composer require`. Zero edits to host app config required.
- `config/google-drive.php` — env-driven provider settings (client_id, client_secret, redirect_uri, api_base).
- `public/icons/google-drive.svg` — brand asset.
- 12 PHPUnit Feature tests covering OAuth state-token round-trip, OAuth code exchange, token refresh, sync (full + incremental), removal events driving soft delete via the IoC contract, health probe, PDF passthrough, disconnect with best-effort revoke.
- Opt-in live test (`tests/Live/GoogleDriveLiveTest.php`) that hits `www.googleapis.com/drive/v3` when `CONNECTOR_GOOGLE_DRIVE_LIVE=1`.
- CI matrix: PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

### Decisions

- **Standalone-agnostic** — this package never imports a host class. Every host-side concern (job dispatch, KB disk writes, PII redaction, audit emission, soft-delete by remote-id) routes through `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract` v1.1.0+. The host binds its own implementation in a service provider.
- **Folder filter + shared-drive support deferred to v1.1** — current scope is the authorising user's "My Drive". A folder picker UI is on the AskMyDocs admin SPA roadmap; shared-drive support waits on a customer ask.
- **Sheets / Slides / non-`google-apps.document` Office formats deferred** — the v1.0 MIME filter intentionally excludes them. The synthetic MIMEs are reserved in the base package's `VendorMimeSelector` so the v1.1 routing extension can land without breaking existing ingestions.
- **Folder paths surface as IDs only** — Drive's `parents` field returns parent IDs without names. Resolving them to human-readable paths requires additional API roundtrips and is left to a future enrichment pass; the IDs are usable as-is by chunkers / rerankers that want to correlate co-located docs.
