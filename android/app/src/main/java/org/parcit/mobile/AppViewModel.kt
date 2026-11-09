package org.parcit.mobile

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import org.parcit.mobile.data.*

sealed interface Ecran {
    data object Liste : Ecran
    data object Declarer : Ecran
    data object Notifications : Ecran
    data class Detail(val id: Int) : Ecran
}

class AppViewModel(private val app: ParcApp) : ViewModel() {
    private val repo = app.repo

    var utilisateur by mutableStateOf(repo.utilisateurLocal().takeIf { app.store.loggedIn })
    var ecran by mutableStateOf<Ecran>(Ecran.Liste)
    var chargement by mutableStateOf(false)
    var message by mutableStateOf<String?>(null)
    var taches by mutableStateOf<List<Tache>>(emptyList())
    var brouillons by mutableStateOf(repo.brouillons())
    var detail by mutableStateOf<Tache?>(null)
    var referentiels by mutableStateOf(Referentiels())
    var materiels by mutableStateOf<List<Materiel>>(emptyList())
    var serveur by mutableStateOf(app.store.serverUrl)
    var notifications by mutableStateOf<List<AppNotification>>(emptyList())
    var nonLues by mutableStateOf(0)
    /** Pop-up affiché quand une nouvelle notification arrive pendant que l'application est ouverte. */
    var alerte by mutableStateOf<AppNotification?>(null)
    private var scrutation: Job? = null

    init {
        app.api.onSessionLost = { utilisateur = null; taches = emptyList(); message = "Session expirée, reconnectez-vous." }
    }

    private fun erreur(e: Throwable) {
        message = if (e is ApiException) when (e.code) {
            "app_obsolete" -> "Cette version de l'application n'est plus supportée. Mettez-la à jour."
            else -> e.message
        } else "Erreur inattendue."
    }

    private fun lancer(chargeur: Boolean = true, bloc: suspend () -> Unit) = viewModelScope.launch {
        if (chargeur) chargement = true
        try { bloc() } catch (e: Throwable) { erreur(e) } finally { if (chargeur) chargement = false }
    }

    /** Tant que l'application est visible, on interroge le serveur toutes les 30 s. */
    fun enAvantPlan(oui: Boolean) {
        scrutation?.cancel()
        if (!oui) return
        scrutation = viewModelScope.launch {
            while (isActive) {
                if (utilisateur != null) try { rafraichirNotifications() } catch (e: Throwable) { /* silencieux : réessayé au tour suivant */ }
                delay(30_000)
            }
        }
    }

    private suspend fun rafraichirNotifications() {
        val (liste, nb) = repo.notifications()
        notifications = liste; nonLues = nb
        val neuves = liste.filter { !it.lue && it.id > app.store.dernierNotifId }
        if (neuves.isNotEmpty()) {
            alerte = neuves.maxBy { it.id }
            // Les panneaux de la liste (état d'une panne) se mettent à jour avec la nouveauté.
            if (ecran == Ecran.Liste) taches = repo.mesDeclarations()
        }
        liste.maxOfOrNull { it.id }?.let { if (it > app.store.dernierNotifId) app.store.dernierNotifId = it }
    }

    fun ouvrirNotifications() { ecran = Ecran.Notifications; lancer(chargeur = false) { rafraichirNotifications() } }
    fun fermerAlerte() { alerte = null }

    fun ouvrirNotification(n: AppNotification) {
        alerte = null
        if (!n.lue) lancer(chargeur = false) { repo.marquerLues(listOf(n.id)); rafraichirNotifications() }
        ouvrirDetail(n.tache.id)
    }

    fun toutMarquerLu() = lancer(chargeur = false) { repo.toutMarquerLu(); rafraichirNotifications() }

    fun preferences(email: Boolean, sms: Boolean) = lancer(chargeur = false) { utilisateur = repo.preferences(email, sms) }

    /** « Je prends » : message clair si un collègue a été plus rapide. */
    fun prendre(id: Int) = lancer {
        try {
            val t = repo.prendre(id)
            message = "Vous avez pris en charge ${t.reference ?: "la panne"}."
            if (detail?.id == id) detail = t
        } finally {
            alerte = null
            runCatching { rafraichirNotifications() }
        }
    }

    fun connecter(serveur: String, login: String, mdp: String) = lancer {
        utilisateur = repo.login(serveur, login.trim(), mdp)
        Alertes.planifierScrutation(app)
        this.serveur = app.store.serverUrl
        ecran = Ecran.Liste
        actualiser()
    }

    fun deconnecter() = lancer {
        Alertes.arreterScrutation(app); repo.deconnecter(); notifications = emptyList(); nonLues = 0; utilisateur = null; taches = emptyList(); detail = null; ecran = Ecran.Liste
    }

    /** Recharge la liste et profite du réseau pour vider les déclarations en attente. */
    fun actualiser() {
        if (utilisateur == null) return
        lancer(chargeur = taches.isEmpty()) {
            repo.viderFile(); brouillons = repo.brouillons()
            utilisateur = repo.rafraichirProfil()
            taches = repo.mesDeclarations()
        }
    }

    fun ouvrirDetail(id: Int) { ecran = Ecran.Detail(id); detail = taches.firstOrNull { it.id == id }
        lancer(chargeur = detail == null) { detail = repo.tache(id) } }

    fun ouvrirFormulaire() {
        ecran = Ecran.Declarer
        lancer(chargeur = false) { referentiels = repo.referentiels(); materiels = repo.mesMateriels() }
    }

    fun retour() { ecran = Ecran.Liste; detail = null; actualiser() }
    fun retourNotifications() { ecran = Ecran.Notifications; detail = null }

    fun declarer(titre: String, description: String, priorite: String, serviceId: Int?, materielId: Int?) = lancer {
        val envoyee = repo.declarer(titre, description, priorite, serviceId, materielId)
        brouillons = repo.brouillons()
        message = if (envoyee) "Panne déclarée." else "Pas de réseau : la déclaration sera envoyée automatiquement."
        ecran = Ecran.Liste
        if (envoyee) taches = repo.mesDeclarations()
    }

    fun reessayer(uuid: String) = lancer {
        try { repo.envoyer(uuid) } finally { brouillons = repo.brouillons() }
        taches = repo.mesDeclarations()
    }

    fun abandonner(uuid: String) { repo.supprimerBrouillon(uuid); brouillons = repo.brouillons() }

    fun commenter(texte: String) { val t = detail ?: return; lancer { detail = repo.commenter(t.id, texte) } }
    fun annuler(motif: String) { val t = detail ?: return; lancer { detail = repo.annuler(t, motif); message = "Panne annulée." } }
    fun messageLu() { message = null }
}
