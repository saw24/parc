package org.parcit.mobile.data

import android.content.Context
import androidx.work.*
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.*
import java.util.UUID
import java.util.concurrent.TimeUnit

class Repository(private val ctx: Context, private val api: ApiClient, private val store: SecureStore) {
    val json = Json { ignoreUnknownKeys = true; coerceInputValues = true }

    private inline fun <reified T> JsonObject.data(): T = json.decodeFromJsonElement(this["data"]!!)

    // ------------------------------------------------------------------ Mode hors ligne (lecture)
    internal val cache = CacheLocal(ctx, json)
    val sync = SyncManager(this, api, store, cache, json, ctx)
    /** Message à montrer à l'utilisateur (ex. « enregistré hors ligne »). */
    var info: (String) -> Unit = {}
    /** Appelé avec null quand le serveur répond, ou avec l'heure des données affichées quand on retombe sur la copie locale. */
    var etatReseau: (Long?) -> Unit = {}

    /** Réseau d'abord ; sans réseau, dernière copie connue (si elle existe). Une vraie erreur du serveur reste une erreur. */
    private suspend fun <T> avecCache(cle: String, ser: kotlinx.serialization.KSerializer<T>, charger: suspend () -> T): T {
        try {
            val v = charger()
            cache.ecrire(cle, ser, v); etatReseau(null)
            return v
        } catch (e: ApiException) {
            if (!e.reseau) throw e
            val c = cache.lire(cle, ser) ?: throw e
            etatReseau(c.second)
            return c.first
        }
    }

    // ------------------------------------------------------------------ Session
    suspend fun login(server: String, login: String, password: String): Utilisateur {
        store.serverUrl = server
        cache.vider()
        val r = api.post("auth/login", buildJsonObject {
            put("login", login); put("password", password)
            putJsonObject("appareil") {
                put("nom", android.os.Build.MODEL ?: "Android"); put("plateforme", "android")
                put("version_app", org.parcit.mobile.BuildConfig.VERSION_NAME)
            }
        }, auth = false)
        val d = r["data"]!!.jsonObject
        store.refreshToken = d["refresh_token"]!!.jsonPrimitive.content
        store.accessToken = d["access_token"]!!.jsonPrimitive.content
        store.userJson = d["utilisateur"].toString()
        val u: Utilisateur = json.decodeFromJsonElement(d["utilisateur"]!!)
        // Les envois en attente appartiennent à un compte : jamais rejoués sous un autre.
        if (store.opsUserId != 0 && store.opsUserId != u.id) { sync.viderFile(); store.outboxJson = null }
        store.opsUserId = u.id; store.pannesSince = null
        planifierEnvoi(); SyncManager.planifier(ctx)
        return u
    }

    fun utilisateurLocal(): Utilisateur? = store.userJson?.let { runCatching { json.decodeFromString<Utilisateur>(it) }.getOrNull() }

    suspend fun rafraichirProfil(): Utilisateur {
        val u: Utilisateur = try { api.get("me").data() } catch (e: ApiException) {
            if (e.reseau) utilisateurLocal()?.let { return it }
            throw e
        }
        store.userJson = json.encodeToString(Utilisateur.serializer(), u)
        return u
    }

    suspend fun deconnecter() {
        runCatching { api.post("auth/logout", buildJsonObject { }) }
        store.clearSession(); cache.vider(); store.pannesSince = null; SyncManager.arreter(ctx)
    }

    // ------------------------------------------------------------------ Pannes
    suspend fun mesDeclarations(): List<Tache> = avecCache("mes_pannes", ListSerializer(Tache.serializer())) { mesDeclarationsReseau() }

    private suspend fun mesDeclarationsReseau(): List<Tache> {
        val out = mutableListOf<Tache>()
        var cursor: String? = null
        do {
            val q = mutableMapOf("declarant" to "moi", "limit" to "100")
            cursor?.let { q["cursor"] = it }
            val r = api.get("taches", q)
            out += r.data<List<Tache>>()
            cursor = r["meta"]?.jsonObject?.get("next_cursor")?.jsonPrimitive?.contentOrNull
        } while (cursor != null && out.size < 500)
        return out
    }

