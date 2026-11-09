package org.parcit.mobile

import android.app.Application
import org.parcit.mobile.data.ApiClient
import org.parcit.mobile.data.Repository
import org.parcit.mobile.data.SecureStore

/** Conteneur d'objets partagés (injection manuelle : l'application est petite). */
class ParcApp : Application() {
    lateinit var store: SecureStore
    lateinit var api: ApiClient
    lateinit var repo: Repository

    override fun onCreate() {
        super.onCreate()
        store = SecureStore(this)
        api = ApiClient(store)
        repo = Repository(this, api, store)
    }
}
