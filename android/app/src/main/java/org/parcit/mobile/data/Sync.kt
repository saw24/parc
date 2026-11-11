package org.parcit.mobile.data

import android.content.Context
import androidx.work.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.sync.Mutex
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.*
import java.util.concurrent.TimeUnit

/** Une modification faite hors ligne, rejouée dans l'ordre à la prochaine synchronisation. */
@Serializable
data class Operation(
    val id: String, val type: String, val tacheId: Int, val libelle: String, val creeLe: Long,
    val payload: JsonObject = JsonObject(emptyMap()), val erreur: String? = null, val conflit: Boolean = false,
)

data class SyncEtat(
    val enCours: Boolean = false, val etape: String = "", val progression: Float = 0f,
    val derniere: Long? = null, val enAttente: Int = 0, val erreur: String? = null,
)

/**
 * Synchronisation dans les deux sens : envoie d'abord les modifications faites hors ligne, puis télécharge les
 * données auxquelles l'utilisateur a droit (copie locale). Une seule synchronisation à la fois ; elle tourne en
 * tâche de fond (aussi application fermée, via WorkManager) et ne bloque jamais l'interface.
 */
class SyncManager(
    private val repo: Repository, private val api: ApiClient, private val store: SecureStore,
    private val cache: CacheLocal, private val json: Json, private val ctx: Context,
) {
    val etat = MutableStateFlow(SyncEtat(derniere = store.derniereSync.takeIf { it > 0 }, enAttente = compter()))
    private val verrou = Mutex()
    private val serOps = ListSerializer(Operation.serializer())

    // ------------------------------------------------------------------ File des modifications hors ligne
    fun operations(): List<Operation> = store.opsJson?.let { runCatching { json.decodeFromString(serOps, it) }.getOrNull() } ?: emptyList()

    private fun compter() = operations().size + repo.brouillons().size

    @Synchronized private fun sauver(l: List<Operation>) {
        store.opsJson = if (l.isEmpty()) null else json.encodeToString(serOps, l)
        etat.update { it.copy(enAttente = l.size + repo.brouillons().size) }
    }

    @Synchronized fun enfiler(op: Operation) { sauver(operations() + op); planifierMaintenant(ctx) }
    @Synchronized fun abandonner(id: String) = sauver(operations().filter { it.id != id })
    @Synchronized fun reessayer(id: String) = sauver(operations().map { if (it.id == id) it.copy(erreur = null, conflit = false) else it })
    fun viderFile() { store.opsJson = null; etat.update { it.copy(enAttente = repo.brouillons().size) } }
    fun rafraichirCompteur() = etat.update { it.copy(enAttente = compter()) }

    // ------------------------------------------------------------------ Synchronisation
    /** true = terminée ; false = déjà en cours, pas de réseau ou refus. Ne lève jamais d'exception. */
    suspend fun synchroniser(): Boolean {
        if (!store.loggedIn || !verrou.tryLock()) return false
        try {
            etat.update { it.copy(enCours = true, erreur = null, etape = "Envoi de vos modifications", progression = 0.02f) }
            pousser()
            tirer()
            val maintenant = System.currentTimeMillis()
            store.derniereSync = maintenant
            etat.update { it.copy(enCours = false, derniere = maintenant, erreur = null, etape = "", progression = 1f, enAttente = compter()) }
            repo.etatReseau(null)
            return true
        } catch (e: ApiException) {
            etat.update { it.copy(enCours = false, etape = "", enAttente = compter(), erreur = if (e.reseau) null else e.message) }
            if (e.reseau) repo.etatReseau(store.derniereSync.takeIf { it > 0 } ?: System.currentTimeMillis())
            return false
        } catch (e: Exception) {
            etat.update { it.copy(enCours = false, etape = "", enAttente = compter(), erreur = "Synchronisation interrompue.") }
            return false
        } finally { verrou.unlock() }
    }

    // ---- Envoi
    private suspend fun pousser() {
        repo.viderFile()                                   // déclarations créées hors ligne
        for (op in operations()) {
            if (op.erreur != null) continue                // refusée : en attente d'une décision de l'utilisateur
            try {
                envoyer(op)
                abandonner(op.id)
            } catch (e: ApiException) {
                if (e.reseau || !e.definitive) throw e
                // Refus définitif (conflit, droits, panne supprimée…) : conservée avec son motif, sans bloquer le reste.
                sauver(operations().map { if (it.id == op.id) it.copy(erreur = e.message, conflit = e.code == "conflit" || e.status == 409) else it })
            }
        }
    }

    private suspend fun versionCourante(id: Int): Int = api.get("taches/$id")["data"]!!.jsonObject["version"]!!.jsonPrimitive.int

    private suspend fun envoyer(op: Operation) {
        val id = op.tacheId
        when (op.type) {
            "prendre" -> api.post("taches/$id/prendre", buildJsonObject { })
            "commenter" -> api.post("taches/$id/commentaires", op.payload)
            "statut", "annuler" -> api.post("taches/$id/statut", JsonObject(op.payload + ("version" to JsonPrimitive(versionCourante(id)))))
            "affecter" -> api.post("taches/$id/affecter", JsonObject(op.payload + ("version" to JsonPrimitive(versionCourante(id)))))
            "modifier" -> api.patch("taches/$id", JsonObject(op.payload + ("version" to JsonPrimitive(versionCourante(id)))))
            "pointer" -> api.post("inventaires/$id/pointer", op.payload)     // tacheId = numéro de l'inventaire
            else -> Unit
        }
    }

    // ---- Téléchargement
    private fun progression(etape: String, fait: Int, total: Int) = etat.update { it.copy(etape = etape, progression = 0.1f + 0.9f * fait / total) }

    private suspend fun tirer() {
        val u = repo.rafraichirProfil()
        val etapes = mutableListOf<Pair<String, suspend () -> Unit>>()
        fun e(nom: String, f: suspend () -> Unit) { etapes += nom to f }
        e("Listes de choix") { repo.referentiels() }
        e("Vos pannes") { repo.mesDeclarations() }
        e("Pannes visibles") { tirerPannes() }
        if (u.voitParc) e("Parc informatique") { repo.parc("", null) }
        e("Alertes") { repo.notifications() }
        e("Matériels") { repo.mesMateriels() }
        if (u.voitInterventions) e("Agents") { repo.agents() }
        e("Utilisateurs") { repo.utilisateurs() }
        if (u.voitInventaires) e("Inventaires") { repo.telechargerInventaires() }
        if (u.voitUtilisateurs) e("Comptes") { repo.utilisateursAdmin(""); repo.roles() }
        if (u.gereServices) e("Services") { repo.servicesAdmin() }
        e("Détail des pannes en cours") { tirerDetails(u.id) }
        etapes.forEachIndexed { i, (nom, f) ->
            progression(nom, i, etapes.size)
            try { f() } catch (e: ApiException) {
                // Une ressource interdite ou absente n'empêche pas de synchroniser le reste ; le réseau, si.
                if (e.reseau || e.status >= 500 || e.status == 401) throw e
            }
        }
    }

    /** Pannes que l'utilisateur peut voir : téléchargement complet la première fois, ensuite seulement les changements. */
    private suspend fun tirerPannes() {
        val ser = ListSerializer(Tache.serializer())
        val actuel = cache.lire("pannes_toutes", ser)?.first
        val depuis = store.pannesSince
        var heure: String? = null
        val recues = mutableListOf<Tache>()
        var curseur: String? = null
        do {
            val q = mutableMapOf("limit" to "100")
            if (actuel != null && depuis != null) q["updated_since"] = depuis
            curseur?.let { q["cursor"] = it }
            val r = api.get("taches", q)
            if (heure == null) heure = r["meta"]?.jsonObject?.get("server_time")?.jsonPrimitive?.contentOrNull
            recues += json.decodeFromJsonElement<List<Tache>>(r["data"]!!)
            curseur = r["meta"]?.jsonObject?.get("next_cursor")?.jsonPrimitive?.contentOrNull
        } while (curseur != null && recues.size < 1000)
        val fusion = if (actuel != null && depuis != null) {
            val par = actuel.associateBy { it.id }.toMutableMap()
            recues.forEach { par[it.id] = it }
            // Ce qui a été supprimé (ou n'est plus visible) disparaît de la copie locale.
            val ids = api.get("taches", mapOf("ids_only" to "1"))["data"]!!.jsonArray.map { it.jsonPrimitive.int }.toSet()
            par.values.filter { it.id in ids }
        } else recues
        cache.ecrire("pannes_toutes", ser, fusion.sortedByDescending { it.updatedAt ?: it.dates.creation ?: "" })
        if (heure != null) store.pannesSince = heure
    }

    /** Historique complet des pannes en cours qui concernent l'utilisateur (consultable hors ligne). */
    private suspend fun tirerDetails(moi: Int) {
        val ser = ListSerializer(Tache.serializer())
        val ouvertes = cache.lire("pannes_toutes", ser)?.first.orEmpty()
            .filter { it.statut != "Terminé" && it.statut != "Annulé" && (it.assigne?.id == moi || it.declarant?.id == moi) }
            .take(40)
        for (t in ouvertes) repo.tache(t.id)
    }

    companion object {
        fun planifier(ctx: Context) {
            val req = PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES)
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()).build()
            WorkManager.getInstance(ctx).enqueueUniquePeriodicWork("synchronisation", ExistingPeriodicWorkPolicy.KEEP, req)
        }

        /** Dès que le réseau est là, même application fermée. */
        fun planifierMaintenant(ctx: Context) {
            val req = OneTimeWorkRequestBuilder<SyncWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS).build()
            WorkManager.getInstance(ctx).enqueueUniqueWork("synchronisation_immediate", ExistingWorkPolicy.REPLACE, req)
        }

        fun arreter(ctx: Context) {
            WorkManager.getInstance(ctx).cancelUniqueWork("synchronisation")
            WorkManager.getInstance(ctx).cancelUniqueWork("synchronisation_immediate")
        }
    }
}

class SyncWorker(ctx: Context, p: WorkerParameters) : CoroutineWorker(ctx, p) {
    override suspend fun doWork(): Result {
        val app = applicationContext as org.parcit.mobile.ParcApp
        if (!app.store.loggedIn) return Result.success()
        return if (app.repo.sync.synchroniser() || app.repo.sync.etat.value.erreur != null) Result.success() else Result.retry()
    }
}
