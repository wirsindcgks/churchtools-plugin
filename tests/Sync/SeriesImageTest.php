<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests\Sync;

use ChurchToolsPlugin\Db\EventRepository;
use ChurchToolsPlugin\Sync\ImageImportFailures;
use ChurchToolsPlugin\Sync\SyncEngine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

/**
 * Regression coverage for the "recurring series loses its image" bug found on
 * 2026-08-18 against live data.
 *
 * A ChurchTools series gets one row per occurrence, and every sync INSERTs the
 * occurrences that have newly moved into the horizon. upsert() deliberately does
 * not write attachment_id (it must not clobber the imported one on re-upsert),
 * so those new rows start out NULL. syncSeriesImage() then found the image
 * unchanged and returned early — leaving exactly those rows at NULL forever,
 * because nothing ever revisits a series whose image didn't change.
 *
 * The frontend falls back to the raw ChurchTools image_url for such rows, so the
 * symptom was the plugin hotlinking images off church.tools — precisely what
 * importing them into the media library exists to prevent (see readme.txt's
 * Datenschutz section). Observed live: 13 of 14 occurrences of one weekly
 * "Gottesdienst" series.
 */
final class SeriesImageTest extends TestCase
{
    protected function setUp(): void
    {
        ctp_test_reset_post_meta();
        ctp_test_reset_deleted_attachments();
        ctp_test_reset_media();
    }

    public function testUnchangedImageStillStampsOccurrencesAddedSinceTheImport(): void
    {
        ctp_test_set_post_meta(53, '_ctp_source_image_url', 'https://musterkirche.church.tools/?q=public/filedownload&id=7281');

        $repository = $this->createMock(EventRepository::class);
        $repository->method('getSeriesAttachmentId')->willReturn(53);

        // The actual assertion: the series is re-stamped rather than skipped, so
        // occurrence rows inserted after the original import stop being NULL.
        $repository->expects($this->once())
            ->method('setSeriesAttachment')
            ->with(5687, 53);

        $this->syncSeriesImage($repository, 5687, 'https://musterkirche.church.tools/?q=public/filedownload&id=7281');
    }

    /**
     * The counterpart: no image on the ChurchTools side means the attachment is
     * deleted and the column cleared — unchanged behavior, asserted here so the
     * re-stamping above can't be "fixed" by making every path stamp something.
     */
    public function testMissingRemoteImageClearsTheSeriesAttachment(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->method('getSeriesAttachmentId')->willReturn(53);
        $repository->expects($this->once())
            ->method('setSeriesAttachment')
            ->with(5687, null);

        $this->syncSeriesImage($repository, 5687, '');

        $this->assertSame([53], ctp_test_deleted_attachments());
    }

    public function testSeriesWithoutAnyImageIsLeftAlone(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->method('getSeriesAttachmentId')->willReturn(null);
        $repository->expects($this->never())->method('setSeriesAttachment');

        $this->syncSeriesImage($repository, 5687, '');
    }

    /**
     * Der Befund vom 2026-09-15: ChurchTools beantwortete den Bilddownload mit
     * 401, der Import gab still null zurueck, und die Serie blieb ohne Bild,
     * ohne dass es irgendwo stand. Der Lauf geht weiter - aber der Grund
     * landet samt Status im Sammler.
     */
    public function testAFailedDownloadIsRecordedWithItsHttpStatus(): void
    {
        $url = 'https://musterkirche.church.tools/?q=public/filedownload&id=7281';
        ctp_test_queue_download(new WP_Error('http_404', 'Unauthorized', ['code' => 401, 'body' => '{"message":"Die Berechtigung appointment_image ist notwendig"}']));

        $repository = $this->createMock(EventRepository::class);
        $repository->method('getSeriesAttachmentId')->willReturn(null);
        $repository->expects($this->never())->method('setSeriesAttachment');

        $failures = new ImageImportFailures();
        $this->syncSeriesImage($repository, 5687, $url, $failures);

        $this->assertSame([$url], ctp_test_download_calls());
        $this->assertSame(['HTTP 401 Unauthorized' => 1], $failures->reasons());
    }

    /** Eine Anmeldeseite mit Status 200 ist auch ein gescheiterter Import. */
    public function testADownloadThatIsNoImageIsRecorded(): void
    {
        ctp_test_queue_download('<!DOCTYPE html><title>Anmelden</title>');

        $failures = new ImageImportFailures();

        $this->assertNull(SyncEngine::importImage('https://musterkirche.church.tools/images/7281/abc', '_ctp_source_image_url', 'churchtools-event-', $failures));
        $this->assertSame(['Die Antwort ist kein Bild' => 1], $failures->reasons());
    }

    public function testAFailedSideloadIsRecorded(): void
    {
        ctp_test_queue_download(self::png());
        ctp_test_queue_sideload(new WP_Error('upload_error', 'Sorry, you are not allowed to upload this file type.'));

        $failures = new ImageImportFailures();

        $this->assertNull(SyncEngine::importImage('https://musterkirche.church.tools/images/7281/abc', '_ctp_source_image_url', 'churchtools-event-', $failures));
        $this->assertSame(['Sorry, you are not allowed to upload this file type.' => 1], $failures->reasons());
    }

    /** Das Gegenstueck: Ein gelungener Import meldet nichts. */
    public function testASuccessfulImportRecordsNothing(): void
    {
        $url = 'https://musterkirche.church.tools/images/7281/abc';
        ctp_test_queue_download(self::png());
        ctp_test_queue_sideload(77);

        $repository = $this->createMock(EventRepository::class);
        $repository->method('getSeriesAttachmentId')->willReturn(null);
        $repository->expects($this->once())->method('setSeriesAttachment')->with(5687, 77);

        $failures = new ImageImportFailures();
        $this->syncSeriesImage($repository, 5687, $url, $failures);

        $this->assertSame(0, $failures->count());
        $this->assertSame($url, get_post_meta(77, '_ctp_source_image_url', true));
    }

    /** Ein Pixel, genug fuer getimagesize(). */
    private static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
    }

    private function syncSeriesImage(EventRepository $repository, int $ctEventId, string $imageUrl, ?ImageImportFailures $failures = null): void
    {
        $method = new ReflectionMethod(SyncEngine::class, 'syncSeriesImage');
        $method->invoke(null, $repository, $ctEventId, $imageUrl, $failures);
    }
}
