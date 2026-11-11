package org.parcit.mobile.data

import android.content.Context
import kotlinx.serialization.KSerializer
import kotlinx.serialization.json.Json
import java.io.File

/** Copie locale des dernières données lues (stockage privé de l'application) : de quoi consulter sans réseau. */
class CacheLocal(ctx: Context, private val json: Json) {
    private val dir = File(ctx.filesDir, "cache_hors_ligne").apply { mkdirs() }

    private fun fichier(cle: String) = File(dir, cle.replace(Regex("[^A-Za-z0-9_-]"), "_") + ".json")

    @Synchronized fun <T> ecrire(cle: String, ser: KSerializer<T>, valeur: T) {
        runCatching {
            val f = fichier(cle)
            val tmp = File(dir, f.name + ".tmp")
            tmp.writeText(json.encodeToString(ser, valeur))
            tmp.renameTo(f)
        }
    }

    /** La valeur et l'heure (ms) de sa dernière mise à jour, ou null. */
    @Synchronized fun <T> lire(cle: String, ser: KSerializer<T>): Pair<T, Long>? = runCatching {
        val f = fichier(cle)
        if (f.exists()) json.decodeFromString(ser, f.readText()) to f.lastModified() else null
    }.getOrNull()

    @Synchronized fun vider() { dir.listFiles()?.forEach { it.delete() } }
}
