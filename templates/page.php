<?php
/**
 * Mount point only.
 *
 * Everything that used to live here -- the navigation shell, the 11-column book
 * table, the upload and metadata modals, ~545 lines of markup -- is now Vue
 * components under src/. The script is registered with Util::addScript() in
 * PageController::index(), and data arrives via IInitialState rather than being
 * interpolated into HTML.
 */
?>
<div id="koreader-companion"></div>
