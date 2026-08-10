<?php

declare(strict_types=1);

namespace OCA\KoreaderCompanion\AppInfo;

use OCA\KoreaderCompanion\Listener\FileCreationListener;
use OCA\KoreaderCompanion\Listener\FileDeleteListener;
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
    }

    public function boot(IBootContext $context): void {
    }
}
