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

    // ------------------------------------------------------------------ Session
    suspend fun login(server: String, login: String, password: String): Utilisateur {
        store.serverUrl = server
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
        val u: Utilisateur = api.get("me").data()
        store.userJson = json.encodeToString(Utilisateur.serializer(), u)
        return u
    }

    suspend fun deconnecter() {
        runCatching { api.post("auth/logout", buildJsonObject { }) }
        store.clearSession()
    }

    // ------------------------------------------------------------------ Pannes
    suspend fun mesDeclarations(): List<Tache> {
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

    suspend fun tache(id: Int): Tache = api.get("taches/$id").data()

    suspend fun referentiels(): Referentiels = api.get("referentiels").data()

    /** Le matériel que l'utilisateur peut voir ; liste vide si son rôle n'a pas accès au parc. */
    suspend fun mesMateriels(): List<Materiel> = try {
        api.get("materiel", mapOf("assigne" to "moi", "limit" to "100")).data()
    } catch (e: ApiException) { if (e.status == 403) emptyList() else throw e }

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
