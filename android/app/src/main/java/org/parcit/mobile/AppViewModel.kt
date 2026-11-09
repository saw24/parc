package org.parcit.mobile

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import org.parcit.mobile.data.*

sealed interface Ecran {
    data object Liste : Ecran
    data object Declarer : Ecran
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

    fun connecter(serveur: String, login: String, mdp: String) = lancer {
        utilisateur = repo.login(serveur, login.trim(), mdp)
        this.serveur = app.store.serverUrl
        ecran = Ecran.Liste
        actualiser()
    }

    fun deconnecter() = lancer {
        repo.deconnecter(); utilisateur = null; taches = emptyList(); detail = null; ecran = Ecran.Liste
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
