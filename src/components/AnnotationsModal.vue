<template>
	<NcModal
		size="normal"
		:label-id="titleId"
		@close="$emit('close')">
		<div class="annotations">
			<h2 :id="titleId" class="annotations__title">
				{{ book.title }}
				<span class="annotations__subtitle">{{ summary }}</span>
			</h2>

			<NcLoadingIcon v-if="loading" class="annotations__loading" :size="32" />

			<NcEmptyContent v-else-if="error" :name="error">
				<template #icon>
					<AlertCircleOutline />
				</template>
			</NcEmptyContent>

			<NcEmptyContent
				v-else-if="!annotations.length"
				:name="t('koreader_companion', 'No highlights yet')"
				:description="t('koreader_companion', 'Highlights appear here once your device has synced them.')">
				<template #icon>
					<Marker />
				</template>
			</NcEmptyContent>

			<ul v-else class="annotations__list">
				<li v-for="(group, chapter) in grouped" :key="chapter" class="annotations__group">
					<h3 v-if="chapter" class="annotations__chapter">{{ chapter }}</h3>

					<ul>
						<li v-for="(item, index) in group" :key="index" class="annotations__item">
							<blockquote class="annotations__text" :class="'annotations__text--' + (item.color || 'gray')">
								{{ item.text }}
							</blockquote>

							<p v-if="item.note" class="annotations__note">{{ item.note }}</p>

							<div class="annotations__meta">
								<span v-if="item.pageno">
									{{ t('koreader_companion', 'Page {page}', { page: item.pageno }) }}
								</span>
								<span v-if="item.datetime">{{ item.datetime }}</span>
								<!-- Only offered for EPUB: the reader is epub.js, so there is
								     nowhere to jump to for the other formats. -->
								<NcButton
									v-if="readable"
									type="tertiary"
									class="annotations__jump"
									@click="$emit('jump', item)">
									<template #icon>
										<BookOpenPageVariant :size="18" />
									</template>
									{{ t('koreader_companion', 'Show in book') }}
								</NcButton>
							</div>
						</li>
					</ul>
				</li>
			</ul>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue'
import Marker from 'vue-material-design-icons/Marker.vue'

import { fetchAnnotations } from '../api.js'

export default {
	name: 'AnnotationsModal',

	components: {
		AlertCircleOutline,
		BookOpenPageVariant,
		Marker,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcModal,
	},

	props: {
		book: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'jump'],

	data() {
		return {
			annotations: [],
			loading: true,
			error: null,
		}
	},

	computed: {
		// NcModal labels itself from this element rather than painting a title of
		// its own: its floating title bar sits at the top of the viewport, where it
		// covered Nextcloud's search field and repeated the heading below it.
		titleId() {
			return `kc-annotations-title-${this.book.id}`
		},
		readable() {
			return (this.book.format || '').toLowerCase() === 'epub'
		},
		notes() {
			return this.annotations.filter((item) => item.note).length
		},
		summary() {
			const parts = [
				n(
					'koreader_companion',
					'%n highlight',
					'%n highlights',
					this.annotations.length,
				),
			]
			if (this.notes) {
				parts.push(n('koreader_companion', '%n note', '%n notes', this.notes))
			}
			return parts.join(' · ')
		},
		/**
		 * Grouped by chapter, in reading order.
		 *
		 * The server already sorted by page, so inserting each item into its
		 * chapter's bucket keeps both the chapter order and the order within one.
		 */
		grouped() {
			const groups = {}
			for (const item of this.annotations) {
				const chapter = item.chapter || ''
				if (!groups[chapter]) {
					groups[chapter] = []
				}
				groups[chapter].push(item)
			}
			return groups
		},
	},

	async mounted() {
		try {
			this.annotations = await fetchAnnotations(this.book.id)
		} catch (error) {
			this.error = t('koreader_companion', 'Could not load your highlights')
		} finally {
			this.loading = false
		}
	},
}
</script>

<style scoped lang="scss">
.annotations {
	padding: calc(var(--default-grid-baseline) * 5);
	max-height: 80vh;
	overflow-y: auto;

	&__title {
		display: flex;
		flex-direction: column;
		gap: calc(var(--default-grid-baseline));
		margin-block: 0 calc(var(--default-grid-baseline) * 4);
	}

	&__subtitle {
		font-size: .8em;
		font-weight: normal;
		color: var(--color-text-maxcontrast);
	}

	&__loading {
		margin-block: calc(var(--default-grid-baseline) * 10);
	}

	&__list,
	&__group ul {
		list-style: none;
		padding: 0;
		margin: 0;
	}

	&__chapter {
		font-size: .9em;
		color: var(--color-text-maxcontrast);
		margin-block: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 2);
		position: sticky;
		inset-block-start: 0;
		background-color: var(--color-main-background);
		padding-block: calc(var(--default-grid-baseline));
	}

	&__item {
		padding-block-end: calc(var(--default-grid-baseline) * 3);
		margin-block-end: calc(var(--default-grid-baseline) * 3);
		border-block-end: 1px solid var(--color-border);

		&:last-child {
			border-block-end: 0;
		}
	}

	// The device records a colour per highlight; showing it as the quote's own
	// border is the cheapest way to carry that across without recolouring text.
	&__text {
		margin: 0;
		padding-inline-start: calc(var(--default-grid-baseline) * 3);
		border-inline-start: 3px solid var(--color-primary-element);

		&--yellow { border-inline-start-color: #e9d54a; }
		&--green { border-inline-start-color: #5aa469; }
		&--blue { border-inline-start-color: #4a90d9; }
		&--red { border-inline-start-color: #d94a4a; }
		&--orange { border-inline-start-color: #e08a3c; }
		&--purple { border-inline-start-color: #9a5ac4; }
		&--gray { border-inline-start-color: var(--color-border-dark); }
	}

	&__note {
		margin-block: calc(var(--default-grid-baseline) * 2) 0;
		margin-inline-start: calc(var(--default-grid-baseline) * 3);
		padding: calc(var(--default-grid-baseline) * 2);
		border-radius: var(--border-radius);
		background-color: var(--color-background-hover);
		font-size: .95em;
	}

	&__meta {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
		margin-block-start: calc(var(--default-grid-baseline) * 2);
		font-size: .8em;
		color: var(--color-text-maxcontrast);
	}

	&__jump {
		margin-inline-start: auto;
	}
}
</style>
