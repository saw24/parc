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
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import org.parcit.mobile.AppViewModel
import org.parcit.mobile.data.*

// ------------------------------------------------------------------ Modifier une panne

@Composable
fun EcranFormPanne(vm: AppViewModel) {
    val t = vm.detail ?: return
    val equipe = t.actions.changerStatut || t.actions.affecter || vm.utilisateur?.voitInterventions == true
    var titre by remember { mutableStateOf(t.titre) }
    var desc by remember { mutableStateOf(t.description ?: "") }
    var prio by remember { mutableStateOf(t.priorite) }
    var service by remember(vm.referentiels) { mutableStateOf(vm.referentiels.services.firstOrNull { it.nom == t.service }) }
    Column(Modifier.fillMaxSize()) {
        EnTete("Modifier ${t.reference ?: "la panne"}", retour = { vm.retour() })
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Champ(titre, { titre = it }, "Titre *")
            Champ(desc, { desc = it }, "Détails", minLignes = 4)
            Text("Urgence", style = MaterialTheme.typography.titleSmall)
            SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
                val niveaux = vm.referentiels.priorites
                niveaux.forEachIndexed { i, p -> SegmentedButton(prio == p, { prio = p }, SegmentedButtonDefaults.itemShape(i, niveaux.size), label = { Text(p) }) }
            }
            Choix("Service concerné *", vm.referentiels.services, service, { it.nom }) { service = it ?: service }
            if (!equipe) Text("Vous pouvez modifier une panne tant que le service IT ne l'a pas prise en charge.",
                style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Surface(color = MaterialTheme.colorScheme.surfaceContainer, tonalElevation = 2.dp) {
            Box(Modifier.padding(16.dp)) {
                BoutonPrincipal("Enregistrer", {
                    vm.modifierPanne(buildJsonObject {
                        if (titre.trim() != t.titre) put("titre", titre.trim())
                        if (desc.trim() != (t.description ?: "")) put("description", desc.trim())
                        if (prio != t.priorite) put("priorite", prio)
                        service?.let { if (it.nom != t.service) put("service_id", it.id) }
                    })
                }, enabled = titre.isNotBlank() && service != null && !vm.chargement, icone = Icons.Rounded.Done)
            }
        }
    }
}

