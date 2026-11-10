package org.parcit.mobile

import android.content.Context
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlin.coroutines.resume
import kotlinx.coroutines.suspendCancellableCoroutine

/** Notifications push (Firebase). Sans google-services.json, tout reste inerte : l'application continue de fonctionner par scrutation. */
object Push {
    fun disponible(ctx: Context) = runCatching { FirebaseApp.getApps(ctx).isNotEmpty() }.getOrDefault(false)

    /** Jeton FCM de cet appareil, ou null si Firebase n'est pas configuré / indisponible. */
    suspend fun jeton(ctx: Context): String? {
        if (!disponible(ctx)) return null
        return suspendCancellableCoroutine { c ->
            runCatching { FirebaseMessaging.getInstance().token.addOnCompleteListener { t -> c.resume(if (t.isSuccessful) t.result else null) } }
                .onFailure { c.resume(null) }
        }
    }
}

class ParcFcmService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        val app = application as ParcApp
        if (!app.store.loggedIn) return
        CoroutineScope(Dispatchers.IO).launch { runCatching { app.repo.enregistrerPush(token) } }
    }

    override fun onMessageReceived(message: RemoteMessage) {
        // Application affichée : elle montre déjà ses propres alertes.
        if (Alertes.enAvantPlan) return
        val d = message.data
        val id = d["notification_id"]?.toIntOrNull() ?: return
        Alertes.afficherPush(this, id, d["titre"] ?: "Parc IT", d["message"] ?: "", d["tache_id"]?.toIntOrNull() ?: 0, d["peut_prendre"] == "1")
    }
}
