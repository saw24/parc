package org.parcit.mobile.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import org.parcit.mobile.AppViewModel
import org.parcit.mobile.data.Tache
import java.time.LocalDate

/** Vue des agents du service IT : pannes à prendre, mes tâches, toutes les pannes ouvertes. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranInterventions(vm: AppViewModel) {
    var q by remember { mutableStateOf(vm.recherche) }
    LaunchedEffect(Unit) { vm.chargerInterventions() }
    Column(Modifier.fillMaxSize()) {
        EnTete("Interventions", "${vm.interventions.size} panne(s)", actions = {
            IconButton({ vm.chargerInterventions() }) { Icon(Icons.Rounded.Refresh, "Actualiser") }
        })
        val filtres = listOf("file" to "À prendre", "moi" to "Mes tâches", "ouvertes" to "Ouvertes", "toutes" to "Toutes")
        SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth().padding(horizontal = 16.dp)) {
            filtres.forEachIndexed { i, (k, l) ->
                SegmentedButton(vm.filtre == k, { vm.choisirFiltre(k) }, SegmentedButtonDefaults.itemShape(i, filtres.size), label = { Text(l) })
            }
        }
        Champ(q, { q = it; if (it.isEmpty() || it.length >= 3) vm.rechercherInterventions(it) }, "Rechercher (objet, référence…)",
            Modifier.padding(16.dp, 12.dp, 16.dp, 4.dp), icone = Icons.Rounded.Search)
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (vm.interventions.isEmpty() && !vm.chargement) item {
                when (vm.filtre) {
                    "file" -> EtatVide(Icons.Rounded.CheckCircle, "File vide", "Aucune panne en attente de prise en charge.")
                    "moi" -> EtatVide(Icons.Rounded.Inbox, "Rien à traiter", "Aucune tâche ne vous est assignée.")
                    "ouvertes" -> EtatVide(Icons.Rounded.Inbox, "Aucune panne ouverte")
                    else -> EtatVide(Icons.Rounded.Inbox, "Aucune panne")
                }
            }
            items(vm.interventions, key = { it.id }) { t ->
                CartePanne(t, extra = {
                    val infos = listOfNotNull(t.declarant?.nom?.let { "Par $it" }, t.service, t.materiel?.libelle).joinToString(" · ")
                    if (infos.isNotBlank()) Text(infos, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    if (t.actions.prendre) BoutonSecondaire("Je prends", { vm.prendre(t.id) }, enabled = !vm.chargement, icone = Icons.Rounded.PanTool)
                }) { vm.ouvrirDetail(t.id) }
            }
        }
    }
}

/** Boutons d'équipe du détail : affecter, changer le statut / rouvrir, note interne. */
@Composable
fun ActionsAgent(vm: AppViewModel, t: Tache) {
    var dialogue by remember { mutableStateOf<String?>(null) }
    val boutons = t.actions.affecter || t.actions.changerStatut || t.actions.rouvrir
    val note = vm.estEquipe(t) && t.actions.commenter
    if (boutons || note) Carte {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("Actions", style = MaterialTheme.typography.titleSmall)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (t.actions.affecter) BoutonSecondaire("Affecter", { vm.chargerAgents(); dialogue = "affecter" }, icone = Icons.Rounded.PersonAdd)
                if (t.actions.changerStatut) BoutonSecondaire("Statut", { dialogue = "statut" }, icone = Icons.Rounded.SwapHoriz)
                else if (t.actions.rouvrir) BoutonSecondaire("Rouvrir", { dialogue = "statut" }, icone = Icons.Rounded.Replay)
            }
            if (note) {
                var texte by remember(t.id) { mutableStateOf("") }
                var interne by remember(t.id) { mutableStateOf(true) }
                Champ(texte, { texte = it }, "Commentaire", minLignes = 2)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(interne, { interne = it }); Text("Note interne (invisible du déclarant)", style = MaterialTheme.typography.bodyMedium)
                }
                BoutonSecondaire("Ajouter", { vm.commenterEquipe(texte.trim(), !interne); texte = "" }, enabled = texte.isNotBlank() && !vm.chargement)
            }
        }
    }
    when (dialogue) {
        "affecter" -> DialogueAffecter(vm) { dialogue = null }
        "statut" -> DialogueStatut(vm, t) { dialogue = null }
    }
}

@Composable
private fun DialogueAffecter(vm: AppViewModel, fermer: () -> Unit) {
    var commentaire by remember { mutableStateOf("") }
    AlertDialog(onDismissRequest = fermer, title = { Text("Affecter à") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                if (vm.agents.isEmpty()) Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
                vm.agents.forEach { a ->
                    Surface(onClick = { vm.affecter(a.id, commentaire.ifBlank { null }); fermer() }, shape = MaterialTheme.shapes.medium, color = MaterialTheme.colorScheme.surfaceContainerHigh) {
                        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            Avatar(a.nom)
                            Column(Modifier.weight(1f)) {
                                Text(a.nom, style = MaterialTheme.typography.titleSmall)
                                Text(if (a.fileCommune) "Remettre dans la file" else "${a.charge} en cours", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                }
                Spacer(Modifier.height(8.dp))
                Champ(commentaire, { commentaire = it }, "Message (facultatif)")
            }
        },
        confirmButton = {}, dismissButton = { TextButton(fermer) { Text("Fermer") } })
}

@Composable
private fun DialogueStatut(vm: AppViewModel, t: Tache, fermer: () -> Unit) {
    val choix = listOf("À faire", "En cours", "En attente", "Terminé", "Annulé").filter { it != t.statut }
    var statut by remember { mutableStateOf(if (t.actions.rouvrir) "En cours" else if (t.statut == "En cours") "Terminé" else "En cours") }
    var motif by remember { mutableStateOf("") }
    var relance by remember { mutableStateOf(LocalDate.now().plusDays(3).toString()) }
    var commentaire by remember { mutableStateOf("") }
    val attente = statut == "En attente"
    AlertDialog(onDismissRequest = fermer, title = { Text("Nouveau statut") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                choix.forEach { s ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        RadioButton(statut == s, { statut = s }); PuceStatut(s)
                    }
                }
                if (attente) {
                    Champ(motif, { motif = it }, "Motif de l'attente *")
                    Champ(relance, { relance = it }, "Date de relance (AAAA-MM-JJ) *")
                }
                Champ(commentaire, { commentaire = it }, "Commentaire (facultatif)")
            }
        },
        confirmButton = {
            Button({
                vm.changerStatut(statut, if (attente) motif.trim() else null, if (attente) relance.trim() else null, commentaire.ifBlank { null }); fermer()
            }, enabled = !attente || (motif.isNotBlank() && Regex("\\d{4}-\\d{2}-\\d{2}").matches(relance.trim()))) { Text("Valider") }
        },
        dismissButton = { TextButton(fermer) { Text("Annuler") } })
}
