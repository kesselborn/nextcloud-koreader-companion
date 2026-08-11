import { createApp } from 'vue'
import { t, n } from '@nextcloud/l10n'

import App from './App.vue'

const app = createApp(App)

// Nextcloud convention: expose the translation helpers as globals so component
// templates can call them without importing them in every file.
app.config.globalProperties.t = t
app.config.globalProperties.n = n

app.mount('#koreader-companion')
