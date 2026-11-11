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
import org.parcit.mobile.data.*

private fun duree(mois: Int?): String = when {
    mois == null -> "—"
    mois >= 12 -> "${mois / 12} an(s)" + (if (mois % 12 > 0) " ${mois % 12} mois" else "")
    else -> "$mois mois"
}

private val SUIVANT = mapOf("Proposé" to listOf("Validé", "Refusé"), "Validé" to listOf("Commandé", "Refusé"), "Commandé" to listOf("Remplacé"))

@Composable
fun EcranRenouvellement(vm: AppViewModel) {
    val u = vm.utilisateur
    var onglet by remember { mutableIntStateOf(0) }
    var aProposer by remember { mutableStateOf<SuggestionRenouv?>(null) }
    var aDecider by remember { mutableStateOf<Pair<Renouvellement, String>?>(null) }

    Column(Modifier.fillMaxSize()) {
        EnTete("Renouvellement", "Matériel à remplacer", retour = { vm.retour() })
        PrimaryTabRow(onglet, containerColor = Color.Transparent) {
            Tab(onglet == 0, { onglet = 0 }, text = { Text("Suggestions (${vm.suggestionsRenouv.size})") })
            Tab(onglet == 1, { onglet = 1 }, text = { Text("Propositions (${vm.renouvellements.size})") })
        }
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 12.dp, 16.dp, 32.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            if (onglet == 0) {
                if (vm.suggestionsRenouv.isEmpty() && !vm.chargement) item { EtatVide(Icons.Rounded.CheckCircle, "Rien à renouveler", "Aucun matériel ne dépasse sa durée de vie ni ne tombe en panne trop souvent.") }
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
                            if (u?.proposeRenouvellements == true) FilledTonalButton({ aProposer = s }, Modifier.align(Alignment.End)) { Text("Proposer") }
                        }
                    }
                }
            } else {
                if (vm.renouvellements.isEmpty() && !vm.chargement) item { EtatVide(Icons.Rounded.Autorenew, "Aucune proposition") }
                items(vm.renouvellements, key = { it.id }) { r ->
                    Carte {
                        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(r.materiel.libelle, Modifier.weight(1f), style = MaterialTheme.typography.titleSmall)
                                Puce(r.statut, when (r.statut) {
                                    "Refusé" -> MaterialTheme.colorScheme.error
                                    "Remplacé" -> MaterialTheme.colorScheme.secondary
                                    else -> MaterialTheme.colorScheme.primary
                                })
                            }
                            (listOfNotNull(r.motif) + r.criteres.map { it.texte }).forEach { Text("• $it", style = MaterialTheme.typography.bodyMedium) }
                            val meta = listOfNotNull(r.coutEstime?.let { "Coût estimé : ${"%,.0f".format(it)}" }, r.proposePar?.let { "Proposé par $it" },
                                r.decidePar?.let { "Décision : $it" }, r.commentaire).joinToString(" · ")
                            if (meta.isNotBlank()) Text(meta, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            val actions = if (u?.decideRenouvellements == true) SUIVANT[r.statut].orEmpty() else emptyList()
                            if (actions.isNotEmpty()) Row(Modifier.align(Alignment.End), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                actions.forEach { d ->
                                    if (d == "Refusé") OutlinedButton({ aDecider = r to d }) { Text(d) } else Button({ aDecider = r to d }) { Text(d) }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    aProposer?.let { s ->
        var cout by remember { mutableStateOf("") }
        var motif by remember { mutableStateOf("") }
        AlertDialog(onDismissRequest = { aProposer = null }, title = { Text("Proposer le renouvellement") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(s.materiel.libelle, style = MaterialTheme.typography.bodyMedium)
                Champ(cout, { cout = it.filter { c -> c.isDigit() || c == '.' || c == ',' } }, "Coût estimé (facultatif)")
                Champ(motif, { motif = it }, "Remarque (facultatif)", minLignes = 2)
            } },
            confirmButton = { TextButton({
                vm.proposerRenouv(s.materiel.id, cout.replace(',', '.').toDoubleOrNull(), motif.trim().ifBlank { null }); aProposer = null
            }) { Text("Proposer") } },
            dismissButton = { TextButton({ aProposer = null }) { Text("Annuler") } })
    }

    aDecider?.let { (r, d) ->
        var commentaire by remember { mutableStateOf("") }
        val refus = d == "Refusé"
        AlertDialog(onDismissRequest = { aDecider = null }, title = { Text("Passer à « $d »") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Text(r.materiel.libelle, style = MaterialTheme.typography.bodyMedium)
                if (d == "Remplacé") Text("L'ancien matériel sera marqué « Retiré ».", style = MaterialTheme.typography.bodySmall)
                Champ(commentaire, { commentaire = it }, if (refus) "Raison du refus *" else "Commentaire (facultatif)", minLignes = 2)
            } },
            confirmButton = { TextButton({
                vm.deciderRenouv(r.id, d, commentaire.trim().ifBlank { null }); aDecider = null
            }, enabled = !refus || commentaire.isNotBlank()) { Text("Confirmer") } },
            dismissButton = { TextButton({ aDecider = null }) { Text("Annuler") } })
    }
}
