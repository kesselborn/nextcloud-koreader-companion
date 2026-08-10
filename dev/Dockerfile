# Dev-only image: Nextcloud 34 plus the one binary ebook covers need.
#
# CBR is RAR, and PHP cannot read RAR without either ext-rar or an external
# binary for kiwilan/php-archive to shell out to. The official image ships
# neither, so CBR covers are impossible there. CBZ needs nothing extra --
# ZipArchive handles it -- and EPUB likewise.
#
# Deliberately NOT installed: ghostscript.
#
# PDF covers cannot work on Nextcloud 34.0.2 no matter what we install. That
# release hard-codes OC\Preview\IMagickSupport::hasExtension() and
# supportsFormat() to return false, which disables every ImageMagick-backed
# preview provider including OC\Preview\PDF. It is a security measure, not a bug:
# Nextcloud selected ImageMagick by file extension, so an attacker could route
# arbitrary content into it, and ImageMagick has a long CVE history.
# See https://github.com/nextcloud/server/issues/62802
#
# We deliberately do not route around it by shipping our own Imagick-based PDF
# provider -- that would reintroduce exactly the exposure upstream just closed,
# in an app whose whole job is ingesting files other people uploaded. PDFs get
# Nextcloud's generic file icon until upstream re-enables the providers (likely
# relocated into a separate app, see nextcloud/server#1041).
FROM nextcloud:34.0.2-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unrar-free; \
    rm -rf /var/lib/apt/lists/*
