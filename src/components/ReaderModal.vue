<template>
	<NcModal
		size="full"
		:name="book.title"
		:close-button-contained="false"
		@close="$emit('close')">
		<div class="reader">
			<div class="reader__header">
				<span class="reader__title">{{ book.title }}</span>
				<span v-if="chapter" class="reader__chapter">{{ chapter }}</span>
				<div class="reader__zoom">
					<NcButton
						:aria-label="t('koreader_companion', 'Smaller text')"
						type="tertiary"
						@click="setFontSize(fontSize - 10)">
						<template #icon>
							<FormatFontSizeDecrease :size="20" />
						</template>
					</NcButton>
					<NcButton
						:aria-label="t('koreader_companion', 'Larger text')"
						type="tertiary"
						@click="setFontSize(fontSize + 10)">
						<template #icon>
							<FormatFontSizeIncrease :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<div class="reader__stage">
				<NcButton
					:aria-label="t('koreader_companion', 'Previous page')"
					type="tertiary"
					class="reader__page-button"
					:disabled="!ready"
					@click="prev">
					<template #icon>
						<ChevronLeft :size="24" />
					</template>
				</NcButton>

				<!-- epub.js writes into an iframe it owns, so this element must stay
				     empty and keep a stable size; the ResizeObserver relays every
				     size change to the rendition. -->
				<div ref="viewer" class="reader__viewer" />

				<NcButton
					:aria-label="t('koreader_companion', 'Next page')"
					type="tertiary"
					class="reader__page-button"
					:disabled="!ready"
					@click="next">
					<template #icon>
						<ChevronRight :size="24" />
					</template>
				</NcButton>

				<div v-if="!ready" class="reader__overlay">
					<NcLoadingIcon v-if="!error" :size="44" />
					<NcEmptyContent v-else :name="error">
						<template #icon>
							<AlertCircle />
						</template>
					</NcEmptyContent>
				</div>
			</div>

			<div class="reader__footer">
				<input
					type="range"
					min="0"
					max="100"
					step="1"
					:value="percent"
					:disabled="!locationsReady"
					:aria-label="t('koreader_companion', 'Position in book')"
					class="reader__slider"
					@change="seek">
				<span class="reader__percent">
					{{ locationsReady
						? t('koreader_companion', '{percent}%', { percent })
						: t('koreader_companion', 'Measuring…') }}
				</span>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { markRaw } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import AlertCircle from 'vue-material-design-icons/AlertCircleOutline.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FormatFontSizeDecrease from 'vue-material-design-icons/FormatFontSizeDecrease.vue'
import FormatFontSizeIncrease from 'vue-material-design-icons/FormatFontSizeIncrease.vue'

import { fetchBookFile } from '../api.js'

// Where we left off, per book. Deliberately local: KOReader's own sync is
// device-to-device over the /sync API and uses its own progress model, and
// writing web-reader positions into it would confuse a real device.
const positionKey = (id) => `koreader_companion:position:${id}`
const FONT_SIZE_KEY = 'koreader_companion:font-size'
const MIN_FONT_SIZE = 70
const MAX_FONT_SIZE = 200

