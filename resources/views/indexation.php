<?php
// Préparation des données pour le JavaScript
$indexationData = [
    'logs' => $last_log ?? [],
    'restUrl' => rest_url('meiliscout/v1/indexation-status'),
    'restNonce' => wp_create_nonce('wp_rest'),
    'adminPostUrl' => admin_url('admin-post.php'),
];
?>

<div class="p-6 bg-white rounded-lg shadow-md mt-8 mr-6" 
     x-data="indexation(<?php echo htmlspecialchars(json_encode($indexationData), ENT_QUOTES, 'UTF-8'); ?>)">
    <h1 class="text-3xl font-bold mb-4 text-gray-800">Indexation MeiliSearch</h1>

    <p class="mb-6 text-gray-600">
        Cette page vous permet de synchroniser vos contenus avec MeiliSearch.
        L'indexation concernera tous les types de contenu et taxonomies sélectionnés dans la page "Sélection de Contenu".
    </p>

    <form method="post" @submit.prevent="startProcessing">
        <?php wp_nonce_field('meiliscout_indexation_action', 'meiliscout_indexation_nonce'); ?>
        <input type="hidden" name="action" value="meiliscout_indexation">

        <div class="mb-6">
            <h2 class="text-lg font-semibold mb-4">Options d'indexation</h2>

            <div class="flex items-center mb-4">
                <input type="checkbox" name="clear_indices" id="clear_indices" class="mr-2">
                <label for="clear_indices" class="text-gray-700">
                    Vider les indices avant l'indexation
                </label>
            </div>

            <div class="flex items-center mb-4">
                <input type="checkbox" name="index_posts" id="index_posts" class="mr-2" checked>
                <label for="index_posts" class="text-gray-700">
                    Indexer les contenus
                </label>
            </div>

            <div class="flex items-center mb-4">
                <input type="checkbox" name="index_taxonomies" id="index_taxonomies" class="mr-2" checked>
                <label for="index_taxonomies" class="text-gray-700">
                    Indexer les taxonomies
                </label>
            </div>
        </div>

        <button type="submit"
                class="inline-flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700"
                :disabled="isProcessing">
            <template x-if="!isProcessing">
                <span>Lancer l'indexation</span>
            </template>
            <template x-if="isProcessing">
                <span>Indexation en cours...</span>
            </template>
        </button>
    </form>

    <!-- Zone de logs -->
    <div class="mt-8" x-cloak x-show="logs?.entries?.length > 0">
        <h3 class="text-lg font-semibold mb-4">Logs d'indexation</h3>
        <div class="bg-gray-100 p-4 rounded-lg max-h-96 overflow-y-auto">
            <template x-for="(entry, index) in logs.entries" :key="index">
                <div :class="{
                    'mb-2': true,
                    'text-green-600': entry.type === 'success',
                    'text-blue-600': entry.type === 'info',
                    'text-red-600': entry.type === 'error'
                }">
                    <span x-text="entry.time"></span> - 
                    <span x-text="entry.message"></span>
                </div>
            </template>
        </div>
    </div>
</div>