    suspend fun tache(id: Int): Tache = try {
        avecCache("tache_$id", Tache.serializer()) { api.get("taches/$id").data() }
    } catch (e: ApiException) {
        // Hors ligne sans fiche détaillée : l'entrée de la liste synchronisée (sans l'historique).
        if (e.reseau) tacheLocale(id)?.also { etatReseau(System.currentTimeMillis()) } ?: throw e else throw e
    }

    // ------------------------------------------------------------------ Modifications possibles hors ligne
    private val serListe = ListSerializer(Tache.serializer())

    private fun tacheLocale(id: Int): Tache? = cache.lire("tache_$id", Tache.serializer())?.first
        ?: listOf("pannes_toutes", "mes_pannes").firstNotNullOfOrNull { k -> cache.lire(k, serListe)?.first?.firstOrNull { it.id == id } }

    private fun majTacheCache(id: Int, f: (Tache) -> Tache) {
        cache.lire("tache_$id", Tache.serializer())?.first?.let { cache.ecrire("tache_$id", Tache.serializer(), f(it)) }
        for (cle in listOf("pannes_toutes", "mes_pannes")) cache.lire(cle, serListe)?.first?.let { l -> cache.ecrire(cle, serListe, l.map { if (it.id == id) f(it) else it }) }
    }

    private fun operation(type: String, tacheId: Int, libelle: String, payload: JsonObject = JsonObject(emptyMap())) =
        Operation(UUID.randomUUID().toString(), type, tacheId, "$libelle ${tacheLocale(tacheId)?.reference ?: "la panne"}", System.currentTimeMillis(), payload)

    /** Essaie en ligne ; sans réseau, met la modification en file (rejouée à la synchronisation) et l'applique à la copie locale. */
    private suspend fun action(op: Operation, optimiste: (Tache) -> Tache, appel: suspend () -> Unit): Tache {
        try { appel() } catch (e: ApiException) {
            if (!e.reseau) throw e
            sync.enfiler(op); majTacheCache(op.tacheId, optimiste)
            info("Enregistré hors ligne : sera envoyé à la prochaine synchronisation.")
            etatReseau(System.currentTimeMillis())
            return tacheLocale(op.tacheId) ?: throw e
        }
        return tache(op.tacheId)
    }

    suspend fun referentiels(): Referentiels = avecCache("referentiels", Referentiels.serializer()) { api.get("referentiels").data() }

    /** Le matériel que l'utilisateur peut voir ; liste vide si son rôle n'a pas accès au parc. */
    suspend fun mesMateriels(): List<Materiel> = avecCache("mes_materiels", ListSerializer(Materiel.serializer())) {
        try { api.get("materiel", mapOf("assigne" to "moi", "limit" to "100")).data<List<Materiel>>() }
        catch (e: ApiException) { if (e.status == 403) emptyList() else throw e }
    }

    suspend fun commenter(id: Int, texte: String): Tache = action(
        operation("commenter", id, "Commentaire sur", buildJsonObject { put("commentaire", texte) }), { it },
    ) { api.post("taches/$id/commentaires", buildJsonObject { put("commentaire", texte) }) }

    suspend fun annuler(t: Tache, motif: String): Tache = action(
        operation("annuler", t.id, "Annuler", buildJsonObject { put("statut", "Annulé"); put("commentaire", motif) }),
        { it.copy(statut = "Annulé") },
    ) { api.post("taches/${t.id}/statut", buildJsonObject { put("statut", "Annulé"); put("version", t.version); put("commentaire", motif) }) }

