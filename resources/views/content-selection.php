<div class="p-6 bg-white rounded-lg shadow-md mt-8 mr-6" 
    x-data='contentSelection({
        postTypes: <?php echo json_encode($saved_post_types, JSON_THROW_ON_ERROR) ?>, 
        taxonomies: <?php echo json_encode($saved_taxonomies, JSON_THROW_ON_ERROR) ?>,
        metaKeys: <?php echo json_encode($saved_meta_keys ?? [], JSON_THROW_ON_ERROR) ?>,
        nonIndexableMetaKeys: <?php echo json_encode($non_indexable_meta_keys ?? [], JSON_THROW_ON_ERROR) ?>,
        structureChanges: <?php echo json_encode($structure_changes ?? ['has_changed' => false, 'changes' => [], 'last_indexed' => null], JSON_THROW_ON_ERROR) ?>
    })' 
    x-init="initRepeaterFields()">
    
    <!-- Notification de changement de structure -->
    <div x-show="showNotification" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 transform -translate-y-2"
         x-transition:enter-end="opacity-100 transform translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 transform translate-y-0"
         x-transition:leave-end="opacity-0 transform -translate-y-2"
         class="mb-4 p-4 bg-yellow-50 border-l-4 border-yellow-400">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-yellow-800">Attention</h3>
                <div class="mt-2 text-sm text-yellow-700 whitespace-pre-line" x-text="getNotificationMessage()"></div>
                <div class="mt-4">
                    <div class="-mx-2 -my-1.5 flex">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=meiliscout-indexation')); ?>" class="rounded-md bg-yellow-50 px-2 py-1.5 text-sm font-medium text-yellow-800 hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-yellow-600 focus:ring-offset-2 focus:ring-offset-yellow-50">
                            Aller à la page d'indexation
                        </a>
                        <button type="button" @click="showNotification = false" class="ml-3 rounded-md bg-yellow-50 px-2 py-1.5 text-sm font-medium text-yellow-800 hover:bg-yellow-100 focus:outline-none focus:ring-2 focus:ring-yellow-600 focus:ring-offset-2 focus:ring-offset-yellow-50">
                            Fermer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <h1 class="text-3xl font-bold mb-4 text-gray-800">Sélection de Contenu pour MeiliSearch</h1>
    
    <!-- Onglets -->
    <div class="border-b border-gray-200 mb-6">
        <nav class="-mb-px flex space-x-8" aria-label="Tabs">
            <button @click="activeTab = 'content'" :class="{'border-indigo-500 text-indigo-600': activeTab === 'content', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': activeTab !== 'content'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                Contenus à indexer
            </button>
            <button @click="activeTab = 'meta'" :class="{'border-indigo-500 text-indigo-600': activeTab === 'meta', 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300': activeTab !== 'meta'}" class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                Meta Keys à indexer
            </button>
        </nav>
    </div>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('meiliscout_content_selection_save', 'meiliscout_content_selection_nonce'); ?>
        <input type="hidden" name="action" value="meiliscout_content_selection">

        <!-- Onglet Contenus -->
        <div x-show="activeTab === 'content'">
            <div class="mb-4">
                <h2 class="text-lg font-semibold mb-2">Types de Contenu</h2>
                <?php foreach ($post_types as $post_type) { ?>
                    <div class="flex items-center mb-2">
                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($post_type->name); ?>" id="post_type_<?php echo esc_attr($post_type->name); ?>" class="hidden!" x-model="postTypes">
                        <label :id="$id('pt_toggle_label_<?php echo esc_attr($post_type->name); ?>')" class="flex items-center cursor-pointer">
                            <button x-ref="pt_toggle_<?php echo $post_type->name; ?>" @click="postTypes.includes('<?php echo esc_attr($post_type->name); ?>') ? postTypes.splice(postTypes.indexOf('<?php echo esc_attr($post_type->name); ?>'), 1) : postTypes.push('<?php echo esc_attr($post_type->name); ?>')" type="button" role="switch" :aria-checked="postTypes.includes('<?php echo esc_attr($post_type->name); ?>')" :class="postTypes.includes('<?php echo esc_attr($post_type->name); ?>') ? 'bg-gray-800' : 'bg-gray-800/20'" class="relative inline-flex h-5 w-8 mr-2 items-center rounded-full outline-offset-2 transition">
                                <span :class="postTypes.includes('<?php echo esc_attr($post_type->name); ?>') ? 'translate-x-[15px]' : 'translate-x-[3px]'" class="bg-white size-3.5 rounded-full transition shadow-md" aria-hidden="true"></span>
                            </button>
                            <span class="font-medium text-gray-800 select-none"><?php echo esc_html($post_type->label); ?></span>
                        </label>
                    </div>
                <?php } ?>
            </div>

            <div class="mb-4">
                <h2 class="text-lg font-semibold mb-2">Taxonomies</h2>
                <?php foreach ($taxonomies as $taxonomy) { ?>
                    <div class="flex items-center mb-2">
                        <input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr($taxonomy->name); ?>" id="taxonomy_<?php echo esc_attr($taxonomy->name); ?>" class="hidden!" x-model="taxonomies">
                        <label :id="$id('toggle-taxonomy_<?php echo esc_attr($taxonomy->name); ?>')" class="flex items-center cursor-pointer">
                            <button @click="taxonomies.includes('<?php echo esc_attr($taxonomy->name); ?>') ? taxonomies.splice(taxonomies.indexOf('<?php echo esc_attr($taxonomy->name); ?>'), 1) : taxonomies.push('<?php echo esc_attr($taxonomy->name); ?>')" type="button" role="switch" :aria-checked="taxonomies.includes('<?php echo esc_attr($taxonomy->name); ?>')" :class="taxonomies.includes('<?php echo esc_attr($taxonomy->name); ?>') ? 'bg-gray-800' : 'bg-gray-800/20'" class="relative inline-flex h-5 w-8 mr-2 items-center rounded-full outline-offset-2 transition">
                                <span :class="taxonomies.includes('<?php echo esc_attr($taxonomy->name); ?>') ? 'translate-x-[15px]' : 'translate-x-[3px]'" class="bg-white size-3.5 rounded-full transition shadow-md" aria-hidden="true"></span>
                            </button>
                            <span class="font-medium text-gray-800 select-none"><?php echo esc_html($taxonomy->label); ?></span>
                        </label>
                    </div>
                <?php } ?>
            </div>
        </div>

        <!-- Onglet Meta Keys -->
        <div x-show="activeTab === 'meta'" class="mb-4">
            <h2 class="text-lg font-semibold mb-2">Meta Keys à indexer</h2>
            <p class="text-sm text-gray-600 mb-4">Ajoutez les meta keys que vous souhaitez rendre filtrables dans MeiliSearch.</p>
            
            <!-- Meta keys non indexables détectées -->
            <div x-show="nonIndexableMetaKeys.length > 0" class="mb-6 p-4 bg-yellow-50 border-l-4 border-yellow-400 rounded-md">
                <h3 class="text-sm font-medium text-yellow-800 mb-2">Meta keys non indexables détectées</h3>
                <p class="text-sm text-yellow-700 mb-3">Ces meta keys ont été utilisées dans des requêtes mais ne sont pas configurées pour l'indexation :</p>
                <div class="flex flex-wrap gap-2">
                    <template x-for="metaKey in nonIndexableMetaKeys" :key="metaKey">
                        <button 
                            @click="addNonIndexableMetaKey(metaKey)"
                            type="button"
                            class="inline-flex items-center px-2 py-1 rounded-md text-sm font-medium bg-yellow-100 text-yellow-800 hover:bg-yellow-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-yellow-500">
                            <span x-text="metaKey"></span>
                            <svg class="ml-1.5 h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </template>
                </div>
            </div>
            
            <div class="max-h-64 overflow-y-auto overflow-x-hidden -mr-3 scroll-smooth" x-ref="scrollContainer">
                <template x-for="(field, index) in getFields('meta_keys')" :key="index">
                    <div class="flex items-center mb-3">
                        <input 
                            type="text" 
                            :name="'meta_keys[' + index + ']'"
                            x-model="fields.meta_keys[index]"
                            x-init="storeRef('meta_keys', $el, index)"
                            @keydown.enter.prevent="addField('meta_keys', index + 1)"
                            @keydown.backspace="removeFieldAndFocusPrevious('meta_keys', index)"
                            class="block w-full rounded-md border-0 py-1.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
                            placeholder="Nom de la meta key">
                        <button 
                            @click="removeField('meta_keys', index)" 
                            type="button" 
                            class="mx-1 text-red-500">
                            <span class="sr-only">Supprimer</span>
                            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"></path>
                            </svg>
                        </button>
                    </div>
                </template>
            </div>
            <button 
                @click="addField('meta_keys')" 
                type="button" 
                class="text-sm font-semibold leading-6 text-indigo-600 hover:text-indigo-500">
                <span aria-hidden="true">+</span> Ajouter une meta key
            </button>
        </div>

        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Enregistrer la sélection</button>
    </form>
</div>
