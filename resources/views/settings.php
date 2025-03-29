<?php
use Pollora\MeiliScout\Config\Config;

?>

<div class="p-6 bg-white rounded-lg shadow-md mt-8 mr-6">
    <h1 class="text-3xl font-bold mb-4 text-gray-800">Réglages de MeiliScout</h1>
    <p class="mb-6 text-gray-600">
        Configurez les paramètres de connexion à votre instance MeiliSearch. Assurez-vous que les informations saisies sont correctes pour garantir le bon fonctionnement de la recherche sur votre site.
    </p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('meiliscout_settings_save', 'meiliscout_settings_nonce'); ?>
        <input type="hidden" name="action" value="meiliscout_settings">

        <div class="mb-4">
            <label for="meili_host" class="block text-sm font-medium text-gray-700">MeiliSearch Host</label>
            <input type="text" name="meili_host" id="meili_host" value="<?php echo esc_attr($host); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2" required <?php echo Config::isReadOnly('MEILI_HOST') ? 'readonly' : ''; ?>>
            <p class="mt-1 text-xs text-gray-500">Entrez l'URL de votre serveur MeiliSearch. Par exemple : <code>http://localhost:7700</code>.</p>
        </div>

        <div class="mb-4">
            <label for="meili_key" class="block text-sm font-medium text-gray-700">MeiliSearch Key</label>
            <input type="text" name="meili_key" id="meili_key" value="<?php echo esc_attr($key); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2" required <?php echo Config::isReadOnly('MEILI_KEY') ? 'readonly' : ''; ?>>
            <p class="mt-1 text-xs text-gray-500">Entrez la clé d'API pour accéder à votre instance MeiliSearch. Si aucune clé n'est définie, laissez ce champ vide.</p>
        </div>

        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Enregistrer les réglages</button>
    </form>
</div>