    /** Modification partielle d'une panne (seuls les champs fournis changent) ; la version protège contre les écrasements. */
    suspend fun modifierPanne(t: Tache, champs: JsonObject): Tache = action(
        operation("modifier", t.id, "Modifier", champs),
        { x -> x.copy(
            titre = champs["titre"]?.jsonPrimitive?.contentOrNull ?: x.titre,
            description = if (champs.containsKey("description")) champs["description"]?.jsonPrimitive?.contentOrNull else x.description,
            priorite = champs["priorite"]?.jsonPrimitive?.contentOrNull ?: x.priorite,
            motifCause = if (champs.containsKey("motif_cause")) champs["motif_cause"]?.jsonPrimitive?.contentOrNull else x.motifCause,
            dateLimite = if (champs.containsKey("date_limite")) champs["date_limite"]?.jsonPrimitive?.contentOrNull else x.dateLimite,
            actionsTexte = if (champs.containsKey("actions_texte")) champs["actions_texte"]?.jsonPrimitive?.contentOrNull else x.actionsTexte) },
    ) { api.patch("taches/${t.id}", JsonObject(champs + ("version" to JsonPrimitive(t.version)))) }

    suspend fun supprimerPanne(id: Int) { api.delete("taches/$id") }

    // ------------------------------------------------------------------ Administration
    suspend fun utilisateursAdmin(q: String): List<UtilisateurAdmin> =
        api.get("utilisateurs", buildMap { put("detail", "1"); put("inclure_inactifs", "1"); if (q.isNotBlank()) put("q", q.trim()) }).data()
    // Renouvellement du matériel (nécessite le réseau)
    suspend fun suggestionsRenouv(): List<SuggestionRenouv> = api.get("renouvellements/suggestions").data()
    suspend fun renouvellements(): List<Renouvellement> = api.get("renouvellements").data()
    suspend fun proposerRenouv(materielId: Int, cout: Double?, motif: String?) {
        api.post("renouvellements", buildJsonObject { put("materiel_id", materielId); cout?.let { put("cout_estime", it) }; if (!motif.isNullOrBlank()) put("motif", motif) })
    }
    suspend fun deciderRenouv(id: Int, decision: String, commentaire: String?) {
        api.post("renouvellements/$id/decision", buildJsonObject { put("decision", decision); if (!commentaire.isNullOrBlank()) put("commentaire", commentaire) })
    }
    suspend fun roles(): List<Role> = api.get("roles").data()
    suspend fun enregistrerUtilisateur(id: Int?, champs: JsonObject) { if (id == null) api.post("utilisateurs", champs) else api.patch("utilisateurs/$id", champs) }
    suspend fun supprimerUtilisateur(id: Int) { api.delete("utilisateurs/$id") }
    suspend fun servicesAdmin(): List<ServiceAdmin> = api.get("services").data()
    suspend fun enregistrerService(id: Int?, champs: JsonObject) { if (id == null) api.post("services", champs) else api.patch("services/$id", champs) }
    suspend fun supprimerService(id: Int) { api.delete("services/$id") }

    // ------------------------------------------------------------------ Interventions (agents IT)
    private suspend inline fun <reified T> pages(path: String, base: Map<String, String>, max: Int = 300): List<T> {
        val out = mutableListOf<T>()
        var cursor: String? = null
        do {
            val q = base.toMutableMap().apply { put("limit", "100"); cursor?.let { put("cursor", it) } }
            val r = api.get(path, q)
            out += r.data<List<T>>()
            cursor = r["meta"]?.jsonObject?.get("next_cursor")?.jsonPrimitive?.contentOrNull
        } while (cursor != null && out.size < max)
        return out
    }

