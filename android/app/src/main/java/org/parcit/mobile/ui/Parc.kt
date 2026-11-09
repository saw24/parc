package org.parcit.mobile.ui

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.serialization.json.*
import org.parcit.mobile.AppViewModel
import org.parcit.mobile.data.*

private fun iconeType(type: String?): ImageVector {
    val t = (type ?: "").lowercase()
    return when {
        "imprim" in t -> Icons.Rounded.Print
        "phone" in t || "téléph" in t || "telephone" in t || "tablette" in t -> Icons.Rounded.PhoneAndroid
        "écran" in t || "ecran" in t || "moniteur" in t -> Icons.Rounded.Monitor
        "serveur" in t -> Icons.Rounded.Dns
        "routeur" in t || "switch" in t || "réseau" in t || "reseau" in t || "wifi" in t -> Icons.Rounded.Router
        "portable" in t || "laptop" in t -> Icons.Rounded.Laptop
        else -> Icons.Rounded.Computer
    }
}

@Composable
fun EcranParc(vm: AppViewModel) {
    var q by remember { mutableStateOf(vm.rechercheParc) }
    val u = vm.utilisateur
    LaunchedEffect(Unit) { vm.chargerParc() }
    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            EnTete("Parc", "${vm.parc.size} équipement(s)", actions = {
                IconButton({ vm.ouvrirScan() }) { Icon(Icons.Rounded.QrCodeScanner, "Scanner une étiquette") }
            })
            Champ(q, { q = it; if (it.isEmpty() || it.length >= 2) vm.rechercherParc(it, vm.statutParc) }, "Type, marque, série, utilisateur…",
                Modifier.padding(horizontal = 16.dp), icone = Icons.Rounded.Search)
            Row(Modifier.horizontalScroll(rememberScrollState()).padding(16.dp, 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(vm.statutParc == null, { vm.rechercherParc(q, null) }, { Text("Tous") })
                listOf("En service", "En panne", "En attente", "Retiré").forEach { s ->
                    FilterChip(vm.statutParc == s, { vm.rechercherParc(q, s) }, { Text(s) })
                }
            }
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 0.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (vm.parc.isEmpty() && !vm.chargement) item { EtatVide(Icons.Rounded.Computer, "Aucun matériel", "Modifiez la recherche ou le filtre.") }
                items(vm.parc, key = { it.id }) { f ->
                    Carte(onClick = { vm.ouvrirFiche(f.id) }) {
                        Row(Modifier.padding(14.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
                            Surface(color = couleurMateriel(f.statut).copy(alpha = .14f), shape = MaterialTheme.shapes.medium, modifier = Modifier.size(48.dp)) {
                                Icon(iconeType(f.type), null, Modifier.padding(12.dp), tint = couleurMateriel(f.statut))
                            }
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                                Text(f.libelle, style = MaterialTheme.typography.titleSmall, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                Text(listOfNotNull(f.utilisateur?.nom, f.service?.nom).joinToString(" · ").ifBlank { "Non affecté" },
                                    style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                    Puce(f.statut, couleurMateriel(f.statut))
                                    if (f.pannesOuvertes > 0) Puce("${f.pannesOuvertes} panne(s)", MaterialTheme.colorScheme.error, icone = Icons.Rounded.ReportProblem)
                                }
                            }
                            Icon(Icons.Rounded.ChevronRight, null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                    }
                }
            }
        }
        if (u?.peut("materiel.creer") == true) ExtendedFloatingActionButton(
            text = { Text("Ajouter") }, icon = { Icon(Icons.Rounded.Add, null) },
            onClick = { vm.ouvrirFormulaireMateriel(null) }, modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp),
        )
    }
}

