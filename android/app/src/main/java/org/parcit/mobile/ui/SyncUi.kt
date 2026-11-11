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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import org.parcit.mobile.AppViewModel
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private fun heure(ms: Long?) = if (ms == null || ms <= 0) "jamais" else SimpleDateFormat("dd/MM 'à' HH:mm", Locale.FRANCE).format(Date(ms))

/** Écran de synchronisation : état, synchronisation manuelle, envois en attente. */
@Composable
fun EcranSync(vm: AppViewModel) {
    val s = vm.sync
    val ops = remember(s.enAttente, s.enCours, s.erreur) { vm.operationsEnAttente() }
    Column(Modifier.fillMaxSize()) {
        EnTete("Synchronisation", retour = { vm.retour() })
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 16.dp, 16.dp, 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            item {
                Carte {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            Icon(if (vm.reseau) Icons.Rounded.CloudDone else Icons.Rounded.CloudOff, null,
                                tint = if (vm.reseau) Color(0xFF15803D) else MaterialTheme.colorScheme.onSurfaceVariant)
                            Column {
                                Text(when { !vm.reseau -> "Hors ligne"; s.enCours -> "Synchronisation en cours…"; else -> "En ligne" }, style = MaterialTheme.typography.titleMedium)
                                Text("Dernière synchronisation : ${heure(s.derniere)}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                        if (s.enCours) {
                            LinearProgressIndicator(progress = { s.progression }, Modifier.fillMaxWidth())
                            Text(s.etape, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        s.erreur?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error) }
                        BoutonPrincipal("Synchroniser maintenant", { vm.synchroniser() }, enabled = vm.reseau && !s.enCours, icone = Icons.Rounded.Sync)
                    }
                }
            }
            val brouillons = vm.brouillons.size
            if (ops.isNotEmpty() || brouillons > 0) item { Text("En attente d'envoi", style = MaterialTheme.typography.titleSmall, modifier = Modifier.padding(top = 4.dp)) }
            if (brouillons > 0) item {
                Carte { Text("$brouillons déclaration(s) de panne à envoyer", Modifier.padding(14.dp), style = MaterialTheme.typography.bodyMedium) }
            }
            items(ops, key = { it.id }) { op ->
                Carte {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(op.libelle, style = MaterialTheme.typography.titleSmall)
                        Text("Fait le ${heure(op.creeLe)}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        if (op.erreur != null) {
                            Text((if (op.conflit) "Conflit : " else "Refusée : ") + op.erreur, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error)
                            Row {
                                TextButton({ vm.reessayerOperation(op.id) }) { Text("Réessayer") }
                                TextButton({ vm.abandonnerOperation(op.id) }) { Text("Abandonner") }
                            }
                        } else Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            Icon(Icons.Rounded.Schedule, null, Modifier.size(14.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                            Text("Sera envoyée à la prochaine synchronisation", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            Spacer(Modifier.weight(1f))
                            TextButton({ vm.abandonnerOperation(op.id) }) { Text("Annuler") }
                        }
                    }
                }
            }
            item {
                Carte {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text("Comment ça marche", style = MaterialTheme.typography.titleSmall)
                        Text("Vos données (pannes, interventions, parc, alertes selon vos droits) sont copiées sur le téléphone : vous pouvez les consulter et agir sans réseau. " +
                            "Vos modifications faites hors ligne sont envoyées d'abord, puis les données sont mises à jour.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        Text("La synchronisation se fait automatiquement à la connexion, au retour du réseau et toutes les 15 minutes, même application fermée.",
                            style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
    }
}
