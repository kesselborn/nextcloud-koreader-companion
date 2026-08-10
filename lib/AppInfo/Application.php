<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\AppInfo;

use OCA\KoreaderCompanion\Listener\FileCreationListener;
use OCA\KoreaderCompanion\Listener\FileDeleteListener;
use OCA\KoreaderCompanion\Preview\ComicCoverProvider;
use OCA\KoreaderCompanion\Preview\EpubCoverProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

class Application extends App implements IBootstrap {

    public const APP_ID = 'koreader_companion';

    public function __construct() {
        parent::__construct(self::APP_ID);

        // Required, despite appearances. Nextcloud's app autoloader resolves this
        // app's own OCA\KoreaderCompanion\* classes, but not third-party PSR-4
        // namespaces from composer -- so without this, Kiwilan\Archive\Archive is
        // missing at runtime and EPUB/PDF metadata extraction silently degrades to
        // filename parsing ("Class \"Kiwilan\Archive\Archive\" not found", caught
        // and logged by PdfMetadataExtractor). Removing this looks like dead code
        // and is not.
        $composerAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($composerAutoload)) {
            require_once $composerAutoload;
        }
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(NodeCreatedEvent::class, FileCreationListener::class);
        $context->registerEventListener(NodeWrittenEvent::class, FileCreationListener::class);
        $context->registerEventListener(NodeDeletedEvent::class, FileDeleteListener::class);

        // Covers go through Nextcloud's preview system, so they are cached in
        // preview storage, reachable at /core/preview with ordinary session auth,
        // and visible in the Files app too.
        //
        // No PDF provider here, on purpose. Core ships OC\Preview\PDF, but
        // Nextcloud 34.0.2 hard-codes IMagickSupport::hasExtension() and
        // supportsFormat() to false, disabling every ImageMagick-backed provider
        // -- a security measure, since Nextcloud picked ImageMagick by file
        // extension and ImageMagick has a long CVE history
        // (nextcloud/server#62802). Adding enabledPreviewProviders and
        // ghostscript changes nothing while that holds; verified on 34.0.2.
        //
        // We could render PDFs with Imagick ourselves, and deliberately do not:
        // that reintroduces the exposure upstream just closed, in an app built
        // around files other people uploaded. PDFs fall back to Nextcloud's
        // generic icon until upstream re-enables these providers.
        $context->registerPreviewProvider(EpubCoverProvider::class, '/application\/epub\+zip/');
        $context->registerPreviewProvider(ComicCoverProvider::class, '/application\/comicbook\+(rar|zip)/');
    }

    public function boot(IBootContext $context): void {
    }
}
