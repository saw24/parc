package org.parcit.mobile.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.*
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.parcit.mobile.BuildConfig
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Erreur API : [code] est le code stable du serveur (« token_expire », « conflit »…), 0 = réseau. */
class ApiException(val status: Int, val code: String, message: String) : Exception(message) {
    val reseau get() = status == 0
    /** Une erreur 4xx (hors 401/408/429) ne se corrigera pas en réessayant. */
    val definitive get() = status in 400..499 && status != 401 && status != 408 && status != 429
}

class ApiClient(private val store: SecureStore) {
    private val http = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS).readTimeout(30, TimeUnit.SECONDS).build()
    private val refreshLock = Mutex()
    private val json = "application/json; charset=utf-8".toMediaType()

    /** Appelé quand le serveur refuse définitivement la session (révoquée, expirée). */
    var onSessionLost: (() -> Unit)? = null

    suspend fun get(path: String, query: Map<String, String> = emptyMap(), auth: Boolean = true) =
        call("GET", path, null, query, auth)

    suspend fun post(path: String, body: JsonObject, auth: Boolean = true) = call("POST", path, body, emptyMap(), auth)
    suspend fun put(path: String, body: JsonObject) = call("PUT", path, body, emptyMap(), true)
    suspend fun patch(path: String, body: JsonObject) = call("PATCH", path, body, emptyMap(), true)
    suspend fun delete(path: String) = call("DELETE", path, null, emptyMap(), true)

    /** Renvoie l'enveloppe complète {data, meta}. */
    suspend fun call(
        method: String, path: String, body: JsonObject?, query: Map<String, String>, auth: Boolean,
    ): JsonObject {
        val tokenUsed = store.accessToken
        try {
            return send(method, path, body, query, if (auth) tokenUsed else null)
        } catch (e: ApiException) {
            if (auth && e.status == 401 && (e.code == "token_expire" || e.code == "token_invalide")) {
                refresh(tokenUsed)
                return send(method, path, body, query, store.accessToken)
            }
            throw e
        }
    }

    private suspend fun refresh(tokenUsed: String?) = refreshLock.withLock {
        // Un autre appel a peut-être déjà renouvelé les jetons pendant l'attente.
        if (store.accessToken != tokenUsed && store.accessToken != null) return@withLock
        val refresh = store.refreshToken ?: run { onSessionLost?.invoke(); throw ApiException(401, "refresh_invalide", "Session expirée.") }
        try {
            val r = send("POST", "auth/refresh", buildJsonObject { put("refresh_token", refresh) }, emptyMap(), null)
            val d = r["data"]!!.jsonObject
            // Les deux jetons changent ensemble : on stocke le nouveau refresh avant tout.
            store.refreshToken = d["refresh_token"]!!.jsonPrimitive.content
            store.accessToken = d["access_token"]!!.jsonPrimitive.content
        } catch (e: ApiException) {
            if (e.status == 401) { store.clearSession(); onSessionLost?.invoke() }
            throw e
        }
    }

    private suspend fun send(
        method: String, path: String, body: JsonObject?, query: Map<String, String>, token: String?,
    ): JsonObject = withContext(Dispatchers.IO) {
        val base = "${store.serverUrl}/backend/api/v1/index.php/$path"
        val url = try { base.toHttpUrl().newBuilder().apply { query.forEach { (k, v) -> addQueryParameter(k, v) } }.build() }
        catch (e: IllegalArgumentException) { throw ApiException(0, "adresse_invalide", "Adresse du serveur invalide.") }
        val req = Request.Builder().url(url)
            .header("Accept", "application/json")
            .header("X-App-Version", BuildConfig.VERSION_NAME)
            .apply { if (token != null) header("Authorization", "Bearer $token") }
            .method(method, if (body != null) body.toString().toRequestBody(json) else if (method == "GET") null else "{}".toRequestBody(json))
            .build()
        val (status, text) = try {
            http.newCall(req).execute().use { it.code to (it.body?.string() ?: "") }
        } catch (e: IOException) {
            throw ApiException(0, "reseau", "Serveur injoignable. Vérifiez votre connexion.")
        }
        val root = try { Json.parseToJsonElement(text).jsonObject } catch (e: Exception) {
            throw ApiException(status, "reponse_invalide", "Réponse inattendue du serveur ($status).")
        }
        if (status >= 400) {
            val err = root["error"]?.jsonObject
            throw ApiException(status, err?.get("code")?.jsonPrimitive?.contentOrNull ?: "erreur",
                err?.get("message")?.jsonPrimitive?.contentOrNull ?: "Erreur $status")
        }
        // Statut mémorisé pour distinguer 200 (rejeu) de 201 (création).
        buildJsonObject { root.forEach { (k, v) -> put(k, v) }; put("_status", status) }
    }

}
