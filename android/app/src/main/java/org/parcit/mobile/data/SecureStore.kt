package org.parcit.mobile.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Stockage local : les secrets (jetons, file d'envoi) sont chiffrés en AES-GCM avec une clé
 * non exportable du Keystore Android. Si la clé est perdue (restauration, nouvel appareil),
 * la lecture renvoie null et l'utilisateur se reconnecte.
 */
class SecureStore(context: Context) {
    private val prefs = context.getSharedPreferences("parc_store", Context.MODE_PRIVATE)

    private fun key(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getKey(ALIAS, null) as? SecretKey)?.let { return it }
        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        gen.init(
            KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setKeySize(256).build()
        )
        return gen.generateKey()
    }

    fun putSecret(name: String, value: String?) {
        if (value == null) { prefs.edit().remove(name).apply(); return }
        val c = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, key()) }
        val out = c.iv + c.doFinal(value.toByteArray())
        prefs.edit().putString(name, Base64.encodeToString(out, Base64.NO_WRAP)).apply()
    }

    fun getSecret(name: String): String? = try {
        val raw = Base64.decode(prefs.getString(name, null) ?: return null, Base64.NO_WRAP)
        val c = Cipher.getInstance("AES/GCM/NoPadding")
        c.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, raw, 0, 12))
        String(c.doFinal(raw, 12, raw.size - 12))
    } catch (e: Exception) { null }

    var serverUrl: String
        get() = prefs.getString("server", null) ?: org.parcit.mobile.BuildConfig.DEFAULT_SERVER
        set(v) = prefs.edit().putString("server", v.trim().trimEnd('/')).apply()

    var accessToken: String?
        get() = getSecret("access"); set(v) = putSecret("access", v)
    var refreshToken: String?
        get() = getSecret("refresh"); set(v) = putSecret("refresh", v)
    var userJson: String?
        get() = getSecret("user"); set(v) = putSecret("user", v)
    var outboxJson: String?
        get() = getSecret("outbox"); set(v) = putSecret("outbox", v)
    /** Modifications faites hors ligne, en attente d'envoi (voir SyncManager). */
    var opsJson: String?
        get() = getSecret("ops"); set(v) = putSecret("ops", v)
    /** Compte auquel appartiennent les envois en attente : jamais rejoués sous un autre compte. */
    var opsUserId: Int
        get() = prefs.getInt("ops_user", 0); set(v) = prefs.edit().putInt("ops_user", v).apply()
    /** Heure (ms) de la dernière synchronisation complète réussie. */
    var derniereSync: Long
        get() = prefs.getLong("derniere_sync", 0); set(v) = prefs.edit().putLong("derniere_sync", v).apply()
    /** Heure serveur du dernier téléchargement des pannes (synchronisation incrémentale). */
    var pannesSince: String?
        get() = prefs.getString("pannes_since", null); set(v) = prefs.edit().putString("pannes_since", v).apply()

    /** Dernière notification déjà présentée à l'utilisateur (évite de la signaler deux fois). */
    var dernierNotifId: Long
        get() = prefs.getLong("notif_id", 0); set(v) = prefs.edit().putLong("notif_id", v).apply()

    /** Thème de l'interface : clair par défaut. */
    var themeSombre: Boolean
        get() = prefs.getBoolean("theme_sombre", false); set(v) = prefs.edit().putBoolean("theme_sombre", v).apply()

    val loggedIn: Boolean get() = refreshToken != null

    /** Efface la session (la file d'envoi est conservée pour ne rien perdre). */
    fun clearSession() { accessToken = null; refreshToken = null; userJson = null }

    private companion object { const val ALIAS = "parc_it_key" }
}
