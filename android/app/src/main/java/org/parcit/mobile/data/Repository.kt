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
    private val cache = CacheLocal(ctx, json)
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
        planifierEnvoi()
        return json.decodeFromJsonElement(d["utilisateur"]!!)
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
        store.clearSession(); cache.vider()
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

    suspend fun tache(id: Int): Tache = avecCache("tache_$id", Tache.serializer()) { api.get("taches/$id").data() }

    suspend fun referentiels(): Referentiels = avecCache("referentiels", Referentiels.serializer()) { api.get("referentiels").data() }

    /** Le matériel que l'utilisateur peut voir ; liste vide si son rôle n'a pas accès au parc. */
    suspend fun mesMateriels(): List<Materiel> = avecCache("mes_materiels", ListSerializer(Materiel.serializer())) {
        try { api.get("materiel", mapOf("assigne" to "moi", "limit" to "100")).data<List<Materiel>>() }
        catch (e: ApiException) { if (e.status == 403) emptyList() else throw e }
    }

    suspend fun commenter(id: Int, texte: String): Tache {
        api.post("taches/$id/commentaires", buildJsonObject { put("commentaire", texte) })
        return tache(id)
    }

    suspend fun annuler(t: Tache, motif: String): Tache {
        api.post("taches/${t.id}/statut", buildJsonObject {
            put("statut", "Annulé"); put("version", t.version); put("commentaire", motif)
        })
        return tache(t.id)
    }

    /** Modification partielle d'une panne (seuls les champs fournis changent) ; la version protège contre les écrasements. */
    suspend fun modifierPanne(t: Tache, champs: JsonObject): Tache {
        api.patch("taches/${t.id}", JsonObject(champs + ("version" to JsonPrimitive(t.version))))
        return tache(t.id)
    }

    suspend fun supprimerPanne(id: Int) { api.delete("taches/$id") }

    // ------------------------------------------------------------------ Administration
    suspend fun utilisateursAdmin(q: String): List<UtilisateurAdmin> =
        api.get("utilisateurs", buildMap { put("detail", "1"); put("inclure_inactifs", "1"); if (q.isNotBlank()) put("q", q.trim()) }).data()
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
                val aujourdhui = java.time.LocalDate.now()
                when (periode) {
                    "jour" -> p["depuis"] = aujourdhui.toString()
                    "semaine" -> p["depuis"] = aujourdhui.with(java.time.temporal.TemporalAdjusters.previousOrSame(java.time.DayOfWeek.MONDAY)).toString()
                    "mois" -> p["depuis"] = aujourdhui.withDayOfMonth(1).toString()
                }
            }
        }
        val cle = "interv_${filtre}_${if (filtre == "toutes") periode else "x"}_${if (filtre == "ouvertes" || filtre == "toutes") agentId ?: 0 else 0}"
        val ser = ListSerializer(Tache.serializer())
        val liste: List<Tache> = if (q.isBlank()) avecCache(cle, ser) { pages<Tache>("taches", p, max = 1000) }
        else try { pages<Tache>("taches", p + ("q" to q.trim()), max = 1000) } catch (e: ApiException) {
            // Hors ligne : recherche dans la dernière copie de la même vue.
            val c = if (e.reseau) cache.lire(cle, ser) else null
            if (c == null) throw e
            etatReseau(c.second)
            c.first.filter { it.titre.contains(q.trim(), true) || (it.reference ?: "").contains(q.trim(), true) }
        }
        return if (recentDabord) liste else liste.reversed()
    }

    /** Envoie le jeton push de cet appareil au serveur (sans effet si Firebase n'est pas configuré). */
    suspend fun enregistrerPush(jeton: String? = null) {
        val t = jeton ?: org.parcit.mobile.Push.jeton(ctx) ?: return
        api.put("appareils/courant", buildJsonObject { put("fcm_token", t); put("version_app", org.parcit.mobile.BuildConfig.VERSION_NAME) })
    }

    suspend fun agents(): List<Agent> = api.get("agents").data()

    suspend fun affecter(t: Tache, assigneId: Int, commentaire: String?): Tache {
        api.post("taches/${t.id}/affecter", buildJsonObject {
            put("assigne_id", assigneId); put("version", t.version)
            if (!commentaire.isNullOrBlank()) put("commentaire", commentaire)
        })
        return tache(t.id)
    }

    suspend fun changerStatut(t: Tache, statut: String, motif: String?, relance: String?, commentaire: String?): Tache {
        api.post("taches/${t.id}/statut", buildJsonObject {
            put("statut", statut); put("version", t.version)
            if (!motif.isNullOrBlank()) put("motif_attente", motif)
            if (!relance.isNullOrBlank()) put("date_relance", relance)
            if (!commentaire.isNullOrBlank()) put("commentaire", commentaire)
        })
        return tache(t.id)
    }

    suspend fun commenterEquipe(id: Int, texte: String, visibleDeclarant: Boolean): Tache {
        api.post("taches/$id/commentaires", buildJsonObject { put("commentaire", texte); put("visible_declarant", visibleDeclarant) })
        return tache(id)
    }

    // ------------------------------------------------------------------ Parc
    suspend fun parc(q: String, statut: String?): List<Fiche> {
        val p = mutableMapOf<String, String>()
        if (!statut.isNullOrBlank()) p["statut"] = statut
        val cle = "parc_${statut ?: "tous"}"
        val ser = ListSerializer(Fiche.serializer())
        if (q.isBlank()) return avecCache(cle, ser) { pages<Fiche>("materiel", p) }
        return try { pages<Fiche>("materiel", p + ("q" to q.trim())) } catch (e: ApiException) {
            val c = if (e.reseau) cache.lire(cle, ser) else null
            if (c == null) throw e
            etatReseau(c.second)
            c.first.filter { f -> listOfNotNull(f.libelle, f.marque, f.modele, f.numSerie, f.type).any { it.contains(q.trim(), true) } }
        }
    }

    suspend fun fiche(id: Int): Fiche = avecCache("fiche_$id", Fiche.serializer()) { api.get("materiel/$id").data() }
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
    suspend fun prendre(id: Int): Tache {
        api.post("taches/$id/prendre", buildJsonObject { })
        return tache(id)
    }

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
