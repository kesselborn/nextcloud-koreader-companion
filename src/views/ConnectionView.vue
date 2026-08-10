<template>
	<div class="connection">
		<NcSettingsSection :name="title" :description="description">
			<div class="connection__field">
				<NcTextField
					:model-value="url"
					:label="t('koreader_companion', 'Server URL')"
					:readonly="true" />
				<NcButton :aria-label="t('koreader_companion', 'Copy URL')" @click="copy(url)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
				</NcButton>
			</div>

			<div class="connection__field">
				<NcTextField
					:model-value="info.username || ''"
					:label="t('koreader_companion', 'Username')"
					:readonly="true" />
				<NcButton :aria-label="t('koreader_companion', 'Copy username')" @click="copy(info.username)">
					<template #icon>
						<ContentCopy :size="20" />
					</template>
				</NcButton>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			v-if="kind === 'sync'"
			:name="t('koreader_companion', 'Sync password')"
			:description="t('koreader_companion', 'A separate password just for KOReader. It is not your Nextcloud password.')">
			<div class="connection__field">
				<NcPasswordField
					v-model="password"
					:label="t('koreader_companion', 'New sync password')"
					autocomplete="new-password" />
				<NcButton
					type="primary"
					:disabled="password.length < 4 || saving"
					@click="savePassword">
					{{ hasPassword
						? t('koreader_companion', 'Change')
						: t('koreader_companion', 'Set') }}
				</NcButton>
			</div>
			<p class="connection__hint">
				{{ hasPassword
					? t('koreader_companion', 'A sync password is set.')
					: t('koreader_companion', 'No sync password set yet — KOReader cannot sync until you set one.') }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection v-else :name="t('koreader_companion', 'Password')">
			<p class="connection__hint">
				{{ t('koreader_companion', 'Use your Nextcloud password, or an app password if you have two-factor authentication enabled.') }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection :name="t('koreader_companion', 'How to connect')">
			<ol class="connection__steps">
				<li v-for="(step, index) in steps" :key="index">{{ step }}</li>
			</ol>
		</NcSettingsSection>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'

import { setSyncPassword } from '../api.js'

export default {
	name: 'ConnectionView',

	components: {
		ContentCopy,
		NcButton,
		NcPasswordField,
		NcSettingsSection,
		NcTextField,
	},

	props: {
		kind: {
			type: String,
			required: true,
		},
		info: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			password: '',
			saving: false,
			hasPassword: Boolean(this.info.has_koreader_password),
		}
	},

	computed: {
		isSync() {
			return this.kind === 'sync'
		},
		title() {
			return this.isSync
				? t('koreader_companion', 'KOReader sync')
				: t('koreader_companion', 'OPDS access')
		},
		description() {
			return this.isSync
				? t('koreader_companion', 'Point KOReader’s progress sync at this server to keep your reading position across devices.')
				: t('koreader_companion', 'Add this address to any OPDS reader to browse and download your library.')
		},
		url() {
			return this.isSync
				? (this.info.koreader_sync_url || '')
				: (this.info.opds_url || '')
		},
		steps() {
			if (this.isSync) {
				return [
					t('koreader_companion', 'Set a sync password above.'),
					t('koreader_companion', 'In KOReader, open Tools → Progress sync → Custom sync server and enter the URL above.'),
					t('koreader_companion', 'Log in with your Nextcloud username and the sync password.'),
					t('koreader_companion', 'Set document matching to binary checksum, not filename — filenames differ between devices.'),
				]
			}
			return [
				t('koreader_companion', 'Open your OPDS reader and add a new catalog.'),
				t('koreader_companion', 'Paste the URL above and enter your Nextcloud username and password.'),
				t('koreader_companion', 'Browse by author, series, genre, format or language, and download straight to the device.'),
			]
		},
	},

	methods: {
		async copy(value) {
			if (!value) {
				return
			}
			try {
				await navigator.clipboard.writeText(value)
				showSuccess(t('koreader_companion', 'Copied'))
			} catch (error) {
				// Clipboard access needs a secure context; plain http localhost
				// counts, but an http LAN address does not.
				showError(t('koreader_companion', 'Could not copy — select the text and copy manually'))
			}
		},

		async savePassword() {
			this.saving = true
			try {
				await setSyncPassword(this.password)
				this.hasPassword = true
				this.password = ''
				showSuccess(t('koreader_companion', 'Sync password saved'))
			} catch (error) {
				showError(t('koreader_companion', 'Could not save the sync password'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.connection {
	&__field {
		display: flex;
		align-items: end;
		gap: calc(var(--default-grid-baseline) * 2);
		max-width: 560px;
		margin-block-end: calc(var(--default-grid-baseline) * 3);
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		max-width: 60ch;
	}

	&__steps {
		margin: 0;
		padding-inline-start: 1.4em;
		max-width: 68ch;

		li {
			margin-block-end: calc(var(--default-grid-baseline) * 2);
		}
	}
}
</style>
