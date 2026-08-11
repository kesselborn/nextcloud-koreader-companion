<template>
	<NcContent app-name="koreader_companion">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationNew
					:text="t('koreader_companion', 'Upload books')"
					button-class="icon-add"
					@click="uploadOpen = true" />

				<NcAppNavigationItem
					v-for="item in sections"
					:key="item.id"
					:name="item.label"
					:active="section === item.id"
					@click="section = item.id">
					<template #icon>
						<component :is="item.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<LibraryView
				v-if="section === 'library'"
				ref="library"
				@edit="editBook"
				@read="reading = $event" />
			<SettingsView v-else-if="section === 'settings'" />
			<ConnectionView
				v-else
				:kind="section"
				:info="connectionInfo" />
		</NcAppContent>

		<ReaderModal
			v-if="reading"
			:book="reading"
			@saved="$refs.library?.refreshInPlace()"
			@close="reading = null" />

		<UploadModal
			v-if="uploadOpen"
			@close="uploadOpen = false"
			@uploaded="onUploaded" />

		<MetadataModal
			v-if="editing"
			:book="editing"
			@close="editing = null"
			@saved="onUploaded"
			@deleted="onUploaded" />
	</NcContent>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcContent from '@nextcloud/vue/components/NcContent'

import BookOpenVariant from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import Cog from 'vue-material-design-icons/CogOutline.vue'
import RssIcon from 'vue-material-design-icons/RssBox.vue'
import SyncIcon from 'vue-material-design-icons/SyncCircle.vue'

import ConnectionView from './views/ConnectionView.vue'
import LibraryView from './views/LibraryView.vue'
import MetadataModal from './components/MetadataModal.vue'
import ReaderModal from './components/ReaderModal.vue'
import SettingsView from './views/SettingsView.vue'
import UploadModal from './components/UploadModal.vue'

export default {
	name: 'App',

	components: {
		ConnectionView,
		LibraryView,
		MetadataModal,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationItem,
		NcAppNavigationNew,
		NcContent,
		ReaderModal,
		SettingsView,
		UploadModal,
	},

	data() {
		return {
			section: 'library',
			uploadOpen: false,
			editing: null,
			reading: null,
			connectionInfo: loadState('koreader_companion', 'connection', {}),
		}
	},

	computed: {
		sections() {
			return [
				{ id: 'library', label: t('koreader_companion', 'Library'), icon: BookOpenVariant },
				{ id: 'settings', label: t('koreader_companion', 'Settings'), icon: Cog },
				{ id: 'sync', label: t('koreader_companion', 'KOReader sync'), icon: SyncIcon },
				{ id: 'opds', label: t('koreader_companion', 'OPDS access'), icon: RssIcon },
			]
		},
	},

	methods: {
		editBook(book) {
			this.editing = book
		},
		onUploaded() {
			this.editing = null
			this.uploadOpen = false
			this.$refs.library?.reload()
		},
	},
}
</script>
