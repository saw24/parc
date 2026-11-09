package org.parcit.mobile

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import android.Manifest
import android.content.Intent
import android.os.Build
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import org.parcit.mobile.ui.ParcTheme
import org.parcit.mobile.ui.Root

class MainActivity : ComponentActivity() {
    private val vm: AppViewModel by viewModels {
        object : ViewModelProvider.Factory {
            @Suppress("UNCHECKED_CAST")
            override fun <T : ViewModel> create(modelClass: Class<T>): T = AppViewModel(application as ParcApp) as T
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (Build.VERSION.SDK_INT >= 33 && !Alertes.autorise(this)) {
            registerForActivityResult(ActivityResultContracts.RequestPermission()) { }.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
        setContent { ParcTheme { Root(vm) } }
        ouvrirDepuis(intent)
    }

    override fun onNewIntent(intent: Intent) { super.onNewIntent(intent); ouvrirDepuis(intent) }

    /** Un appui sur une notification ouvre la panne concernée. */
    private fun ouvrirDepuis(i: Intent?) {
        val id = i?.getIntExtra(Alertes.EXTRA_TACHE, 0) ?: 0
        if (id > 0) { vm.ouvrirDetail(id); i?.removeExtra(Alertes.EXTRA_TACHE) }
    }

    override fun onStart() { super.onStart(); Alertes.enAvantPlan = true; vm.enAvantPlan(true) }
    override fun onStop() { super.onStop(); Alertes.enAvantPlan = false; vm.enAvantPlan(false) }
}
