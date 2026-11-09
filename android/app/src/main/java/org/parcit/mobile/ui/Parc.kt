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
import kotlinx.serialization.json.*
import org.parcit.mobile.AppViewModel
import org.parcit.mobile.data.*

private fun couleurMateriel(s: String) = when (s) {
    "En service" -> Color(0xFF2E7D32); "En panne" -> Color(0xFFC62828); "En attente" -> Color(0xFFEF6C00); else -> Color(0xFF757575)
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranParc(vm: AppViewModel) {
    var q by remember { mutableStateOf(vm.rechercheParc) }
    val u = vm.utilisateur
    LaunchedEffect(Unit) { vm.chargerParc() }
    Column(Modifier.fillMaxSize()) {
        TopAppBar(title = { Text("Parc") }, actions = {
            TextButton({ vm.ouvrirScan() }) { Text("Scanner") }
            if (u?.peut("materiel.creer") == true) TextButton({ vm.ouvrirFormulaireMateriel(null) }) { Text("+ Ajouter") }
        })
        OutlinedTextField(q, { q = it; if (it.isEmpty() || it.length >= 2) vm.rechercherParc(it, vm.statutParc) }, singleLine = true,
            label = { Text("Type, marque, modèle, série, utilisateur") }, modifier = Modifier.fillMaxWidth().padding(12.dp, 4.dp))
        Row(Modifier.padding(horizontal = 12.dp).verticalScroll(rememberScrollState(), enabled = false), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            FilterChip(vm.statutParc == null, { vm.rechercherParc(q, null) }, { Text("Tous") })
            listOf("En service", "En panne").forEach { s -> FilterChip(vm.statutParc == s, { vm.rechercherParc(q, s) }, { Text(s) }) }
        }
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (vm.parc.isEmpty() && !vm.chargement) item { Text("Aucun matériel.", Modifier.padding(16.dp)) }
            items(vm.parc, key = { it.id }) { f ->
                Card(onClick = { vm.ouvrirFiche(f.id) }, modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                            Pastille(f.statut, couleurMateriel(f.statut))
                            if (f.pannesOuvertes > 0) Pastille("${f.pannesOuvertes} panne(s)", Color(0xFFC62828))
                        }
                        Text(f.libelle, style = MaterialTheme.typography.titleMedium)
                        Text(listOfNotNull(f.utilisateur?.nom, f.service?.nom).joinToString(" · ").ifBlank { "Non affecté" }, style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranFiche(vm: AppViewModel) {
    val f = vm.fiche
    val u = vm.utilisateur
    Scaffold(topBar = {
        TopAppBar(title = { Text("Matériel") }, navigationIcon = { TextButton({ vm.retour() }) { Text("Retour") } },
            actions = { if (f != null && u?.peut("materiel.modifier") == true) TextButton({ vm.ouvrirFormulaireMateriel(f.id) }) { Text("Modifier") } })
    }) { pad ->
        if (f == null) { Box(Modifier.padding(pad).fillMaxSize()); return@Scaffold }
        Column(Modifier.padding(pad).padding(16.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(f.libelle, style = MaterialTheme.typography.headlineSmall)
            Pastille(f.statut, couleurMateriel(f.statut))
            listOf("Type" to f.type, "Marque" to f.marque, "Modèle" to f.modele, "N° de série" to f.numSerie, "Date d'achat" to f.dateAchat,
                "Utilisateur" to f.utilisateur?.nom, "Service" to f.service?.nom).forEach { (k, v) ->
                if (!v.isNullOrBlank()) Row { Text("$k : ", style = MaterialTheme.typography.labelLarge); Text(v) }
            }
            if (u?.peut("tache.declarer") == true || u?.peut("tache.creer") == true)
                Button({ vm.ouvrirFormulaire(Materiel(f.id, f.libelle)) }, modifier = Modifier.fillMaxWidth()) { Text("Déclarer une panne sur ce matériel") }
            if (vm.pannesMateriel.isNotEmpty()) {
                HorizontalDivider()
                Text("Pannes liées", style = MaterialTheme.typography.titleMedium)
                vm.pannesMateriel.forEach { t ->
                    TextButton({ vm.ouvrirDetail(t.id) }, Modifier.fillMaxWidth()) { Text("${t.reference ?: ""} · ${t.titre} (${t.statut})", Modifier.fillMaxWidth()) }
                }
            }
            if (vm.affectations.isNotEmpty()) {
                HorizontalDivider()
                Text("Historique d'affectation", style = MaterialTheme.typography.titleMedium)
                vm.affectations.forEach { a ->
                    Column {
                        Text(listOfNotNull(a.utilisateur, a.service).joinToString(" · ").ifBlank { "Non affecté" })
                        Text("depuis ${dateLisible(a.debut)}" + (a.fin?.let { " jusqu'au ${dateLisible(it)}" } ?: ""), style = MaterialTheme.typography.bodySmall, color = Color.Gray)
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranFormMateriel(vm: AppViewModel, id: Int?) {
    val f = if (id != null) vm.fiche?.takeIf { it.id == id } else null
    var type by remember { mutableStateOf(f?.type ?: "") }
    var marque by remember { mutableStateOf(f?.marque ?: "") }
    var modele by remember { mutableStateOf(f?.modele ?: "") }
    var serie by remember { mutableStateOf(f?.numSerie ?: "") }
    var achat by remember { mutableStateOf(f?.dateAchat ?: "") }
    var statut by remember { mutableStateOf(f?.statut?.ifBlank { null } ?: "En service") }
    var service by remember { mutableStateOf(f?.service?.let { s -> s.id?.let { Service(it, s.nom ?: "") } }) }
    var agent by remember { mutableStateOf(f?.utilisateur?.let { s -> s.id?.let { UtilisateurRef(it, s.nom ?: "") } }) }
    var serviceTouche by remember { mutableStateOf(false) }
    var agentTouche by remember { mutableStateOf(false) }
    val dateOk = achat.isBlank() || Regex("\\d{4}-\\d{2}-\\d{2}").matches(achat.trim())
    Scaffold(topBar = { TopAppBar(title = { Text(if (id == null) "Nouveau matériel" else "Modifier le matériel") }, navigationIcon = { TextButton({ vm.retour() }) { Text("Retour") } }) }) { pad ->
        Column(Modifier.padding(pad).padding(16.dp).verticalScroll(rememberScrollState()).imePadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            val types = vm.referentiels.typesMateriel.map { it.nom }
            if (types.isNotEmpty()) Choix("Type *", types, type.ifBlank { null }, { it }) { type = it ?: type }
            else OutlinedTextField(type, { type = it }, label = { Text("Type *") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(marque, { marque = it }, label = { Text("Marque") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(modele, { modele = it }, label = { Text("Modèle") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(serie, { serie = it }, label = { Text("Numéro de série") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(achat, { achat = it }, label = { Text("Date d'achat (AAAA-MM-JJ)") }, singleLine = true, isError = !dateOk, modifier = Modifier.fillMaxWidth())
            Choix("Statut *", vm.referentiels.statutsMateriel, statut, { it }) { statut = it ?: statut }
            Choix("Service", vm.referentiels.services, service, { it.nom }, optionnel = true) { service = it; serviceTouche = true }
            if (vm.utilisateursRef.isNotEmpty())
                Choix("Utilisateur", vm.utilisateursRef, agent, { it.nom }, optionnel = true) { agent = it; agentTouche = true }
            Button({
                vm.enregistrerMateriel(id, buildJsonObject {
                    put("type", type.trim()); put("statut", statut)
                    put("marque", marque.trim()); put("modele", modele.trim()); put("num_serie", serie.trim())
                    if (achat.isNotBlank() || id != null) put("date_achat", achat.trim().ifBlank { null })
                    // Ne toucher à l'affectation que si l'utilisateur l'a changée (les anciennes fiches peuvent n'avoir qu'un nom en texte).
                    if (id == null || serviceTouche) put("service_id", service?.id)
                    if (id == null || agentTouche) put("utilisateur_id", agent?.id)
                })
            }, enabled = type.isNotBlank() && dateOk && !vm.chargement, modifier = Modifier.fillMaxWidth()) { Text("Enregistrer") }
        }
    }
}