    /** filtre : "file" (à prendre), "moi" (mes tâches ouvertes), "ouvertes" ou "toutes" (tous statuts). */
    /** [periode] (jour, semaine, mois, tout) ne s'applique qu'à « toutes » ; [agentId] à « ouvertes » et « toutes ». */
    suspend fun interventions(filtre: String, q: String, periode: String = "tout", agentId: Int? = null, recentDabord: Boolean = true): List<Tache> {
        val p = mutableMapOf<String, String>()
        when (filtre) {
            "file" -> { p["assigne"] = "file"; p["ouvertes"] = "1" }
            "moi" -> { p["assigne"] = "moi"; p["ouvertes"] = "1" }
            "ouvertes" -> { p["ouvertes"] = "1"; if (agentId != null) p["assigne"] = "$agentId" }
            else -> {   // « toutes » : tous les statuts, y compris terminées et annulées
                if (agentId != null) p["assigne"] = "$agentId"
                debutPeriode(periode)?.let { p["depuis"] = it.toString() }
            }
        }
        if (q.isNotBlank()) p["q"] = q.trim()
        return try {
            val liste: List<Tache> = pages("taches", p, max = 1000)
            etatReseau(null)
            if (recentDabord) liste else liste.reversed()
        } catch (e: ApiException) {
            // Hors ligne : mêmes filtres appliqués à la copie complète téléchargée par la synchronisation.
            if (!e.reseau) throw e
            interventionsLocales(filtre, q, periode, agentId, recentDabord) ?: throw e
        }
    }

    private fun debutPeriode(periode: String): java.time.LocalDate? {
        val aujourdhui = java.time.LocalDate.now()
        return when (periode) {
            "jour" -> aujourdhui
            "semaine" -> aujourdhui.with(java.time.temporal.TemporalAdjusters.previousOrSame(java.time.DayOfWeek.MONDAY))
            "mois" -> aujourdhui.withDayOfMonth(1)
            else -> null
        }
    }

    private fun interventionsLocales(filtre: String, q: String, periode: String, agentId: Int?, recentDabord: Boolean): List<Tache>? {
        val copie = cache.lire("pannes_toutes", serListe) ?: return null
        etatReseau(store.derniereSync.takeIf { it > 0 } ?: copie.second)
        val moi = utilisateurLocal()?.id
        val ouverte = { t: Tache -> t.statut != "Terminé" && t.statut != "Annulé" }
        var l = copie.first
        l = when (filtre) {
            "file" -> l.filter { it.enFile && ouverte(it) }
            "moi" -> l.filter { it.assigne?.id == moi && ouverte(it) }
            "ouvertes" -> l.filter(ouverte)
            else -> l
        }
        if (agentId != null && (filtre == "ouvertes" || filtre == "toutes")) l = l.filter { it.assigne?.id == agentId }
        if (filtre == "toutes") debutPeriode(periode)?.let { d -> l = l.filter { (it.dates.creation ?: "").take(10) >= d.toString() } }
        if (q.isNotBlank()) {
            val m = q.trim()
            l = l.filter { it.titre.contains(m, true) || (it.reference ?: "").contains(m, true) || (it.description ?: "").contains(m, true) }
        }
        l = l.sortedByDescending { it.updatedAt ?: it.dates.creation ?: "" }
        return if (recentDabord) l else l.reversed()
    }

    /** Envoie le jeton push de cet appareil au serveur (sans effet si Firebase n'est pas configuré). */
    suspend fun enregistrerPush(jeton: String? = null) {
        val t = jeton ?: org.parcit.mobile.Push.jeton(ctx) ?: return
        api.put("appareils/courant", buildJsonObject { put("fcm_token", t); put("version_app", org.parcit.mobile.BuildConfig.VERSION_NAME) })
    }

    suspend fun agents(): List<Agent> = avecCache("agents", ListSerializer(Agent.serializer())) { api.get("agents").data() }

    suspend fun affecter(t: Tache, assigneId: Int, commentaire: String?): Tache {
        val payload = buildJsonObject { put("assigne_id", assigneId); if (!commentaire.isNullOrBlank()) put("commentaire", commentaire) }
        val nom = cache.lire("agents", ListSerializer(Agent.serializer()))?.first?.firstOrNull { it.id == assigneId }?.nom
        return action(operation("affecter", t.id, "Affecter", payload), { it.copy(assigne = Ref(assigneId, nom), enFile = false) }) {
            api.post("taches/${t.id}/affecter", JsonObject(payload + ("version" to JsonPrimitive(t.version))))
        }
    }

