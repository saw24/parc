package org.parcit.mobile

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.*
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import org.parcit.mobile.data.ApiException
import org.parcit.mobile.data.AppNotification
import java.util.concurrent.TimeUnit

/** Notifications système (hors de l'application) et leurs actions. */
object Alertes {
    const val CANAL = "pannes"
    const val EXTRA_TACHE = "tache_id"
    const val ACTION_PRENDRE = "org.parcit.mobile.PRENDRE"

    /** Vrai tant que l'application est affichée : elle montre alors ses propres alertes. */
    @Volatile var enAvantPlan = false

    fun creerCanal(ctx: Context) {
        if (Build.VERSION.SDK_INT >= 26) {
            val c = NotificationChannel(CANAL, "Pannes", NotificationManager.IMPORTANCE_HIGH)
            c.description = "Prise en charge et suivi de vos pannes"
            ctx.getSystemService(NotificationManager::class.java).createNotificationChannel(c)
        }
    }

    fun autorise(ctx: Context) = Build.VERSION.SDK_INT < 33 ||
        ContextCompat.checkSelfPermission(ctx, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    fun afficher(ctx: Context, n: AppNotification) {
        if (!autorise(ctx)) return
        val ouvrir = PendingIntent.getActivity(
            ctx, n.id.toInt(), Intent(ctx, MainActivity::class.java).putExtra(EXTRA_TACHE, n.tache.id)
                .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val b = NotificationCompat.Builder(ctx, CANAL)
            .setSmallIcon(android.R.drawable.stat_notify_more).setContentTitle(n.titre).setContentText(n.message)
            .setStyle(NotificationCompat.BigTextStyle().bigText(n.message))
            .setContentIntent(ouvrir).setAutoCancel(true).setCategory(NotificationCompat.CATEGORY_MESSAGE)
        if (n.peutPrendre) {
            val prendre = PendingIntent.getBroadcast(
                ctx, n.id.toInt(), Intent(ctx, PrendreReceiver::class.java).setAction(ACTION_PRENDRE)
                    .putExtra(EXTRA_TACHE, n.tache.id).putExtra("notif", n.id.toInt()),
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
            )
            b.addAction(0, "Je prends", prendre)
        }
        runCatching { NotificationManagerCompat.from(ctx).notify(n.id.toInt(), b.build()) }
    }

    /** Notification reçue par push : même identifiant que la scrutation, donc jamais en double. */
    fun afficherPush(ctx: Context, id: Int, titre: String, message: String, tacheId: Int, peutPrendre: Boolean) {
        if (!autorise(ctx)) return
        val ouvrir = PendingIntent.getActivity(
            ctx, id, Intent(ctx, MainActivity::class.java).putExtra(EXTRA_TACHE, tacheId)
                .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val b = NotificationCompat.Builder(ctx, CANAL)
            .setSmallIcon(android.R.drawable.stat_notify_more).setContentTitle(titre).setContentText(message)
            .setStyle(NotificationCompat.BigTextStyle().bigText(message))
            .setContentIntent(ouvrir).setAutoCancel(true).setOnlyAlertOnce(true).setCategory(NotificationCompat.CATEGORY_MESSAGE)
        if (peutPrendre && tacheId > 0) {
            val prendre = PendingIntent.getBroadcast(
                ctx, id, Intent(ctx, PrendreReceiver::class.java).setAction(ACTION_PRENDRE)
                    .putExtra(EXTRA_TACHE, tacheId).putExtra("notif", id),
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
            )
            b.addAction(0, "Je prends", prendre)
        }
        runCatching { NotificationManagerCompat.from(ctx).notify(id, b.build()) }
    }

    fun resultat(ctx: Context, notifId: Int, texte: String) {
        if (!autorise(ctx)) return
        val b = NotificationCompat.Builder(ctx, CANAL).setSmallIcon(android.R.drawable.stat_notify_more)
            .setContentTitle("Parc IT").setContentText(texte).setAutoCancel(true).setTimeoutAfter(60_000)
        runCatching { NotificationManagerCompat.from(ctx).notify(notifId, b.build()) }
    }

    fun planifierScrutation(ctx: Context) {
        val req = PeriodicWorkRequestBuilder<ScrutationWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()).build()
        WorkManager.getInstance(ctx).enqueueUniquePeriodicWork("scrutation_notifications", ExistingPeriodicWorkPolicy.KEEP, req)
    }

    fun arreterScrutation(ctx: Context) = WorkManager.getInstance(ctx).cancelUniqueWork("scrutation_notifications")
}

/** Action « Je prends » depuis la notification, sans ouvrir l'application. */
class PrendreReceiver : BroadcastReceiver() {
    override fun onReceive(ctx: Context, intent: Intent) {
        val tache = intent.getIntExtra(Alertes.EXTRA_TACHE, 0)
        val notif = intent.getIntExtra("notif", tache)
        val pending = goAsync()
        val app = ctx.applicationContext as ParcApp
        CoroutineScope(Dispatchers.IO).launch {
            val texte = try {
                val t = app.repo.prendre(tache)
                NotificationManagerCompat.from(ctx).cancel(notif)
                "Vous avez pris en charge ${t.reference ?: "la panne"}."
            } catch (e: ApiException) {
                when (e.code) {
                    "conflit", "deja_prise" -> e.message ?: "Cette panne vient d'être prise par un collègue."
                    "interdit" -> "Vous n'avez pas le droit de prendre cette panne."
                    else -> if (e.reseau) "Pas de réseau : ouvrez l'application pour réessayer." else e.message ?: "Échec."
                }
            }
            Alertes.resultat(ctx, notif, texte)
            pending.finish()
        }
    }
}

/** Vérifie les nouvelles notifications toutes les ~15 min (canal de base, sans FCM). */
class ScrutationWorker(ctx: Context, p: WorkerParameters) : CoroutineWorker(ctx, p) {
    override suspend fun doWork(): Result {
        val app = applicationContext as ParcApp
        if (!app.store.loggedIn || Alertes.enAvantPlan) return Result.success()
        return try {
            val (liste, _) = app.repo.notifications()
            val nouvelles = liste.filter { !it.lue && it.id > app.store.dernierNotifId }.sortedBy { it.id }
            // Premier passage après connexion : au plus les 5 plus récentes, pour ne pas inonder le téléphone.
            nouvelles.takeLast(5).forEach { Alertes.afficher(applicationContext, it) }
            liste.maxOfOrNull { it.id }?.let { if (it > app.store.dernierNotifId) app.store.dernierNotifId = it }
            Result.success()
        } catch (e: ApiException) {
            if (e.status == 401 || e.definitive) Result.success() else Result.retry()
        }
    }
}
