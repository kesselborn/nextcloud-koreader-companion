<template>
	<NcModal
		:name="t('koreader_companion', 'Upload books')"
		size="normal"
		@close="$emit('close')">
		<div class="upload">
			<h2 class="upload__heading">{{ t('koreader_companion', 'Upload books') }}</h2>

			<div
				v-if="!current"
				class="upload__drop"
				:class="{ 'upload__drop--active': dragging }"
				@dragover.prevent="dragging = true"
				@dragleave.prevent="dragging = false"
				@drop.prevent="onDrop">
				<Upload :size="40" />
				<p>{{ t('koreader_companion', 'Drop files here, or choose them below.') }}</p>
				<p class="upload__formats">{{ acceptLabel }}</p>
				<NcButton type="primary" @click="$refs.input.click()">
					{{ t('koreader_companion', 'Choose files') }}
				</NcButton>
				<input
					ref="input"
					type="file"
					multiple
					:accept="accept"
					class="hidden-visually"
					@change="onSelectFiles">
			</div>

			<!-- Two-step flow kept from the original: extract metadata, let the
			     user correct it, then commit. -->
			<div v-else class="upload__review">
				<p class="upload__progress-label">
					{{ t('koreader_companion', 'File {index} of {total}: {name}', {
						index: index + 1, total: queue.length, name: current.name }) }}
				</p>

				<div class="upload__grid">
					<NcTextField v-model="form.title" :label="t('koreader_companion', 'Title')" />
					<NcTextField v-model="form.author" :label="t('koreader_companion', 'Author')" />
					<NcTextField v-model="form.publisher" :label="t('koreader_companion', 'Publisher')" />
					<NcTextField v-model="form.language" :label="t('koreader_companion', 'Language')" />
					<NcTextField v-model="form.series" :label="t('koreader_companion', 'Series')" />
					<NcTextField v-model="form.tags" :label="t('koreader_companion', 'Genre or tags')" />
				</div>

				<!-- NcProgressBar takes `value`, not `modelValue`. -->
				<NcProgressBar v-if="uploading" :value="percent" size="medium" />

				<div class="upload__footer">
					<NcButton :disabled="uploading" @click="skip">
						{{ t('koreader_companion', 'Skip') }}
					</NcButton>
					<NcButton type="primary" :disabled="uploading" @click="commit">
						<template #icon>
							<NcLoadingIcon v-if="uploading" :size="20" />
						</template>
						{{ t('koreader_companion', 'Add to library') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Upload from 'vue-material-design-icons/Upload.vue'

import { extractMetadata, uploadBook } from '../api.js'

// Matches the extensions the backend listener indexes. cbz is absent there too;
// see tasks.md 3.8.
const EXTENSIONS = ['epub', 'pdf', 'cbr', 'mobi']

export default {
	name: 'UploadModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcProgressBar,
		NcTextField,
		Upload,
	},

	emits: ['close', 'uploaded'],

	data() {
		return {
			queue: [],
			index: 0,
			form: {},
			dragging: false,
			uploading: false,
			percent: 0,
			uploadedAny: false,
		}
	},

	computed: {
		accept() {
			return EXTENSIONS.map(e => '.' + e).join(',')
		},
		acceptLabel() {
			return t('koreader_companion', 'Accepted formats: {formats}', {
				formats: EXTENSIONS.join(', ').toUpperCase(),
			})
		},
		current() {
			return this.queue[this.index] || null
		},
	},

	methods: {
		onDrop(event) {
			this.dragging = false
			this.enqueue(Array.from(event.dataTransfer?.files || []))
		},

		onSelectFiles(event) {
			this.enqueue(Array.from(event.target.files || []))
		},

		async enqueue(files) {
			const accepted = files.filter((file) => {
				const ext = file.name.split('.').pop().toLowerCase()
				return EXTENSIONS.includes(ext)
			})

			if (accepted.length !== files.length) {
				showError(t('koreader_companion', 'Some files were skipped: unsupported format'))
			}
			if (accepted.length === 0) {
				return
			}

			this.queue = accepted
			this.index = 0
			await this.prepareCurrent()
		},

		async prepareCurrent() {
			if (!this.current) {
				this.finish()
				return
			}
			this.percent = 0
			try {
				const extracted = await extractMetadata(this.current)
				this.form = {
					title: extracted?.title || this.current.name.replace(/\.[^.]+$/, ''),
					author: extracted?.author || '',
					publisher: extracted?.publisher || '',
					language: extracted?.language || '',
					series: extracted?.series || '',
					tags: extracted?.tags || extracted?.subject || '',
				}
			} catch (error) {
				// Extraction failing is recoverable: fall back to the filename and
				// let the user type the rest.
				this.form = { title: this.current.name.replace(/\.[^.]+$/, '') }
			}
		},

		async commit() {
			this.uploading = true
			try {
				await uploadBook(this.current, this.form, (p) => { this.percent = p })
				this.uploadedAny = true
				await this.advance()
			} catch (error) {
				showError(t('koreader_companion', 'Upload of {name} failed', { name: this.current.name }))
			} finally {
				this.uploading = false
			}
		},

		async skip() {
			await this.advance()
		},

		async advance() {
			this.index += 1
			if (this.current) {
				await this.prepareCurrent()
			} else {
				this.finish()
			}
		},

		finish() {
			if (this.uploadedAny) {
				showSuccess(t('koreader_companion', 'Library updated'))
				this.$emit('uploaded')
			} else {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.upload {
	padding: calc(var(--default-grid-baseline) * 5);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);

	&__heading {
		margin: 0;
	}

	&__drop {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		padding: calc(var(--default-grid-baseline) * 10);
		border: 2px dashed var(--color-border-dark);
		border-radius: var(--border-radius-large, 8px);
		text-align: center;

		&--active {
			border-color: var(--color-primary-element);
			background-color: var(--color-primary-element-light);
		}

		p {
			margin: 0;
		}
	}

	&__formats {
		color: var(--color-text-maxcontrast);
		font-size: .85em;
	}

	&__review {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__progress-label {
		margin: 0;
		color: var(--color-text-maxcontrast);
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__footer {
		display: flex;
		justify-content: flex-end;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
