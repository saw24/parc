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
import kotlinx.serialization.json.put
import org.parcit.mobile.data.*

sealed interface Ecran {
    data object Liste : Ecran
    data object Declarer : Ecran
    data object Notifications : Ecran
    data object Interventions : Ecran
    data object Parc : Ecran
    data object Scan : Ecran
    data class Detail(val id: Int) : Ecran
    data class MaterielDetail(val id: Int) : Ecran
    data class MaterielForm(val id: Int?) : Ecran
    data class PanneForm(val id: Int) : Ecran
    data object Utilisateurs : Ecran
    data class UtilisateurForm(val id: Int?) : Ecran
    data object Services : Ecran
    data object Sync : Ecran
    data object Renouvellement : Ecran
}

/** Écrans principaux (barre du bas) : on y revient avec « Retour ». */
val Ecran.principal get() = this is Ecran.Liste || this is Ecran.Interventions || this is Ecran.Parc || this is Ecran.Notifications

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
    var sombre by mutableStateOf(app.store.themeSombre)
    var notifications by mutableStateOf<List<AppNotification>>(emptyList())
    var nonLues by mutableStateOf(0)
    /** Pop-up affiché quand une nouvelle notification arrive pendant que l'application est ouverte. */
    var alerte by mutableStateOf<AppNotification?>(null)
    private var scrutation: Job? = null
    /** Non nul quand on consulte la copie locale faute de réseau : heure (ms) des données affichées. */
    var horsLigne by mutableStateOf<Long?>(null)
    /** Réseau disponible sur l'appareil (le basculement en ligne / hors ligne est automatique). */
    var reseau by mutableStateOf(true)
    var sync by mutableStateOf(SyncEtat())

    // Vue agent IT
    var filtre by mutableStateOf("file")
    var periode by mutableStateOf("semaine")        // jour | semaine | mois | tout (vue « Toutes » seulement)
    var agentFiltre by mutableStateOf<Int?>(null)    // « pris en charge par »
    var recentDabord by mutableStateOf(true)
    private var pushEnregistre = false
    var recherche by mutableStateOf("")
    var interventions by mutableStateOf<List<Tache>>(emptyList())
    var agents by mutableStateOf<List<Agent>>(emptyList())
    var origine: Ecran = Ecran.Liste
    /** Matériel présélectionné quand on déclare une panne depuis sa fiche. */
    var materielPrefill by mutableStateOf<Materiel?>(null)

    // Parc
    var rechercheParc by mutableStateOf("")
    var statutParc by mutableStateOf<String?>(null)
    var parc by mutableStateOf<List<Fiche>>(emptyList())
    var fiche by mutableStateOf<Fiche?>(null)
    var affectations by mutableStateOf<List<Affectation>>(emptyList())
    var pannesMateriel by mutableStateOf<List<Tache>>(emptyList())
    var utilisateursRef by mutableStateOf<List<UtilisateurRef>>(emptyList())

    // Administration
    var utilisateursAdmin by mutableStateOf<List<UtilisateurAdmin>>(emptyList())
    var rechercheUtilisateurs by mutableStateOf("")
    var roles by mutableStateOf<List<Role>>(emptyList())
    var servicesAdmin by mutableStateOf<List<ServiceAdmin>>(emptyList())

    /** Nouvelle version de l'APK disponible (distribution directe : le téléchargement se fait par le lien du serveur). */
    var miseAJour by mutableStateOf<Config?>(null)

    init {
        app.repo.etatReseau = { horsLigne = it }
        app.repo.info = { message = it }
        viewModelScope.launch { app.repo.sync.etat.collect { sync = it } }
        runCatching {
            val cm = app.getSystemService(android.net.ConnectivityManager::class.java)
            reseau = cm.activeNetwork != null
            cm.registerDefaultNetworkCallback(object : android.net.ConnectivityManager.NetworkCallback() {
                override fun onAvailable(network: android.net.Network) { viewModelScope.launch { reseau = true; if (utilisateur != null) synchroniser() } }
                override fun onLost(network: android.net.Network) { viewModelScope.launch { reseau = cm.activeNetwork != null } }
            })
        }
        verifierMiseAJour()
        app.api.onSessionLost = { utilisateur = null; taches = emptyList(); message = "Session expirée, reconnectez-vous." }
    }

    private fun erreur(e: Throwable) {
        message = if (e is ApiException) when (e.code) {
            "app_obsolete" -> "Cette version de l'application n'est plus supportée. Mettez-la à jour."
            "reseau" -> "Pas de réseau : cette action nécessite une connexion."
            else -> e.message
        } else "Erreur inattendue."
    }

    /** Synchronisation dans les deux sens, en tâche de fond (l'interface reste utilisable) ; recharge l'écran ensuite. */
    fun synchroniser() {
        if (utilisateur == null) return
        viewModelScope.launch { if (app.repo.sync.synchroniser()) reessayer() }
    }
    fun operationsEnAttente() = app.repo.sync.operations()
    fun abandonnerOperation(id: String) = app.repo.sync.abandonner(id)
    fun reessayerOperation(id: String) { app.repo.sync.reessayer(id); synchroniser() }

    /** Recharge l'écran courant (retour du réseau, ou bouton « Réessayer » du bandeau hors ligne). */
    fun reessayer() {
        actualiser()
        when (val e = ecran) {
            Ecran.Interventions -> chargerInterventions(avecBarre = true)
            Ecran.Parc -> chargerParc()
            Ecran.Notifications -> ouvrirNotifications()
            is Ecran.Detail -> lancer(chargeur = false) { detail = repo.tache(e.id) }
            else -> {}
        }
    }

    private fun lancer(chargeur: Boolean = true, bloc: suspend () -> Unit) = viewModelScope.launch {
        if (chargeur) chargement = true
        try { bloc() } catch (e: Throwable) { erreur(e) } finally { if (chargeur) chargement = false }
    }

    /** Tant que l'application est visible, on interroge le serveur toutes les 30 s. */
    fun enAvantPlan(oui: Boolean) {
        scrutation?.cancel()
        if (!oui) return
        if (utilisateur != null && System.currentTimeMillis() - (sync.derniere ?: 0L) > 300_000) synchroniser()
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
            // Elle apparaît tout de suite dans « mes pannes assignées » (et quitte la file à prendre).
            filtre = "moi"
            runCatching { interventions = repo.interventions("moi", recherche) }
        } finally {
            alerte = null
            runCatching { rafraichirNotifications() }
        }
    }

    suspend fun testerServeur(adresse: String): Pair<Boolean, String> = app.api.tester(adresse)

    fun connecter(serveur: String, login: String, mdp: String) = lancer {
        utilisateur = repo.login(serveur, login.trim(), mdp)
        runCatching { repo.enregistrerPush() }
        synchroniser()
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
            if (!pushEnregistre) { pushEnregistre = true; runCatching { repo.enregistrerPush() } }
            repo.viderFile(); brouillons = repo.brouillons()
            utilisateur = repo.rafraichirProfil()
            taches = repo.mesDeclarations()
        }
    }

    fun ouvrirDetail(id: Int) { if (ecran.principal) origine = ecran; ecran = Ecran.Detail(id); detail = taches.firstOrNull { it.id == id }
        lancer(chargeur = detail == null) { detail = repo.tache(id) } }

    fun ouvrirFormulaire(prefill: Materiel? = null) {
        materielPrefill = prefill
        if (prefill == null) origine = Ecran.Liste
        ecran = Ecran.Declarer
        lancer(chargeur = false) { referentiels = repo.referentiels(); materiels = repo.mesMateriels() }
    }

    fun retour() {
        val e = ecran
        if (e is Ecran.PanneForm) { ecran = Ecran.Detail(e.id); return }
        detail = null
        ecran = when {
            e is Ecran.UtilisateurForm -> Ecran.Utilisateurs
            e is Ecran.MaterielForm && e.id != null -> Ecran.MaterielDetail(e.id)
            e is Ecran.Scan || e is Ecran.MaterielForm || e is Ecran.MaterielDetail -> Ecran.Parc
            e is Ecran.Detail || e is Ecran.Declarer || e is Ecran.Sync || e is Ecran.Renouvellement -> origine
            else -> Ecran.Liste
        }
        recharger()
    }

    private fun recharger() {
        when (ecran) {
            Ecran.Interventions -> chargerInterventions(); Ecran.Parc -> chargerParc(); Ecran.Liste -> actualiser()
            Ecran.Utilisateurs -> chargerUtilisateurs(); Ecran.Services -> chargerServices(); Ecran.Renouvellement -> chargerRenouv(); else -> {}
        }
    }

    /** L'agent IT voit les actions d'équipe (note interne, affectation, statut) sur les pannes qu'il peut gérer. */
    fun estEquipe(t: Tache) = utilisateur?.voitInterventions == true || t.actions.changerStatut || t.actions.affecter

    fun aller(e: Ecran) {
        if (e == Ecran.Sync || e == Ecran.Renouvellement) origine = if (ecran.principal) ecran else Ecran.Liste
        ecran = e; detail = null
        when (e) { Ecran.Interventions -> chargerInterventions(); Ecran.Parc -> chargerParc(); Ecran.Liste -> actualiser(); Ecran.Notifications -> ouvrirNotifications(); Ecran.Utilisateurs -> chargerUtilisateurs(); Ecran.Services -> chargerServices(); Ecran.Renouvellement -> chargerRenouv(); else -> {} }
    }

    // ------------------------------------------------------------------ Interventions
    fun chargerInterventions(avecBarre: Boolean = false) = lancer(chargeur = avecBarre || interventions.isEmpty()) { interventions = repo.interventions(filtre, recherche, periode, agentFiltre, recentDabord) }
    fun appliquerFiltres(periode: String, agent: Int?, recent: Boolean) { this.periode = periode; agentFiltre = agent; recentDabord = recent; chargerInterventions(avecBarre = true) }
    fun choisirFiltre(f: String) { filtre = f; chargerInterventions(avecBarre = true) }
    fun rechercherInterventions(q: String) { recherche = q; chargerInterventions() }

    fun chargerAgents() = lancer(chargeur = false) { agents = repo.agents() }

    private fun majDetail(bloc: suspend () -> Tache) = lancer { detail = bloc() }

    fun affecter(agentId: Int, commentaire: String?) { val t = detail ?: return
        majDetail { repo.affecter(t, agentId, commentaire).also { message = "Panne affectée." } } }

    fun changerStatut(statut: String, motif: String?, relance: String?, commentaire: String?) { val t = detail ?: return
        majDetail { repo.changerStatut(t, statut, motif, relance, commentaire).also { message = "Statut mis à jour." } } }

    fun commenterEquipe(texte: String, visibleDeclarant: Boolean) { val t = detail ?: return
        majDetail { repo.commenterEquipe(t.id, texte, visibleDeclarant) } }

    // ------------------------------------------------------------------ Renouvellement du matériel
    var suggestionsRenouv by mutableStateOf<List<SuggestionRenouv>>(emptyList())
    fun chargerRenouv() = lancer { suggestionsRenouv = repo.suggestionsRenouv() }

    // ------------------------------------------------------------------ Parc
    fun chargerParc() = lancer(chargeur = parc.isEmpty()) { parc = repo.parc(rechercheParc, statutParc) }
    fun rechercherParc(q: String, statut: String?) { rechercheParc = q; statutParc = statut; chargerParc() }

    fun ouvrirFiche(id: Int) {
        ecran = Ecran.MaterielDetail(id); fiche = parc.firstOrNull { it.id == id }; affectations = emptyList(); pannesMateriel = emptyList()
        lancer(chargeur = fiche == null) {
            fiche = repo.fiche(id); affectations = repo.affectations(id); pannesMateriel = repo.pannesDuMateriel(id)
        }
    }

    fun ouvrirScan() { ecran = Ecran.Scan }

    /** Résultat d'un scan d'étiquette QR (ou saisie d'un numéro de série). */
    fun codeScanne(code: String) = lancer {
        try { ouvrirFiche(repo.scanner(code).id) } catch (e: ApiException) {
            if (e.status == 404) { message = "Aucun matériel ne correspond à ce code."; ecran = Ecran.Parc } else throw e
        }
    }

    var dernierTexteLu by mutableStateOf("")
    var prefillEtiquette by mutableStateOf<InfosEtiquette?>(null)

    /** Cherche le matériel décrit par une étiquette : série exacte, puis série partielle, modèle, marque. */
    fun chercherParEtiquette(i: InfosEtiquette) = lancer {
        if (i.numSerie.length >= 4) {
            try { ouvrirFiche(repo.scanner(i.numSerie).id); return@lancer } catch (e: ApiException) { if (e.status != 404) throw e }
        }
        for (q in listOf(i.numSerie, i.modele, i.marque).filter { it.length >= 3 }) {
            val liste = repo.parc(q, null)
            if (liste.size == 1) { ouvrirFiche(liste[0].id); return@lancer }
            if (liste.isNotEmpty()) { ecran = Ecran.Parc; rechercheParc = q; statutParc = null; parc = liste; message = "${liste.size} matériel(s) correspondent à « $q »."; return@lancer }
        }
        message = "Aucun matériel du parc ne correspond. Vous pouvez le créer depuis cette lecture."
    }
    fun creerDepuisEtiquette(i: InfosEtiquette) { prefillEtiquette = i; ouvrirFormulaireMateriel(null, i) }

    fun ouvrirFormulaireMateriel(id: Int?, prefill: InfosEtiquette? = null) {
        prefillEtiquette = prefill
        ecran = Ecran.MaterielForm(id)
        lancer(chargeur = false) {
            referentiels = repo.referentiels()
            if (utilisateur?.peut("utilisateur.lister") == true || utilisateur?.gereParc == true) utilisateursRef = repo.utilisateurs()
        }
    }

    fun enregistrerMateriel(id: Int?, champs: kotlinx.serialization.json.JsonObject) = lancer {
        val f = repo.enregistrerMateriel(id, champs)
        message = if (id == null) "Matériel ajouté." else "Matériel mis à jour."
        parc = emptyList()
        ouvrirFiche(f.id)
    }

    // ------------------------------------------------------------------ Modification / suppression d'une panne
    fun ouvrirFormPanne() {
        val t = detail ?: return
        ecran = Ecran.PanneForm(t.id)
        lancer(chargeur = false) { referentiels = repo.referentiels(); materiels = repo.mesMateriels(); agents = repo.agents() }
    }

    fun modifierPanne(champs: kotlinx.serialization.json.JsonObject) { val t = detail ?: return
        lancer {
            detail = repo.modifierPanne(t, champs); message = "Panne modifiée."
            ecran = Ecran.Detail(t.id)
        } }

    /** Édition complète (équipe) : champs, puis statut, puis affectation, chacun avec la version à jour. */
    fun modifierPanneComplet(champs: kotlinx.serialization.json.JsonObject, statut: String?, motif: String?, relance: String?, assigneId: Int?) { val t0 = detail ?: return
        lancer {
            var t = t0
            if (champs.isNotEmpty()) t = repo.modifierPanne(t, champs)
            if (statut != null) t = repo.changerStatut(t, statut, motif, relance, null)
            if (assigneId != null) t = repo.affecter(t, assigneId, null)
            detail = t; message = "Panne modifiée."
            ecran = Ecran.Detail(t0.id)
        } }

    fun supprimerPanne() { val t = detail ?: return
        lancer {
            repo.supprimerPanne(t.id); message = "Panne supprimée."
            detail = null; taches = taches.filter { it.id != t.id }; interventions = interventions.filter { it.id != t.id }
            ecran = origine; recharger()
        } }

    // ------------------------------------------------------------------ Administration (utilisateurs, services)
    fun ouvrirUtilisateurs() { ecran = Ecran.Utilisateurs; detail = null; chargerUtilisateurs() }
    fun chargerUtilisateurs() = lancer(chargeur = utilisateursAdmin.isEmpty()) { utilisateursAdmin = repo.utilisateursAdmin(rechercheUtilisateurs) }
    fun rechercherUtilisateurs(q: String) { rechercheUtilisateurs = q; chargerUtilisateurs() }

    fun ouvrirFormUtilisateur(id: Int?) {
        ecran = Ecran.UtilisateurForm(id)
        lancer(chargeur = false) { roles = repo.roles(); referentiels = repo.referentiels() }
    }

    fun enregistrerUtilisateur(id: Int?, champs: kotlinx.serialization.json.JsonObject) = lancer {
        repo.enregistrerUtilisateur(id, champs)
        message = if (id == null) "Utilisateur créé." else "Utilisateur mis à jour."
        ecran = Ecran.Utilisateurs
        utilisateursAdmin = repo.utilisateursAdmin(rechercheUtilisateurs)
        if (id == utilisateur?.id) utilisateur = repo.rafraichirProfil()
    }

    fun supprimerUtilisateur(id: Int) = lancer {
        repo.supprimerUtilisateur(id); message = "Utilisateur supprimé."
        ecran = Ecran.Utilisateurs
        utilisateursAdmin = repo.utilisateursAdmin(rechercheUtilisateurs)
    }

    fun ouvrirServices() { ecran = Ecran.Services; detail = null; chargerServices() }
    fun chargerServices() = lancer(chargeur = servicesAdmin.isEmpty()) { servicesAdmin = repo.servicesAdmin() }

    fun enregistrerService(id: Int?, nom: String, description: String) = lancer {
        repo.enregistrerService(id, kotlinx.serialization.json.buildJsonObject {
            put("nom", nom.trim()); put("description", description.trim().ifBlank { null })
        })
        message = if (id == null) "Service créé." else "Service mis à jour."
        servicesAdmin = repo.servicesAdmin(); referentiels = repo.referentiels()
    }

    fun supprimerService(id: Int) = lancer {
        repo.supprimerService(id); message = "Service supprimé."
        servicesAdmin = repo.servicesAdmin(); referentiels = repo.referentiels()
    }

    // ------------------------------------------------------------------ Mise à jour de l'application
    fun verifierMiseAJour() {
        viewModelScope.launch {
            runCatching { repo.config() }.onSuccess { c ->
                miseAJour = c.takeIf { it.versionCourante.isNotBlank() && versionInferieure(org.parcit.mobile.BuildConfig.VERSION_NAME, it.versionCourante) }
            }
        }
    }

    private fun versionInferieure(a: String, b: String): Boolean {
        val x = a.split('.').map { it.toIntOrNull() ?: 0 }; val y = b.split('.').map { it.toIntOrNull() ?: 0 }
        for (i in 0 until maxOf(x.size, y.size)) { val d = (x.getOrElse(i) { 0 }) - (y.getOrElse(i) { 0 }); if (d != 0) return d < 0 }
        return false
    }


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
    fun basculerTheme() { sombre = !sombre; app.store.themeSombre = sombre }
    fun messageLu() { message = null }
}