export default {
	name: 'ReaderModal',

	components: {
		AlertCircle,
		ChevronLeft,
		ChevronRight,
		FormatFontSizeDecrease,
		FormatFontSizeIncrease,
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

	emits: ['close'],

	data() {
		return {
			// markRaw throughout: epub.js keeps internal object identities and
			// breaks when handed back a reactive proxy of itself.
			epub: null,
			rendition: null,
			resizeObserver: null,
			ready: false,
			error: '',
			percent: 0,
			chapter: '',
			locationsReady: false,
			fontSize: Number(localStorage.getItem(FONT_SIZE_KEY)) || 100,
		}
	},

	mounted() {
		document.addEventListener('keyup', this.onKey)
		this.open()
	},

	beforeUnmount() {
		document.removeEventListener('keyup', this.onKey)
		this.resizeObserver?.disconnect()
		this.rendition?.destroy()
		this.epub?.destroy()
	},

	methods: {
		async open() {
			try {
				// epub.js drags in a ZIP implementation, so keep it out of the main
				// bundle -- only readers pay for it.
				const [{ default: ePub }, data] = await Promise.all([
					import('epubjs'),
					fetchBookFile(this.book.id),
				])

				this.epub = markRaw(ePub(data))
				this.rendition = markRaw(this.epub.renderTo(this.$refs.viewer, {
					width: '100%',
					height: '100%',
					flow: 'paginated',
					spread: 'auto',
					allowScriptedContent: false,
				}))
				this.rendition.themes.fontSize(`${this.fontSize}%`)
				this.rendition.on('relocated', this.onRelocated)
				this.rendition.on('keyup', this.onKey)

				await this.rendition.display(localStorage.getItem(positionKey(this.book.id)) || undefined)
				this.ready = true
				this.observeResize()
				this.loadLocations()
			} catch (error) {
				this.error = t('koreader_companion', 'This book could not be opened')
			}
		},

		/**
		 * Locations are what turn a CFI into a percentage, and generating them
		 * means walking the whole book -- seconds on a large one. Do it after the
		 * first page is on screen and cache the result.
		 */
		async loadLocations() {
			const key = `koreader_companion:locations:${this.book.id}`
			try {
				const stored = localStorage.getItem(key)
				if (stored) {
					this.epub.locations.load(stored)
				} else {
					await this.epub.locations.generate(1600)
					try {
						localStorage.setItem(key, this.epub.locations.save())
					} catch (quotaExceeded) {
						// Caching is an optimisation; losing it costs a few seconds
						// next time, not the position readout we just computed.
					}
				}
				this.locationsReady = true
				this.onRelocated(this.rendition.currentLocation())
			} catch (error) {
				// A book without a position readout is still a readable book.
			}
		},

		observeResize() {
			this.resizeObserver = markRaw(new ResizeObserver(() => {
				this.rendition?.resize(this.$refs.viewer.clientWidth, this.$refs.viewer.clientHeight)
			}))
			this.resizeObserver.observe(this.$refs.viewer)
		},

		onRelocated(location) {
			const cfi = location?.start?.cfi
			if (!cfi) {
				return
			}
			localStorage.setItem(positionKey(this.book.id), cfi)
			if (this.locationsReady) {
				this.percent = Math.round(this.epub.locations.percentageFromCfi(cfi) * 100)
			}
			this.chapter = this.epub.navigation?.get(location.start.href)?.label?.trim() || ''
		},

		onKey(event) {
			if (event.key === 'ArrowLeft') {
				this.prev()
			} else if (event.key === 'ArrowRight') {
				this.next()
			}
		},

		prev() {
			this.rendition?.prev()
		},

		next() {
			this.rendition?.next()
		},

		seek(event) {
			const cfi = this.epub.locations.cfiFromPercentage(Number(event.target.value) / 100)
			this.rendition?.display(cfi)
		},

		setFontSize(size) {
			this.fontSize = Math.min(MAX_FONT_SIZE, Math.max(MIN_FONT_SIZE, size))
			localStorage.setItem(FONT_SIZE_KEY, String(this.fontSize))
			this.rendition?.themes.fontSize(`${this.fontSize}%`)
		},
	},
}
</script>

<style scoped lang="scss">
.reader {
	display: flex;
	flex-direction: column;
	height: 100%;
	box-sizing: border-box;

	&__header {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
		padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 12)
			calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
		border-block-end: 1px solid var(--color-border);
	}

	&__title {
		font-weight: 600;
	}

	&__title,
	&__chapter {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__chapter {
		color: var(--color-text-maxcontrast);
		font-size: .9em;
	}

	&__zoom {
		display: flex;
		gap: var(--default-grid-baseline);
		margin-inline-start: auto;
	}

	&__stage {
		position: relative;
		flex: 1;
		display: flex;
		align-items: center;
		min-height: 0;
	}

	&__viewer {
		flex: 1;
		height: 100%;
		min-width: 0;
		// The book brings its own typography on a light page; forcing the
		// Nextcloud dark theme onto arbitrary EPUB CSS produces unreadable
		// combinations far more often than it helps.
		background-color: #fff;
	}

	&__page-button {
		flex: 0 0 auto;
		margin-inline: var(--default-grid-baseline);
	}

	&__overlay {
		position: absolute;
		inset: 0;
		display: flex;
		align-items: center;
		justify-content: center;
		background-color: var(--color-main-background);
	}

	&__footer {
		display: flex;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 4);
		padding: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
		border-block-start: 1px solid var(--color-border);
	}

	&__slider {
		flex: 1;
		min-width: 0;
	}

	&__percent {
		color: var(--color-text-maxcontrast);
		font-size: .9em;
		font-variant-numeric: tabular-nums;
		min-width: 6em;
		text-align: end;
	}
}
</style>
