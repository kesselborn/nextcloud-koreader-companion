<template>
	<div class="library">
		<!-- Rendered unconditionally, unlike the old template which omitted the
		     search box entirely when the library was empty -- that left the page
		     unable to recover without a reload. -->
		<div class="library__toolbar">
			<NcTextField
				v-model="query"
				:label="t('koreader_companion', 'Search by title or author')"
				trailing-button-icon="close"
				:show-trailing-button="query !== ''"
				class="library__search"
				@trailing-button-click="clearQuery"
				@update:model-value="onQueryInput" />

			<NcSelect
				v-model="sort"
				:options="sortOptions"
				:clearable="false"
				label="label"
				:reduce="option => option.value"
				:aria-label-combobox="t('koreader_companion', 'Sort order')"
				class="library__sort"
				@update:model-value="reload" />

			<span class="library__count">
				{{ n('koreader_companion', '%n book', '%n books', books.length) }}
			</span>

			<!-- Only rendered while something is actually pending: extraction
			     normally happens on its own, and a permanent button would imply the
			     library needs manual upkeep. -->
			<NcNoteCard
				v-if="pendingCount > 0"
				type="info"
				class="library__pending-note">
				<div class="library__pending">
					<span>
						{{ n('koreader_companion',
							'%n book is being processed. Its details will appear here shortly.',
							'%n books are being processed. Their details will appear here shortly.',
							pendingCount) }}
					</span>
					<NcButton
						:disabled="extracting"
						class="library__pending-action"
						@click="extractNow">
						<template #icon>
							<NcLoadingIcon v-if="extracting" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ extracting
							? t('koreader_companion', 'Extracting…')
							: t('koreader_companion', 'Extract metadata now') }}
					</NcButton>
				</div>
			</NcNoteCard>
		</div>

		<NcEmptyContent
			v-if="!loading && books.length === 0"
			:name="query
				? t('koreader_companion', 'No books match your search')
				: t('koreader_companion', 'No books yet')"
			:description="query
				? t('koreader_companion', 'Try a different title or author.')
				: t('koreader_companion', 'Add EPUB, PDF or comic files to your eBooks folder, or upload them here.')">
			<template #icon>
				<BookOpenVariant />
			</template>
		</NcEmptyContent>

		<ul v-else class="library__grid">
			<BookCard
				v-for="book in books"
				:key="book.id"
				:book="book"
				:annotation-count="annotationCounts[book.id] || 0"
				@annotations="$emit('annotations', book)"
				@edit="$emit('edit', book)"
				@read="$emit('read', book)"
				@search-author="searchAuthor" />
		</ul>

		<NcLoadingIcon v-if="loading" :size="32" class="library__loading" />

		<!-- Sentinel for infinite scroll; only rendered while more pages may exist. -->
		<div v-if="hasMore && !loading" ref="sentinel" class="library__sentinel" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

import BookCard from '../components/BookCard.vue'
import { fetchAnnotationCounts, fetchBooks, processPending } from '../api.js'

const PER_PAGE = 50

