<template>
	<li class="book">
		<div class="book__cover" :class="{ 'book__cover--placeholder': !hasCover }">
			<img
				v-if="hasCover"
				:src="cover"
				:alt="''"
				loading="lazy"
				class="book__image"
				@error="coverFailed = true">
			<span v-else class="book__format">{{ book.format.toUpperCase() }}</span>

			<!-- Progress sits on the cover so the grid stays scannable. -->
			<div v-if="progress" class="book__progress" :title="progressTitle">
				<div class="book__progress-fill" :style="{ width: percentage + '%' }" />
			</div>

			<NcActions class="book__actions" :force-menu="true">
				<NcActionButton close-after-click @click="$emit('edit')">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('koreader_companion', 'Edit metadata') }}
				</NcActionButton>
				<NcActionLink :href="downloadUrl" :download="book.name">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('koreader_companion', 'Download') }}
				</NcActionLink>
				<NcActionLink :href="filesUrl">
					<template #icon>
						<FolderOutline :size="20" />
					</template>
					{{ t('koreader_companion', 'Show in Files') }}
				</NcActionLink>
			</NcActions>
		</div>

		<div class="book__meta">
			<span class="book__title" :title="book.title">{{ book.title }}</span>
			<span class="book__author">{{ book.author || t('koreader_companion', 'Unknown author') }}</span>
			<span v-if="progress" class="book__sync">
				{{ percentageLabel }}
				<span v-if="book.progress.device" class="book__device">· {{ book.progress.device }}</span>
			</span>
			<span v-else-if="book.series" class="book__sync">{{ book.series }}</span>
		</div>
	</li>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActions from '@nextcloud/vue/components/NcActions'
import Download from 'vue-material-design-icons/Download.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'

import { coverUrl } from '../api.js'

// Formats with a cover provider. PDF is absent because Nextcloud 34.0.2 disables
// all ImageMagick-backed preview providers (nextcloud/server#62802), so asking
// for a PDF cover only produces a broken image.
const COVER_FORMATS = ['epub', 'cbz', 'cbr']

export default {
	name: 'BookCard',

	components: {
		Download,
		FolderOutline,
		NcActionButton,
		NcActionLink,
		NcActions,
		Pencil,
	},

	props: {
		book: {
			type: Object,
			required: true,
		},
	},

	emits: ['edit'],

	data() {
		return {
			coverFailed: false,
		}
	},

	computed: {
		hasCover() {
			return !this.coverFailed && COVER_FORMATS.includes((this.book.format || '').toLowerCase())
		},
		cover() {
			return coverUrl(this.book.id)
		},
		progress() {
			return this.book.progress || null
		},
		percentage() {
			return Math.min(100, Math.max(0, Number(this.progress?.percentage) || 0))
		},
		percentageLabel() {
			return t('koreader_companion', '{percent}% read', { percent: this.percentage.toFixed(0) })
		},
		progressTitle() {
			const parts = [this.percentageLabel]
			if (this.progress?.device) {
				parts.push(this.progress.device)
			}
			if (this.progress?.updated_at) {
				parts.push(this.progress.updated_at)
			}
			return parts.join(' · ')
		},
		downloadUrl() {
			// The books are ordinary Nextcloud files, so Files serves them -- no
			// need for an app-specific download route.
			return generateUrl('/f/{fileId}?openfile=false&download', { fileId: this.book.id })
		},
		filesUrl() {
			return generateUrl('/f/{fileId}', { fileId: this.book.id })
		},
	},
}
</script>

<style scoped lang="scss">
.book {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);

	&__cover {
		position: relative;
		aspect-ratio: 2 / 3;
		border-radius: var(--border-radius-large, 8px);
		overflow: hidden;
		background-color: var(--color-background-dark);
		box-shadow: 0 1px 4px var(--color-box-shadow, rgba(0, 0, 0, .15));

		&--placeholder {
			display: flex;
			align-items: center;
			justify-content: center;
			border: 1px solid var(--color-border);
		}
	}

	&__image {
		width: 100%;
		height: 100%;
		object-fit: cover;
		display: block;
	}

	&__format {
		font-size: .8em;
		font-weight: bold;
		letter-spacing: .08em;
		color: var(--color-text-maxcontrast);
	}

	&__progress {
		position: absolute;
		inset-inline: 0;
		inset-block-end: 0;
		height: 4px;
		background-color: var(--color-background-darker);
	}

	&__progress-fill {
		height: 100%;
		background-color: var(--color-primary-element);
	}

	&__actions {
		position: absolute;
		inset-block-start: 2px;
		inset-inline-end: 2px;
	}

	&__meta {
		display: flex;
		flex-direction: column;
		min-width: 0;
	}

	&__title {
		font-weight: 500;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__author,
	&__sync {
		color: var(--color-text-maxcontrast);
		font-size: .85em;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__device {
		opacity: .8;
	}
}
</style>
