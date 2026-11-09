package org.parcit.mobile.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
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
        TopAppBar(title = { Text("Interventions") }, actions = { TextButton({ vm.chargerInterventions() }) { Text("Actualiser") } })
        Row(Modifier.padding(horizontal = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("file" to "À prendre", "moi" to "Mes tâches", "toutes" to "Toutes").forEach { (k, l) ->
                FilterChip(vm.filtre == k, { vm.choisirFiltre(k) }, { Text(l) })
            }
        }
        OutlinedTextField(q, { q = it; if (it.isEmpty() || it.length >= 3) vm.rechercherInterventions(it) }, singleLine = true,
            label = { Text("Rechercher (objet, référence…)") }, modifier = Modifier.fillMaxWidth().padding(12.dp))
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(12.dp, 0.dp, 12.dp, 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (vm.interventions.isEmpty() && !vm.chargement) item {
                Text(when (vm.filtre) { "file" -> "Aucune panne en attente de prise en charge."; "moi" -> "Aucune tâche ne vous est assignée."; else -> "Aucune panne ouverte." }, Modifier.padding(16.dp))
            }
            items(vm.interventions, key = { it.id }) { t -> CarteIntervention(t, vm) }
        }
    }
}

@Composable
private fun CarteIntervention(t: Tache, vm: AppViewModel) {
    Card(onClick = { vm.ouvrirDetail(t.id) }, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                Pastille(t.statut, couleurStatutPublic(t.statut))
                Pastille(t.priorite, if (t.priorite == "Haute") Color(0xFFC62828) else Color.DarkGray)
                if (t.sla.retardPriseEnCharge || t.sla.retardResolution) Pastille("En retard", Color(0xFFC62828))
                Text(t.reference ?: "", style = MaterialTheme.typography.labelMedium)
            }
            Text(t.titre, style = MaterialTheme.typography.titleMedium)
            Text(listOfNotNull(t.declarant?.nom?.let { "Par $it" }, t.service, t.materiel?.libelle).joinToString(" · "), style = MaterialTheme.typography.bodySmall)
            if (t.assigne != null && !t.enFile) Text("Assignée à ${t.assigne.nom}", style = MaterialTheme.typography.bodySmall)
            if (t.actions.prendre) Button({ vm.prendre(t.id) }, enabled = !vm.chargement) { Text("Je prends") }
        }
    }
}

fun couleurStatutPublic(s: String) = when (s) {
    "Terminé" -> Color(0xFF2E7D32); "En cours" -> Color(0xFF1F5FBF); "En attente" -> Color(0xFFEF6C00)
    "Annulé" -> Color(0xFF757575); else -> Color(0xFFC62828)
}

/** Boutons d'équipe du détail : affecter, changer le statut / rouvrir, note interne. */
@Composable
fun ActionsAgent(vm: AppViewModel, t: Tache) {
    var dialogue by remember { mutableStateOf<String?>(null) }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            if (t.actions.affecter) OutlinedButton({ vm.chargerAgents(); dialogue = "affecter" }) { Text("Affecter") }
            if (t.actions.changerStatut) OutlinedButton({ dialogue = "statut" }) { Text("Changer le statut") }
            else if (t.actions.rouvrir) OutlinedButton({ dialogue = "statut" }) { Text("Rouvrir") }
        }
        if (vm.estEquipe(t) && t.actions.commenter) {
            var texte by remember(t.id) { mutableStateOf("") }
            var interne by remember(t.id) { mutableStateOf(true) }
            OutlinedTextField(texte, { texte = it }, label = { Text("Commentaire") }, minLines = 2, modifier = Modifier.fillMaxWidth())
            Row(verticalAlignment = Alignment.CenterVertically) {
                Checkbox(interne, { interne = it }); Text("Note interne (invisible du déclarant)")
            }
            Button({ vm.commenterEquipe(texte.trim(), !interne); texte = "" }, enabled = texte.isNotBlank() && !vm.chargement) { Text("Ajouter") }
        }
    }
    when (dialogue) {
        "affecter" -> DialogueAffecter(vm, t) { dialogue = null }
        "statut" -> DialogueStatut(vm, t) { dialogue = null }
    }
}

@Composable
private fun DialogueAffecter(vm: AppViewModel, t: Tache, fermer: () -> Unit) {
    var commentaire by remember { mutableStateOf("") }
    AlertDialog(onDismissRequest = fermer, title = { Text("Affecter à") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                if (vm.agents.isEmpty()) Text("Chargement…")
                vm.agents.forEach { a ->
                    TextButton({ vm.affecter(a.id, commentaire.ifBlank { null }); fermer() }, Modifier.fillMaxWidth()) {
                        Text(if (a.fileCommune) "${a.nom} (remettre en file)" else "${a.nom} — ${a.charge} en cours", Modifier.fillMaxWidth())
                    }
                }
                OutlinedTextField(commentaire, { commentaire = it }, label = { Text("Message (facultatif)") }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = {}, dismissButton = { TextButton(fermer) { Text("Fermer") } })
}

@OptIn(ExperimentalMaterial3Api::class)
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
                    Row(verticalAlignment = Alignment.CenterVertically) { RadioButton(statut == s, { statut = s }); Text(s) }
                }
                if (attente) {
                    OutlinedTextField(motif, { motif = it }, label = { Text("Motif de l'attente *") }, modifier = Modifier.fillMaxWidth())
                    OutlinedTextField(relance, { relance = it }, singleLine = true, label = { Text("Date de relance (AAAA-MM-JJ) *") }, modifier = Modifier.fillMaxWidth())
                }
                OutlinedTextField(commentaire, { commentaire = it }, label = { Text("Commentaire (facultatif)") }, modifier = Modifier.fillMaxWidth())
            }
        },
        confirmButton = {
            TextButton({
                vm.changerStatut(statut, if (attente) motif.trim() else null, if (attente) relance.trim() else null, commentaire.ifBlank { null }); fermer()
            }, enabled = !attente || (motif.isNotBlank() && Regex("\\d{4}-\\d{2}-\\d{2}").matches(relance.trim()))) { Text("Valider") }
        },
        dismissButton = { TextButton(fermer) { Text("Annuler") } })
}