export default {
	name: 'LibraryView',

	components: {
		BookCard,
		BookOpenVariant,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Refresh,
	},

	emits: ['annotations', 'edit', 'read'],

	data() {
		return {
			books: [],
			// file id => highlight count, for the badge on each cover. Fetched
			// separately because it comes from a folder listing rather than from the
			// metadata table the listing is built from.
			annotationCounts: {},
			page: 1,
			query: '',
			// Last updated by default: what you were reading most recently is what
			// you are most likely to want next, and it counts progress pushed from a
			// device, not just metadata edits.
			sort: 'updated',
			loading: false,
			extracting: false,
			hasMore: true,
			observer: null,
			debounce: null,
			pendingPoll: null,
		}
	},

	computed: {
		pendingCount() {
			return this.books.filter(book => book.indexing_state === 'pending').length
		},
		sortOptions() {
			return [
				{ value: 'updated', label: t('koreader_companion', 'Sort by last updated') },
				{ value: 'title', label: t('koreader_companion', 'Sort by title') },
				{ value: 'author', label: t('koreader_companion', 'Sort by author') },
				{ value: 'recent', label: t('koreader_companion', 'Sort by date added') },
				{ value: 'publication_date', label: t('koreader_companion', 'Sort by publication date') },
			]
		},
	},

	mounted() {
		this.reload()
	},

	beforeUnmount() {
		this.observer?.disconnect()
		clearTimeout(this.debounce)
		clearInterval(this.pendingPoll)
	},

	watch: {
		// Extraction happens in a background job, so a freshly uploaded book stays
		// 'pending' until cron picks it up. Poll while any book is waiting so the
		// list fills itself in, and stop as soon as none are -- no timer ticking
		// away on an idle library.
		pendingCount(count) {
			if (count > 0 && !this.pendingPoll) {
				this.pendingPoll = setInterval(() => this.refreshInPlace(), 5000)
			} else if (count === 0 && this.pendingPoll) {
				clearInterval(this.pendingPoll)
				this.pendingPoll = null
			}
		},
	},

	updated() {
		this.attachObserver()
	},

	methods: {
		onQueryInput() {
			clearTimeout(this.debounce)
			this.debounce = setTimeout(() => this.reload(), 300)
		},

		/**
		 * The clear button only reset the model, so the list kept showing the old
		 * results until something else triggered a fetch. Cancel any pending
		 * debounce too, or it fires afterwards and searches for the text just
		 * cleared.
		 */
		clearQuery() {
			this.query = ''
			clearTimeout(this.debounce)
			this.reload()
		},

		/**
		 * Re-fetch the pages already on screen without collapsing the list, so a
		 * pending book resolving in place does not scroll the user to the top.
		 */
		async refreshInPlace() {
			const pages = Math.max(1, this.page - 1)
			try {
				const batches = await Promise.all(
					Array.from({ length: pages }, (_, i) => fetchBooks({
						page: i + 1,
						query: this.query,
						sort: this.sort,
					})),
				)
				this.books = batches.flat()
				this.loadAnnotationCounts()
			} catch (error) {
				// A failed refresh is not worth interrupting the user; the next
				// tick tries again.
			}
		},

		/**
		 * Put an author into the search box and run the search.
		 *
		 * Goes through the same query the user could have typed rather than a
		 * dedicated author filter, so the box reflects what is on screen and can be
		 * edited or cleared normally.
		 */
		searchAuthor(author) {
			this.query = author
			clearTimeout(this.debounce)
			this.reload()
		},

		/**
		 * Do the extraction the background job would have done, now.
		 *
		 * The server works through a bounded batch, so a big backlog needs more
		 * than one press -- hence reporting what is left rather than implying the
		 * queue is drained.
		 */
		async extractNow() {
			this.extracting = true
			try {
				const { processed, failed, remaining } = await processPending()
				await this.refreshInPlace()

				if (failed > 0) {
					showError(n('koreader_companion',
						'%n book could not be read',
						'%n books could not be read',
						failed))
				}

				if (remaining > 0) {
					showSuccess(n('koreader_companion',
						'%n book still waiting. Press again to continue.',
						'%n books still waiting. Press again to continue.',
						remaining))
				} else if (processed > 0) {
					showSuccess(t('koreader_companion', 'Metadata extracted'))
				}
			} catch (error) {
				showError(t('koreader_companion', 'Could not extract metadata'))
			} finally {
				this.extracting = false
			}
		},

		async reload() {
			this.page = 1
			this.hasMore = true
			this.books = []
			this.annotationCounts = {}
			await this.loadPage()
		},

		/**
		 * Counts for whatever is on screen, in one request.
		 *
		 * Not awaited by the caller: the badge is an addition to a card that is
		 * already useful, so the grid must not wait on a directory listing.
		 */
		async loadAnnotationCounts() {
			const ids = this.books.map((book) => book.id)
			try {
				this.annotationCounts = await fetchAnnotationCounts(ids)
			} catch (error) {
				// No badges is a fine outcome; the covers are unaffected.
			}
		},

		async loadPage() {
			if (this.loading) {
				return
			}
			this.loading = true
			try {
				const batch = await fetchBooks({
					page: this.page,
					query: this.query,
					sort: this.sort,
				})
				this.books.push(...batch)
				this.hasMore = batch.length === PER_PAGE
				this.page += 1
				this.loadAnnotationCounts()
			} catch (error) {
				showError(t('koreader_companion', 'Could not load your library'))
				this.hasMore = false
			} finally {
				this.loading = false
			}
		},

		attachObserver() {
			const sentinel = this.$refs.sentinel
			if (!sentinel) {
				return
			}
			this.observer?.disconnect()
			this.observer = new IntersectionObserver((entries) => {
				if (entries.some(entry => entry.isIntersecting)) {
					this.loadPage()
				}
			}, { rootMargin: '400px' })
			this.observer.observe(sentinel)
		},
	},
}
</script>

<style scoped lang="scss">
.library {
	padding: calc(var(--default-grid-baseline) * 4);

	&__toolbar {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: calc(var(--default-grid-baseline) * 3);
		margin-block-end: calc(var(--default-grid-baseline) * 4);

		// Nextcloud floats the navigation toggle over the top-left of the content
		// area whenever the sidebar is collapsed, and it was landing on top of the
		// search field, clipping the first character. Reserve its width.
		padding-inline-start: var(--default-clickable-area, 44px);

		// Measured, not guessed. NcTextField ships `margin-block-start: 6px` and
		// NcSelect ships `margin-block-end: 4px`, so centring aligned their
		// *margin* boxes and left the visible boxes 5px apart.
		//
		// Nested under the toolbar deliberately: NcTextField's own rule is
		// `.input-field[data-v-…]`, exactly as specific as `.library__search[data-v-…]`
		// would be, and its stylesheet loads later -- so an equally specific reset
		// silently loses. This selector carries one more class and wins outright.
		.library__search,
		.library__sort {
			margin-block: 0;
		}

		// Both controls pinned to one height, nested here for the specificity the
		// components' own scoped rules otherwise win on.
		//
		// 36 rather than --default-clickable-area (34): the select's content is 34
		// tall, so a 34px box leaves a 32px client area and NcSelect's
		// `overflow-y: auto` renders that 2px shortfall as a scrollbar.
		.library__sort :deep(.vs__dropdown-toggle),
		.library__search :deep(.input-field__main-wrapper) {
			box-sizing: border-box;
			min-height: calc(var(--default-clickable-area, 34px) + 2px);
		}
	}

	&__search {
		flex: 1 1 260px;
		max-width: 420px;
	}

	&__sort {
		flex: 0 0 260px;
		min-width: 240px;


	}

	&__pending-note {
		flex: 1 1 100%;
		margin-block: 0;
	}

	&__pending {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: space-between;
		gap: calc(var(--default-grid-baseline) * 3);
	}

	&__pending-action {
		flex: 0 0 auto;
	}

	&__count {
		color: var(--color-text-maxcontrast);
		font-size: .9em;
		margin-inline-start: auto;
		font-variant-numeric: tabular-nums;
	}

	&__grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
		gap: calc(var(--default-grid-baseline) * 5);
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__loading {
		margin: calc(var(--default-grid-baseline) * 6) auto;
	}

	&__sentinel {
		height: 1px;
	}
}
</style>