@Composable
fun EcranFiche(vm: AppViewModel) {
    val f = vm.fiche
    val u = vm.utilisateur
    Column(Modifier.fillMaxSize()) {
        EnTete("Matériel", retour = { vm.retour() }, actions = {
            if (f != null && u?.peut("materiel.modifier") == true) IconButton({ vm.ouvrirFormulaireMateriel(f.id) }) { Icon(Icons.Rounded.Edit, "Modifier") }
        })
        if (f == null) { Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }; return@Column }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Carte {
                Row(Modifier.padding(16.dp), horizontalArrangement = Arrangement.spacedBy(14.dp), verticalAlignment = Alignment.CenterVertically) {
                    Surface(color = couleurMateriel(f.statut).copy(alpha = .14f), shape = MaterialTheme.shapes.large, modifier = Modifier.size(60.dp)) {
                        Icon(iconeType(f.type), null, Modifier.padding(16.dp), tint = couleurMateriel(f.statut))
                    }
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(f.libelle, style = MaterialTheme.typography.titleLarge)
                        Puce(f.statut, couleurMateriel(f.statut))
                    }
                }
            }
            Carte {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    LigneInfo(Icons.Rounded.Category, "Type", f.type)
                    LigneInfo(Icons.Rounded.Memory, "Marque / modèle", listOfNotNull(f.marque, f.modele).joinToString(" ").ifBlank { null })
                    LigneInfo(Icons.Rounded.QrCode2, "Numéro de série", f.numSerie)
                    LigneInfo(Icons.Rounded.Event, "Date d'achat", f.dateAchat)
                    LigneInfo(Icons.Rounded.Person, "Utilisateur", f.utilisateur?.nom)
                    LigneInfo(Icons.Rounded.Business, "Service", f.service?.nom)
                }
            }
            if (u?.peut("tache.declarer") == true || u?.peut("tache.creer") == true)
                BoutonPrincipal("Déclarer une panne sur ce matériel", { vm.ouvrirFormulaire(Materiel(f.id, f.libelle)) }, icone = Icons.Rounded.ReportProblem)
            if (vm.pannesMateriel.isNotEmpty()) {
                Titre("Pannes liées")
                vm.pannesMateriel.forEach { t -> CartePanne(t) { vm.ouvrirDetail(t.id) } }
            }
            if (vm.affectations.isNotEmpty()) {
                Titre("Historique d'affectation")
                Carte {
                    Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        vm.affectations.forEach { a ->
                            Column {
                                Text(listOfNotNull(a.utilisateur, a.service).joinToString(" · ").ifBlank { "Non affecté" }, style = MaterialTheme.typography.bodyLarge)
                                Text("depuis ${dateLisible(a.debut)}" + (a.fin?.let { " jusqu'au ${dateLisible(it)}" } ?: ""), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                        }
                    }
                }
            }
        }
    }
}

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
    Column(Modifier.fillMaxSize()) {
        EnTete(if (id == null) "Nouveau matériel" else "Modifier le matériel", retour = { vm.retour() })
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            val types = vm.referentiels.typesMateriel.map { it.nom }
            if (types.isNotEmpty()) Choix("Type *", types, type.ifBlank { null }, { it }) { type = it ?: type }
            else Champ(type, { type = it }, "Type *")
            Champ(marque, { marque = it }, "Marque")
            Champ(modele, { modele = it }, "Modèle")
            Champ(serie, { serie = it }, "Numéro de série", icone = Icons.Rounded.QrCode2)
            Champ(achat, { achat = it }, "Date d'achat (AAAA-MM-JJ)", erreur = !dateOk)
            Choix("Statut *", vm.referentiels.statutsMateriel, statut, { it }) { statut = it ?: statut }
            Choix("Service", vm.referentiels.services, service, { it.nom }, optionnel = true) { service = it; serviceTouche = true }
            if (vm.utilisateursRef.isNotEmpty())
                Choix("Utilisateur", vm.utilisateursRef, agent, { it.nom }, optionnel = true) { agent = it; agentTouche = true }
        }
        Surface(color = MaterialTheme.colorScheme.surfaceContainer, tonalElevation = 2.dp) {
            Box(Modifier.padding(16.dp)) {
                BoutonPrincipal("Enregistrer", {
                    vm.enregistrerMateriel(id, buildJsonObject {
                        put("type", type.trim()); put("statut", statut)
                        put("marque", marque.trim()); put("modele", modele.trim()); put("num_serie", serie.trim())
                        if (achat.isNotBlank() || id != null) put("date_achat", achat.trim().ifBlank { null })
                        // Ne toucher à l'affectation que si l'utilisateur l'a changée (les anciennes fiches peuvent n'avoir qu'un nom en texte).
                        if (id == null || serviceTouche) put("service_id", service?.id)
                        if (id == null || agentTouche) put("utilisateur_id", agent?.id)
                    })
                }, enabled = type.isNotBlank() && dateOk && !vm.chargement, icone = Icons.Rounded.Done)
            }
        }
    }
}
