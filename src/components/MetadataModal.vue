<template>
	<NcModal
		:name="t('koreader_companion', 'Edit book details')"
		size="normal"
		@close="$emit('close')">
		<div class="metadata">
			<h2 class="metadata__heading">{{ t('koreader_companion', 'Edit book details') }}</h2>

			<div class="metadata__grid">
				<NcTextField v-model="form.title" :label="t('koreader_companion', 'Title')" />
				<NcTextField v-model="form.author" :label="t('koreader_companion', 'Author')" />
				<NcTextField v-model="form.publisher" :label="t('koreader_companion', 'Publisher')" />
				<NcTextField v-model="form.language" :label="t('koreader_companion', 'Language')" />
				<NcTextField v-model="form.publication_date" :label="t('koreader_companion', 'Publication date')" />
				<NcTextField v-model="form.identifier" :label="t('koreader_companion', 'ISBN or identifier')" />
				<!-- Series applies to every format, not just comics as the old form assumed. -->
				<NcTextField v-model="form.series" :label="t('koreader_companion', 'Series')" />
				<NcTextField v-model="form.tags" :label="t('koreader_companion', 'Genre or tags')" />
				<template v-if="isComic">
					<NcTextField v-model="form.issue" :label="t('koreader_companion', 'Issue')" />
					<NcTextField v-model="form.volume" :label="t('koreader_companion', 'Volume')" />
				</template>
			</div>

			<NcTextArea
				v-model="form.description"
				:label="t('koreader_companion', 'Description')"
				class="metadata__description"
				rows="4" />

			<div class="metadata__footer">
				<NcButton type="error" :disabled="busy" @click="confirmDelete">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('koreader_companion', 'Delete book') }}
				</NcButton>
				<div class="metadata__spacer" />
				<NcButton :disabled="busy" @click="$emit('close')">
					{{ t('koreader_companion', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="busy" @click="save">
					<template #icon>
						<NcLoadingIcon v-if="busy" :size="20" />
					</template>
					{{ t('koreader_companion', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Delete from 'vue-material-design-icons/Delete.vue'

import { deleteBook, updateMetadata } from '../api.js'

const FIELDS = [
	'title', 'author', 'publisher', 'language', 'publication_date',
	'identifier', 'series', 'tags', 'issue', 'volume', 'description',
]

export default {
	name: 'MetadataModal',

	components: {
		Delete,
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcTextArea,
		NcTextField,
	},

	props: {
		book: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'saved', 'deleted'],

	data() {
		const form = {}
		FIELDS.forEach((field) => {
			form[field] = this.book[field] ?? ''
		})
		return { form, busy: false }
	},

	computed: {
		isComic() {
			return ['cbr', 'cbz'].includes((this.book.format || '').toLowerCase())
		},
	},

	methods: {
		async save() {
			this.busy = true
			try {
				await updateMetadata(this.book.id, this.form)
				showSuccess(t('koreader_companion', 'Saved'))
				this.$emit('saved')
			} catch (error) {
				showError(t('koreader_companion', 'Could not save the details'))
			} finally {
				this.busy = false
			}
		},

		async confirmDelete() {
			const message = t('koreader_companion', 'Delete “{title}”? The file is moved to your Nextcloud trash.', {
				title: this.book.title || this.book.name,
			})
			if (!window.confirm(message)) {
				return
			}

			this.busy = true
			try {
				await deleteBook(this.book.id)
				showSuccess(t('koreader_companion', 'Book deleted'))
				this.$emit('deleted')
			} catch (error) {
				showError(t('koreader_companion', 'Could not delete the book'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.metadata {
	padding: calc(var(--default-grid-baseline) * 5);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);

	&__heading {
		margin: 0;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__footer {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 2);
		flex-wrap: wrap;
	}

	&__spacer {
		flex: 1 1 auto;
	}
}
</style>
