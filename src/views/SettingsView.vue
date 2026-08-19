<template>
	<div class="settings">
		<NcSettingsSection
			:name="t('koreader_companion', 'eBooks folder')"
			:description="t('koreader_companion', 'Where your library lives. Changing it clears the index and re-scans; reading progress is kept.')">
			<div class="settings__row">
				<!-- :model-value, not :value -- v9 renamed the prop to modelValue, and a
				     :value binding is silently ignored, leaving the field blank.
				     readonly rather than disabled so the current folder stays legible. -->
				<NcTextField
					:model-value="folder"
					:label="t('koreader_companion', 'Folder')"
					:readonly="true"
					class="settings__folder" />
				<NcButton @click="pickFolder">
					<template #icon>
						<FolderOutline :size="20" />
					</template>
					{{ t('koreader_companion', 'Browse') }}
				</NcButton>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			:name="t('koreader_companion', 'Filenames')"
			:description="t('koreader_companion', 'Rename files from their metadata, as “Author - Title (Year).ext”.')">
			<NcCheckboxRadioSwitch
				:model-value="autoRename"
				type="switch"
				@update:model-value="onAutoRename">
				{{ t('koreader_companion', 'Rename new files automatically') }}
			</NcCheckboxRadioSwitch>

			<NcButton
				:disabled="renaming"
				class="settings__rename"
				@click="confirmRename">
				<template #icon>
					<NcLoadingIcon v-if="renaming" :size="20" />
					<Pencil v-else :size="20" />
				</template>
				{{ t('koreader_companion', 'Rename all existing books now') }}
			</NcButton>

			<NcProgressBar v-if="renaming" :value="renameProgress" size="medium" class="settings__progress" />
		</NcSettingsSection>
	</div>
</template>

<script>
import { FilePickerClosed, getFilePickerBuilder, showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

import {
	batchRename,
	getBatchRenameProgress,
	getSettings,
	setAutoRename,
	setFolder,
} from '../api.js'

export default {
	name: 'SettingsView',

	components: {
		FolderOutline,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcProgressBar,
		NcSettingsSection,
		NcTextField,
		Pencil,
	},

	data() {
		return {
			folder: 'eBooks',
			autoRename: false,
			renaming: false,
			renameProgress: 0,
			poll: null,
		}
	},

	async mounted() {
		try {
			const settings = await getSettings()
			this.folder = settings.folder || 'eBooks'
			this.autoRename = settings.auto_rename === 'yes'
		} catch (error) {
			showError(t('koreader_companion', 'Could not load settings'))
		}
	},

	beforeUnmount() {
		clearInterval(this.poll)
	},

	methods: {
		async pickFolder() {
			// Replaces OC.dialogs.filepicker, deprecated since 27.1. The
			// confirm button is load-bearing: dialogs renders no default
			// action, so without addButton the picker can only be cancelled
			// -- pick() then rejects and the folder is never set.
			//
			// No MIME filter: a books folder holds files, not subfolders, so
			// a directory-only mask renders the target as an empty list
			// ("No files here" -- the files are there, just hidden). canPick
			// shows them but keeps them unpickable instead.
			const picker = getFilePickerBuilder(t('koreader_companion', 'Choose your eBooks folder'))
				.allowDirectories(true)
				.setMultiSelect(false)
				.setCanPick((node) => node.type === 'folder')
				.addButton({
					label: t('koreader_companion', 'Choose'),
					variant: 'primary',
					// The library closes the dialog with the selected nodes
					// after the callback; picking needs no extra work here.
					callback: () => {},
				})
				.build()

			try {
				const paths = await picker.pick()
				const path = Array.isArray(paths) ? paths[0] : paths
				if (!path) {
					return
				}
				const folder = String(path).replace(/^\/+/, '') || 'eBooks'
				const result = await setFolder(folder)
				this.folder = folder
				showSuccess(result?.message || t('koreader_companion', 'Folder updated'))
			} catch (error) {
				// Cancel rejects with FilePickerClosed; not worth a toast.
				if (!(error instanceof FilePickerClosed)) {
					showError(t('koreader_companion', 'Could not change the folder'))
				}
			}
		},

		async onAutoRename(value) {
			this.autoRename = value
			try {
				await setAutoRename(value)
			} catch (error) {
				this.autoRename = !value
				showError(t('koreader_companion', 'Could not save that setting'))
			}
		},

		async confirmRename() {
			this.renaming = true
			this.renameProgress = 0
			this.poll = setInterval(async () => {
				try {
					const progress = await getBatchRenameProgress()
					this.renameProgress = Number(progress?.percent) || 0
				} catch (error) {
					// Progress is advisory; a failed poll should not abort the rename.
				}
			}, 1000)

			try {
				const result = await batchRename()
				showSuccess(t('koreader_companion', 'Renamed {count} of {total} books', {
					count: result?.renamed_count ?? 0,
					total: result?.total_books ?? 0,
				}))
			} catch (error) {
				showError(t('koreader_companion', 'Renaming failed'))
			} finally {
				clearInterval(this.poll)
				this.renaming = false
				this.renameProgress = 0
			}
		},
	},
}
</script>

<style scoped lang="scss">
.settings {
	&__row {
		display: flex;
		align-items: end;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;

		> .button-vue {
			flex: 0 0 auto;
		}
	}

	&__folder {
		flex: 1 1 240px;
		max-width: 360px;
	}

	&__rename {
		margin-block-start: calc(var(--default-grid-baseline) * 3);
	}

	&__progress {
		margin-block-start: calc(var(--default-grid-baseline) * 2);
		max-width: 360px;
	}
}
</style>