    suspend fun changerStatut(t: Tache, statut: String, motif: String?, relance: String?, commentaire: String?): Tache {
        val payload = buildJsonObject {
            put("statut", statut)
            if (!motif.isNullOrBlank()) put("motif_attente", motif)
            if (!relance.isNullOrBlank()) put("date_relance", relance)
            if (!commentaire.isNullOrBlank()) put("commentaire", commentaire)
        }
        return action(operation("statut", t.id, "Passer à « $statut » :", payload), { it.copy(statut = statut) }) {
            api.post("taches/${t.id}/statut", JsonObject(payload + ("version" to JsonPrimitive(t.version))))
        }
    }

    suspend fun commenterEquipe(id: Int, texte: String, visibleDeclarant: Boolean): Tache {
        val payload = buildJsonObject { put("commentaire", texte); put("visible_declarant", visibleDeclarant) }
        return action(operation("commenter", id, "Commentaire sur", payload), { it }) { api.post("taches/$id/commentaires", payload) }
    }

    // ------------------------------------------------------------------ Parc
    suspend fun parc(q: String, statut: String?): List<Fiche> {
        val p = mutableMapOf<String, String>()
        if (!statut.isNullOrBlank()) p["statut"] = statut
        if (q.isNotBlank()) p["q"] = q.trim()
        val ser = ListSerializer(Fiche.serializer())
        return try {
            val liste: List<Fiche> = pages("materiel", p)
            if (statut.isNullOrBlank() && q.isBlank()) cache.ecrire("parc_tous", ser, liste)   // copie complète
            etatReseau(null)
            liste
        } catch (e: ApiException) {
            val c = (if (e.reseau) cache.lire("parc_tous", ser) else null) ?: throw e
            etatReseau(store.derniereSync.takeIf { it > 0 } ?: c.second)
            c.first
                .filter { statut.isNullOrBlank() || it.statut == statut }
                .filter { f -> q.isBlank() || listOfNotNull(f.libelle, f.marque, f.modele, f.numSerie, f.type).any { it.contains(q.trim(), true) } }
        }
    }

    suspend fun fiche(id: Int): Fiche = try {
        avecCache("fiche_$id", Fiche.serializer()) { api.get("materiel/$id").data() }
    } catch (e: ApiException) {
        // Fiche jamais ouverte en ligne : l'entrée de la liste du parc synchronisée.
        if (e.reseau) cache.lire("parc_tous", ListSerializer(Fiche.serializer()))?.first?.firstOrNull { it.id == id } ?: throw e else throw e
    }
    suspend fun affectations(id: Int): List<Affectation> = avecCache("affectations_$id", ListSerializer(Affectation.serializer())) { api.get("materiel/$id/affectations").data() }
    suspend fun pannesDuMateriel(id: Int): List<Tache> = avecCache("pannes_materiel_$id", ListSerializer(Tache.serializer())) { api.get("materiel/$id/pannes").data() }
    suspend fun scanner(code: String): Fiche = api.get("materiel/scan", mapOf("code" to code)).data()
    suspend fun utilisateurs(): List<UtilisateurRef> = api.get("utilisateurs").data()

    /** Création (id null) ou modification partielle ; les champs absents ne sont pas touchés. */
    suspend fun enregistrerMateriel(id: Int?, champs: JsonObject): Fiche =
        (if (id == null) api.post("materiel", champs) else api.patch("materiel/$id", champs)).data()

    // ------------------------------------------------------------------ Mise à jour de l'APK
    suspend fun config(): Config = api.get("config", auth = false).data()

    // ------------------------------------------------------------------ Notifications
    /** Les 50 dernières notifications, plus le nombre de non lues. */
    suspend fun notifications(): Pair<List<AppNotification>, Int> {
        var nb: Int? = null
        val liste = avecCache("notifications", ListSerializer(AppNotification.serializer())) {
            val r = api.get("notifications", mapOf("limit" to "50"))
            nb = r["meta"]?.jsonObject?.get("non_lues")?.jsonPrimitive?.intOrNull
            r.data<List<AppNotification>>()
        }
        return liste to (nb ?: liste.count { !it.lue })
    }

