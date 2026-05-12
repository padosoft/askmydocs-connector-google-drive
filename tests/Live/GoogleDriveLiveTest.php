<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGoogleDrive\Tests\Live;

use Illuminate\Support\Facades\Http;
use Padosoft\AskMyDocsConnectorGoogleDrive\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Live test — hits www.googleapis.com when `CONNECTOR_GOOGLE_DRIVE_LIVE=1`
 * and a valid `CONNECTOR_GOOGLE_DRIVE_TOKEN` is present in the
 * environment.
 *
 * Operators run this manually to validate credentials and / or record
 * fresh response-shape fixtures. CI does NOT run this suite by default
 * (the gate env-var is unset on CI runners).
 *
 * See README.md §Credential setup → §Live testsuite for the step-by-
 * step setup. Mirrors the AskMyDocs RUNBOOK Google Drive section
 * verbatim.
 */
final class GoogleDriveLiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONNECTOR_GOOGLE_DRIVE_LIVE') !== '1') {
            $this->markTestSkipped('CONNECTOR_GOOGLE_DRIVE_LIVE not set to 1 — live suite disabled.');
        }

        $token = getenv('CONNECTOR_GOOGLE_DRIVE_TOKEN');
        if ($token === false || trim((string) $token) === '') {
            $this->markTestSkipped('Missing credential env var: CONNECTOR_GOOGLE_DRIVE_TOKEN');
        }
    }

    #[Test]
    public function lists_files_via_real_api(): void
    {
        $response = Http::withToken((string) getenv('CONNECTOR_GOOGLE_DRIVE_TOKEN'))
            ->timeout(10)
            ->get('https://www.googleapis.com/drive/v3/files', ['pageSize' => 1]);

        $this->assertTrue(
            $response->successful(),
            'Drive /files returned non-2xx: '.$response->status(),
        );
        $json = $response->json();
        $this->assertIsArray($json);
        $this->assertArrayHasKey('files', $json);
    }

    #[Test]
    public function fetches_about_user_via_real_api(): void
    {
        $response = Http::withToken((string) getenv('CONNECTOR_GOOGLE_DRIVE_TOKEN'))
            ->timeout(10)
            ->get('https://www.googleapis.com/drive/v3/about', ['fields' => 'user']);

        $this->assertTrue(
            $response->successful(),
            'Drive /about returned non-2xx: '.$response->status(),
        );
    }
}