// ------------------------------------------------------------------ Utilisateurs

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranUtilisateurs(vm: AppViewModel) {
    val gere = vm.utilisateur?.gereUtilisateurs == true
    Scaffold(containerColor = MaterialTheme.colorScheme.background, floatingActionButton = {
        if (gere) ExtendedFloatingActionButton(text = { Text("Utilisateur") }, icon = { Icon(Icons.Rounded.PersonAdd, null) }, onClick = { vm.ouvrirFormUtilisateur(null) })
    }) { pad ->
        Column(Modifier.padding(pad).fillMaxSize()) {
            EnTete("Utilisateurs", "${vm.utilisateursAdmin.size} compte(s)", retour = { vm.retour() })
            Box(Modifier.padding(horizontal = 16.dp, vertical = 4.dp)) {
                var q by remember { mutableStateOf(vm.rechercheUtilisateurs) }
                Champ(q, { q = it; vm.rechercherUtilisateurs(it) }, "Rechercher (nom, identifiant)", icone = Icons.Rounded.Search)
            }
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (vm.utilisateursAdmin.isEmpty() && !vm.chargement) item { EtatVide(Icons.Rounded.Group, "Aucun utilisateur") }
                items(vm.utilisateursAdmin, key = { it.id }) { u ->
                    Carte(onClick = if (gere && !u.compteTechnique) ({ vm.ouvrirFormUtilisateur(u.id) }) else null) {
                        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            Avatar(u.nom, 40)
                            Column(Modifier.weight(1f)) {
                                Text(u.nom, style = MaterialTheme.typography.titleSmall)
                                Text(listOfNotNull(u.login, u.service?.nom).joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Puce(u.role, MaterialTheme.colorScheme.primary)
                                if (!u.actif) Puce("Inactif", MaterialTheme.colorScheme.error)
                                else if (!u.connexionAutorisee) Puce("Sans connexion", MaterialTheme.colorScheme.tertiary)
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun EcranFormUtilisateur(vm: AppViewModel, id: Int?) {
    val u = if (id != null) vm.utilisateursAdmin.firstOrNull { it.id == id } else null
    var nom by remember { mutableStateOf(u?.nom ?: "") }
    var login by remember { mutableStateOf(u?.login ?: "") }
    var email by remember { mutableStateOf(u?.email ?: "") }
    var tel by remember { mutableStateOf(u?.telephone ?: "") }
    var role by remember { mutableStateOf(u?.role) }
    var service by remember(vm.referentiels) { mutableStateOf(u?.service?.let { s -> vm.referentiels.services.firstOrNull { it.id == s.id } ?: s }) }
    var actif by remember { mutableStateOf(u?.actif ?: true) }
    var connexion by remember { mutableStateOf(u?.connexionAutorisee ?: true) }
    var mdp by remember { mutableStateOf("") }
    var supprimer by remember { mutableStateOf(false) }
    val moi = id != null && id == vm.utilisateur?.id
    val mdpOk = if (id == null) mdp.length >= 10 else mdp.isEmpty() || mdp.length >= 10
    Column(Modifier.fillMaxSize()) {
        EnTete(if (id == null) "Nouvel utilisateur" else "Modifier l'utilisateur", retour = { vm.retour() }, actions = {
            if (id != null && !moi) IconButton({ supprimer = true }) { Icon(Icons.Rounded.Delete, "Supprimer", tint = MaterialTheme.colorScheme.error) }
        })
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Champ(nom, { nom = it }, "Nom complet *", icone = Icons.Rounded.Person)
            Champ(login, { login = it.trim() }, "Identifiant *", icone = Icons.Rounded.AlternateEmail)
            Champ(email, { email = it.trim() }, "E-mail")
            Champ(tel, { tel = it }, "Téléphone (SMS)")
            Choix("Rôle *", vm.roles, vm.roles.firstOrNull { it.nom == role }, { it.nom }) { role = it?.nom ?: role }
            Choix("Service", vm.referentiels.services, service, { it.nom }, optionnel = true) { service = it }
            Champ(mdp, { mdp = it }, if (id == null) "Mot de passe * (10 caractères min.)" else "Nouveau mot de passe (laisser vide pour conserver)",
                erreur = !mdpOk, icone = Icons.Rounded.Lock, masque = true)
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) { Text("Compte actif"); Text("Un compte inactif n'apparaît plus dans les listes de choix.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                Switch(actif, { actif = it }, enabled = !moi)
            }
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) { Text("Connexion autorisée"); Text("Désactivée : le compte peut être affecté mais ne peut pas se connecter.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                Switch(connexion, { connexion = it }, enabled = !moi)
            }
            if (id != null) Text("Changer le rôle, le mot de passe ou désactiver le compte déconnecte l'utilisateur de tous ses appareils.",
                style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Surface(color = MaterialTheme.colorScheme.surfaceContainer, tonalElevation = 2.dp) {
            Box(Modifier.padding(16.dp)) {
                BoutonPrincipal("Enregistrer", {
                    vm.enregistrerUtilisateur(id, buildJsonObject {
                        put("nom", nom.trim()); put("login", login); put("email", email.ifBlank { null }); put("telephone", tel.trim().ifBlank { null })
                        put("role", role); put("service_id", service?.id)
                        if (!moi) { put("actif", actif); put("connexion_autorisee", connexion) }
                        if (mdp.isNotEmpty()) put("mot_de_passe", mdp)
                    })
                }, enabled = nom.isNotBlank() && login.length >= 3 && role != null && mdpOk && !vm.chargement, icone = Icons.Rounded.Done)
            }
        }
    }
    if (supprimer && u != null) AlertDialog(onDismissRequest = { supprimer = false },
        title = { Text("Supprimer ${u.nom} ?") },
        text = { Text("Le compte sera supprimé définitivement. Ses pannes et son historique sont conservés, mais ne lui seront plus rattachés.") },
        confirmButton = { TextButton({ supprimer = false; vm.supprimerUtilisateur(u.id) }, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error)) { Text("Supprimer") } },
        dismissButton = { TextButton({ supprimer = false }) { Text("Garder") } })
}

// ------------------------------------------------------------------ Services

@Composable
fun EcranServices(vm: AppViewModel) {
    val u = vm.utilisateur
    var edition by remember { mutableStateOf<ServiceAdmin?>(null) }
    var creation by remember { mutableStateOf(false) }
    var suppression by remember { mutableStateOf<ServiceAdmin?>(null) }
    Scaffold(containerColor = MaterialTheme.colorScheme.background, floatingActionButton = {
        if (u?.peut("referentiel.gerer") == true) ExtendedFloatingActionButton(text = { Text("Service") }, icon = { Icon(Icons.Rounded.Add, null) }, onClick = { creation = true })
    }) { pad ->
        Column(Modifier.padding(pad).fillMaxSize()) {
            EnTete("Services", "${vm.servicesAdmin.size} service(s)", retour = { vm.retour() })
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (vm.servicesAdmin.isEmpty() && !vm.chargement) item { EtatVide(Icons.Rounded.Business, "Aucun service") }
                items(vm.servicesAdmin, key = { it.id }) { s ->
                    Carte(onClick = if (u?.peut("referentiel.gerer") == true) ({ edition = s }) else null) {
                        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            Column(Modifier.weight(1f)) {
                                Text(s.nom, style = MaterialTheme.typography.titleSmall)
                                if (!s.description.isNullOrBlank()) Text(s.description, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                                Text("${s.nbUtilisateurs} utilisateur(s) · ${s.nbMateriels} matériel(s)", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            }
                            if (u?.peut("referentiel.supprimer") == true) IconButton({ suppression = s }) { Icon(Icons.Rounded.Delete, "Supprimer", tint = MaterialTheme.colorScheme.error) }
                        }
                    }
                }
            }
        }
    }
    if (creation || edition != null) {
        val s = edition
        var nom by remember(s) { mutableStateOf(s?.nom ?: "") }
        var desc by remember(s) { mutableStateOf(s?.description ?: "") }
        val fermer = { creation = false; edition = null }
        AlertDialog(onDismissRequest = fermer, title = { Text(if (s == null) "Nouveau service" else "Modifier le service") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Champ(nom, { nom = it }, "Nom *"); Champ(desc, { desc = it }, "Description", minLignes = 2)
                if (s != null) Text("Renommer un service met aussi à jour les pannes et le matériel qui y sont rattachés.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            } },
            confirmButton = { TextButton({ vm.enregistrerService(s?.id, nom, desc); fermer() }, enabled = nom.isNotBlank()) { Text("Enregistrer") } },
            dismissButton = { TextButton(fermer) { Text("Annuler") } })
    }
    suppression?.let { s ->
        AlertDialog(onDismissRequest = { suppression = null }, title = { Text("Supprimer « ${s.nom} » ?") },
            text = { Text(if (s.nbUtilisateurs + s.nbMateriels > 0) "Ce service est encore utilisé par ${s.nbUtilisateurs} utilisateur(s) et ${s.nbMateriels} matériel(s) : le serveur refusera la suppression tant qu'ils n'ont pas été réaffectés." else "Cette action est irréversible.") },
            confirmButton = { TextButton({ vm.supprimerService(s.id); suppression = null }, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error)) { Text("Supprimer") } },
            dismissButton = { TextButton({ suppression = null }) { Text("Garder") } })
    }
}
