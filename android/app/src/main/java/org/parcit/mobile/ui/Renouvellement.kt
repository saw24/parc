package org.parcit.mobile.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import org.parcit.mobile.AppViewModel

private fun duree(mois: Int?): String = when {
    mois == null -> "—"
    mois >= 12 -> "${mois / 12} an(s)" + (if (mois % 12 > 0) " ${mois % 12} mois" else "")
    else -> "$mois mois"
}

/** Matériel à renouveler : suggestions calculées par le serveur (fin de vie, pannes répétées, garantie proche). */
@Composable
fun EcranRenouvellement(vm: AppViewModel) {
    Column(Modifier.fillMaxSize()) {
        EnTete("Renouvellement", "${vm.suggestionsRenouv.size} matériel(s) à renouveler", retour = { vm.retour() })
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 12.dp, 16.dp, 32.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            if (vm.suggestionsRenouv.isEmpty() && !vm.chargement) item { EtatVide(Icons.Rounded.CheckCircle, "Rien à renouveler", "Aucun matériel ne dépasse sa durée de vie, ne tombe en panne trop souvent ni n'approche de la fin de sa garantie.") }
            items(vm.suggestionsRenouv, key = { it.materiel.id }) { s ->
                Carte {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(s.materiel.libelle, Modifier.weight(1f), style = MaterialTheme.typography.titleSmall)
                            Puce("Priorité ${s.score}", if (s.score >= 50) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.tertiary)
                        }
                        Text(listOfNotNull(s.materiel.service, s.materiel.utilisateur).joinToString(" · ").ifBlank { "Non affecté" } + " · Âge : ${duree(s.ageMois)}",
                            style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        s.criteres.forEach { Text("• ${it.texte}", style = MaterialTheme.typography.bodyMedium) }
                    }
                }
            }
        }
    }
}
