import Alpine from 'alpinejs'
import indexation from './components/indexation'
import contentSelection from './components/content-selection'

// Enregistrement des composants Alpine
Alpine.data('indexation', indexation)
Alpine.data('contentSelection', contentSelection)

// Démarrage d'Alpine
window.Alpine = Alpine
Alpine.start()
