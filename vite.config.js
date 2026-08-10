import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'path'

// Emits to js/, which is what Util::addScript('koreader_companion', 'koreader-main')
// resolves to. The legacy hand-written js/koreader.js and js/upload.js are gone;
// everything now comes from src/.
export default createAppConfig({
	main: resolve(join('src', 'main.js')),
}, {
	// One bundle rather than per-component chunks: simpler to load through
	// addScript, and this app is small enough that splitting buys nothing.
	inlineCSS: false,
	createEmptyCSSEntryPoints: true,
	extractLicenseInformation: true,
	thirdPartyLicense: false,
})
