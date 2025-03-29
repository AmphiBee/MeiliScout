export default (config) => ({
    logs: config?.logs || [],
    isProcessing: false,
    timer: null,

    async fetchLogs() {
        try {
            const response = await fetch(config.restUrl, {
                headers: {
                    'X-WP-Nonce': config.restNonce
                }
            });
            const data = await response.json();
            this.logs = data;

            // Continuer le rafraîchissement si le statut est 'pending'
            if (data.status === 'pending') {
                this.timer = setTimeout(() => this.fetchLogs(), 500);
            } else {
                this.isProcessing = false;
            }
        } catch (error) {
            console.error('Erreur lors de la récupération des logs:', error);
        }
    },

    async startProcessing(event) {
        event.preventDefault();
        this.isProcessing = true;

        // Soumettre le formulaire via fetch
        const formData = new FormData(event.target);

        try {
            await fetch(config.adminPostUrl, {
                method: 'POST',
                body: formData
            });

            // Démarrer le suivi des logs
            this.fetchLogs();
        } catch (error) {
            console.error('Erreur lors de la soumission:', error);
            this.isProcessing = false;
        }

        return false;
    }
}); 