    suspend fun marquerLues(ids: List<Long>) {
        if (ids.isNotEmpty()) api.post("notifications/lues", buildJsonObject { putJsonArray("ids") { ids.forEach { add(it) } } })
    }

    suspend fun toutMarquerLu() { api.post("notifications/lues", buildJsonObject { put("toutes", true) }) }

    suspend fun preferences(email: Boolean, sms: Boolean): Utilisateur {
        val u: Utilisateur = api.patch("me", buildJsonObject { put("notif_email", email); put("notif_sms", sms) }).data()
        store.userJson = json.encodeToString(Utilisateur.serializer(), u)
        return u
    }

    /** « Je prends » : atomique côté serveur. Lève ApiException(409) si un collègue a été plus rapide. */
    suspend fun prendre(id: Int): Tache = action(
        operation("prendre", id, "Prendre en charge"),
        { it.copy(assigne = utilisateurLocal()?.let { u -> Ref(u.id, u.nom) }, enFile = false, actions = it.actions.copy(prendre = false)) },
    ) { api.post("taches/$id/prendre", buildJsonObject { }) }

    // ------------------------------------------------------------------ Envoi différé
    fun brouillons(): List<Brouillon> = store.outboxJson?.let {
        runCatching { json.decodeFromString(ListSerializer(Brouillon.serializer()), it) }.getOrNull()
    } ?: emptyList()

    private fun sauver(l: List<Brouillon>) { store.outboxJson = if (l.isEmpty()) null else json.encodeToString(ListSerializer(Brouillon.serializer()), l) }

    @Synchronized private fun maj(uuid: String, f: (Brouillon) -> Brouillon?) {
        sauver(brouillons().mapNotNull { if (it.uuid == uuid) f(it) else it })
    }

    fun supprimerBrouillon(uuid: String) = maj(uuid) { null }

    /** Enregistre la déclaration localement puis tente l'envoi : rien n'est perdu sans réseau. */
    suspend fun declarer(titre: String, description: String, priorite: String, serviceId: Int?, materielId: Int?): Boolean {
        val b = Brouillon(UUID.randomUUID().toString(), titre, description, priorite, serviceId, materielId)
        synchronized(this) { sauver(brouillons() + b) }
        return envoyer(b.uuid)
    }

    /** true = envoyée ; false = gardée pour plus tard (réseau). Lève ApiException si définitivement refusée. */
    suspend fun envoyer(uuid: String): Boolean {
        val b = brouillons().firstOrNull { it.uuid == uuid } ?: return true
        try {
            api.post("taches", buildJsonObject {
                put("client_uuid", b.uuid); put("titre", b.titre); put("description", b.description)
                put("priorite", b.priorite)
                b.serviceId?.let { put("service_id", it) }; b.materielId?.let { put("materiel_id", it) }
            })
            supprimerBrouillon(uuid)
            return true
        } catch (e: ApiException) {
            if (e.reseau) { planifierEnvoi(); return false }
            if (e.definitive) { maj(uuid) { it.copy(erreur = e.message) } }
            throw e
        }
    }

    /** Envoie tout ce qui attend ; renvoie le nombre de déclarations restantes. */
    suspend fun viderFile(): Int {
        for (b in brouillons().filter { it.erreur == null }) {
            try { envoyer(b.uuid) } catch (e: ApiException) { if (e.reseau || !e.definitive) return brouillons().size }
        }
        return brouillons().size
    }

    fun planifierEnvoi() {
        val req = OneTimeWorkRequestBuilder<EnvoiWorker>()
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS).build()
        WorkManager.getInstance(ctx).enqueueUniqueWork("envoi_declarations", ExistingWorkPolicy.KEEP, req)
    }
}

class EnvoiWorker(ctx: Context, p: WorkerParameters) : CoroutineWorker(ctx, p) {
    override suspend fun doWork(): Result {
        val app = applicationContext as org.parcit.mobile.ParcApp
        if (!app.store.loggedIn) return Result.success()
        return if (app.repo.viderFile() == 0 || app.repo.brouillons().all { it.erreur != null }) Result.success() else Result.retry()
    }
}
