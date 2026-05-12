<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGoogleDrive\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Auth\OAuthCredentialVault;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorBase\Support\Metadata\VendorMimeSelector;
use Padosoft\AskMyDocsConnectorGoogleDrive\GoogleDriveConnector;
use Padosoft\AskMyDocsConnectorGoogleDrive\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorGoogleDrive\Tests\TestCase;

/**
 * Feature tests for {@see GoogleDriveConnector} against `Http::fake()`
 * and a spy implementation of {@see ConnectorIngestionContract}.
 */
final class GoogleDriveConnectorTest extends TestCase
{
    private SpyIngestionContract $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new SpyIngestionContract();
        $this->app->instance(ConnectorIngestionContract::class, $this->spy);
        Storage::fake('local');

        config()->set('connectors.providers.google-drive.client_id', 'cid');
        config()->set('connectors.providers.google-drive.client_secret', 'csec');
        config()->set('connectors.providers.google-drive.redirect_uri', 'http://localhost/cb');
    }

    private function connector(): GoogleDriveConnector
    {
        return $this->app->make(GoogleDriveConnector::class);
    }

    private function makeInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'google-drive',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function seedActiveCredential(
        int $installationId,
        string $access = 'AT-xyz',
        ?string $refresh = 'RT-xyz',
        array $extra = [],
        string $tenantId = 'default',
    ): void {
        ConnectorCredential::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installationId,
            'encrypted_access_token' => Crypt::encryptString($access),
            'encrypted_refresh_token' => $refresh !== null ? Crypt::encryptString($refresh) : null,
            'expires_at' => Carbon::now()->addHour(),
            'extra_json' => $extra === [] ? null : $extra,
        ]);
    }

    public function test_initiate_oauth_returns_google_auth_url_with_correct_scopes(): void
    {
        $installation = $this->makeInstallation();
        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertStringContainsString('drive.readonly', $query['scope']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertNotEmpty($query['state'] ?? '');
    }

    public function test_handle_oauth_callback_persists_credentials_and_emits_audit(): void
    {
        $installation = $this->makeInstallation();
        Cache::flush();
        $url = $this->connector()->initiateOAuth($installation->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $state = (string) ($query['state'] ?? '');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-at',
                'refresh_token' => 'fresh-rt',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            ], 200),
        ]);

        $request = Request::create('/cb', 'GET', ['code' => 'auth-code', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $request);

        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertSame('fresh-at', $vault->getAccessToken($installation->id));
        $this->assertSame('fresh-rt', $vault->getRefreshToken($installation->id));

        $events = array_column($this->spy->audits, 'eventType');
        $this->assertContains('installed', $events);
    }

    public function test_handle_oauth_callback_rejects_invalid_state_token(): void
    {
        $installation = $this->makeInstallation();
        $request = Request::create('/cb', 'GET', ['code' => 'c', 'state' => 'fabricated']);

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('state token');

        $this->connector()->handleOAuthCallback($installation->id, $request);
    }

    public function test_refresh_token_swap_persists_new_access_token(): void
    {
        $installation = $this->makeInstallation();
        // Seed an EXPIRED access token + a valid refresh.
        ConnectorCredential::create([
            'tenant_id' => 'default',
            'connector_installation_id' => $installation->id,
            'encrypted_access_token' => Crypt::encryptString('stale-at'),
            'encrypted_refresh_token' => Crypt::encryptString('RT-xyz'),
            'expires_at' => Carbon::now()->subMinute(),
            'extra_json' => null,
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'refreshed-at',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $token = $this->connector()->refreshTokenIfExpired($installation->id);
        $this->assertSame('refreshed-at', $token);

        $events = array_column($this->spy->audits, 'eventType');
        $this->assertContains('token_refreshed', $events);
    }

    public function test_health_returns_healthy_when_about_succeeds(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'www.googleapis.com/drive/v3/about*' => Http::response([
                'user' => ['emailAddress' => 'me@example.test'],
            ], 200),
        ]);

        $health = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $health->state);
    }

    public function test_health_returns_errored_when_no_credentials(): void
    {
        $installation = $this->makeInstallation();
        $health = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $health->state);
    }

    public function test_sync_full_throws_without_access_token(): void
    {
        $installation = $this->makeInstallation();
        $this->expectException(ConnectorAuthException::class);
        $this->connector()->syncFull($installation->id);
    }

    public function test_sync_full_dispatches_per_drive_file_via_contract(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'www.googleapis.com/drive/v3/files?*' => Http::response([
                'files' => [
                    [
                        'id' => 'file-md-1',
                        'name' => 'notes.md',
                        'mimeType' => 'text/markdown',
                        'modifiedTime' => '2026-05-01T10:00:00Z',
                    ],
                    [
                        'id' => 'file-gdoc-2',
                        'name' => 'Plan',
                        'mimeType' => 'application/vnd.google-apps.document',
                        'modifiedTime' => '2026-05-02T10:00:00Z',
                    ],
                ],
            ], 200),
            'www.googleapis.com/drive/v3/files/file-md-1?*' => Http::response('# Notes', 200, [
                'content-type' => 'text/markdown',
            ]),
            'www.googleapis.com/drive/v3/files/file-gdoc-2/export*' => Http::response('# Plan', 200, [
                'content-type' => 'text/markdown',
            ]),
            'www.googleapis.com/drive/v3/changes/startPageToken' => Http::response([
                'startPageToken' => 'spt-100',
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(2, $result->documentsAdded);
        $this->assertCount(2, $this->spy->dispatches);

        // Markdown file → native text/markdown MIME.
        $md = $this->spy->dispatches[0];
        $this->assertSame('notes.md', $md['title']);
        $this->assertSame('text/markdown', $md['mimeType']);
        $this->assertStringContainsString('file-md-1', $md['relativePath']);
        $this->assertSame('default', $md['tenantId']);

        // Google Doc → synthetic vendor MIME so a host's OfficeDocChunker
        // can route on it.
        $gdoc = $this->spy->dispatches[1];
        $this->assertSame('Plan', $gdoc['title']);
        $this->assertSame(VendorMimeSelector::MIME_DRIVE_GDOC, $gdoc['mimeType']);
        $this->assertSame('file-gdoc-2', $gdoc['metadata']['drive_file_id']);
        $this->assertArrayHasKey('converter_hints', $gdoc['metadata']);

        // Sync also stamps the changes cursor.
        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertSame('spt-100', $vault->getExtraKey($installation->id, 'changes_page_token'));
    }

    public function test_sync_incremental_with_no_cursor_falls_back_to_full(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'www.googleapis.com/drive/v3/files?*' => Http::response(['files' => []], 200),
            'www.googleapis.com/drive/v3/changes/startPageToken' => Http::response([
                'startPageToken' => 'spt-1',
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame(0, $result->documentsAdded);

        $modes = array_filter(array_map(static fn ($a) => $a['metadata']['mode'] ?? null, $this->spy->audits));
        $this->assertContains('full', $modes);
    }

    public function test_sync_incremental_removed_change_triggers_soft_delete_via_contract(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: ['changes_page_token' => 'cur-1']);
        $this->spy->remoteIdsThatMatch['file-deleted-1'] = 'default';

        Http::fake([
            'www.googleapis.com/drive/v3/changes?*' => Http::response([
                'changes' => [
                    [
                        'fileId' => 'file-deleted-1',
                        'removed' => true,
                    ],
                ],
                'newStartPageToken' => 'cur-2',
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, Carbon::now()->subDay());
        $this->assertSame(1, $result->documentsRemoved);
        $this->assertCount(1, $this->spy->deletions);
        $this->assertSame('drive_file_id', $this->spy->deletions[0]['metadata_key']);
        $this->assertSame('file-deleted-1', $this->spy->deletions[0]['remote_id']);

        // newStartPageToken is persisted for the next run.
        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertSame('cur-2', $vault->getExtraKey($installation->id, 'changes_page_token'));
    }

    public function test_disconnect_revokes_token_best_effort_and_clears_credentials(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $this->connector()->disconnect($installation->id);

        $vault = $this->app->make(OAuthCredentialVault::class);
        $this->assertNull($vault->getAccessToken($installation->id));

        $events = array_column($this->spy->audits, 'eventType');
        $this->assertContains('disconnected', $events);
    }

    public function test_pdf_file_passes_through_as_native_pdf_mime(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'www.googleapis.com/drive/v3/files?*' => Http::response([
                'files' => [[
                    'id' => 'file-pdf-1',
                    'name' => 'manual.pdf',
                    'mimeType' => 'application/pdf',
                    'modifiedTime' => '2026-05-01T10:00:00Z',
                ]],
            ], 200),
            'www.googleapis.com/drive/v3/files/file-pdf-1?*' => Http::response('%PDF-1.4 binary', 200, [
                'content-type' => 'application/pdf',
            ]),
            'www.googleapis.com/drive/v3/changes/startPageToken' => Http::response([
                'startPageToken' => 'spt-x',
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $dispatch = $this->spy->dispatches[0] ?? null;
        $this->assertNotNull($dispatch);
        $this->assertSame('application/pdf', $dispatch['mimeType']);
        $this->assertStringEndsWith('.pdf', $dispatch['relativePath']);
    }
}
