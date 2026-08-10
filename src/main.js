import { createApp } from 'vue'
import { t, n } from '@nextcloud/l10n'

import App from './App.vue'

const app = createApp(App)

// Nextcloud convention: expose the translation helpers as globals so templates
// can call t('koreader_companion', '...') without importing them everywhere.
app.config.globalProperties.t = t
app.config.globalProperties.n = n

app.mount('#koreader-companion')
