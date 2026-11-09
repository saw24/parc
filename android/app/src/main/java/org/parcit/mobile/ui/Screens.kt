package org.parcit.mobile.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import org.parcit.mobile.AppViewModel
import org.parcit.mobile.Ecran
import org.parcit.mobile.data.*
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

@Composable
fun ParcTheme(content: @Composable () -> Unit) = MaterialTheme(
    colorScheme = lightColorScheme(primary = Color(0xFF1F5FBF)), content = content,
)

fun dateLisible(iso: String?): String = try {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault())
        .format(DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT))
} catch (e: Exception) { "" }

@Composable
private fun couleurStatut(s: String) = when (s) {
    "Terminé" -> Color(0xFF2E7D32); "En cours" -> Color(0xFF1F5FBF); "En attente" -> Color(0xFFEF6C00)
    "Annulé" -> Color(0xFF757575); else -> Color(0xFFC62828)
}

@Composable
fun Pastille(texte: String, couleur: Color) = Surface(color = couleur.copy(alpha = .14f), shape = MaterialTheme.shapes.small) {
    Text(texte, color = couleur, style = MaterialTheme.typography.labelMedium, modifier = Modifier.padding(horizontal = 8.dp, vertical = 2.dp))
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun Root(vm: AppViewModel) {
    val snack = remember { SnackbarHostState() }
    LaunchedEffect(vm.message) { vm.message?.let { snack.showSnackbar(it); vm.messageLu() } }
    Scaffold(snackbarHost = { SnackbarHost(snack) }) { pad ->
        Box(Modifier.padding(pad).fillMaxSize()) {
            if (vm.utilisateur == null) EcranConnexion(vm)
            else when (val e = vm.ecran) {
                Ecran.Liste -> EcranListe(vm)
                Ecran.Declarer -> { BackHandler { vm.retour() }; EcranDeclarer(vm) }
                is Ecran.Detail -> { BackHandler { vm.retour() }; EcranDetail(vm) }
            }
            if (vm.chargement) LinearProgressIndicator(Modifier.fillMaxWidth().align(Alignment.TopCenter))
        }
    }
}

@Composable
fun EcranConnexion(vm: AppViewModel) {
    var serveur by remember { mutableStateOf(vm.serveur) }
    var login by remember { mutableStateOf("") }
    var mdp by remember { mutableStateOf("") }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp).imePadding(), verticalArrangement = Arrangement.Center) {
        Text("Parc IT", style = MaterialTheme.typography.headlineLarge, color = MaterialTheme.colorScheme.primary)
        Text("Déclarez et suivez vos pannes", style = MaterialTheme.typography.bodyLarge)
        Spacer(Modifier.height(24.dp))
        OutlinedTextField(serveur, { serveur = it }, label = { Text("Adresse du serveur") }, singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), modifier = Modifier.fillMaxWidth())
        OutlinedTextField(login, { login = it }, label = { Text("Identifiant") }, singleLine = true, modifier = Modifier.fillMaxWidth())
        OutlinedTextField(mdp, { mdp = it }, label = { Text("Mot de passe") }, singleLine = true,
            visualTransformation = PasswordVisualTransformation(), modifier = Modifier.fillMaxWidth())
        Spacer(Modifier.height(16.dp))
        Button({ vm.connecter(serveur, login, mdp) }, enabled = !vm.chargement && login.isNotBlank() && mdp.isNotBlank() && serveur.isNotBlank(),
            modifier = Modifier.fillMaxWidth()) { Text("Se connecter") }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranListe(vm: AppViewModel) {
    var menu by remember { mutableStateOf(false) }
    Scaffold(
        topBar = {
            TopAppBar(title = { Text("Mes pannes") }, actions = {
                TextButton({ vm.actualiser() }) { Text("Actualiser") }
                Box {
                    TextButton({ menu = true }) { Text(vm.utilisateur?.nom ?: "Compte") }
                    DropdownMenu(menu, { menu = false }) {
                        DropdownMenuItem(text = { Text("Se déconnecter") }, onClick = { menu = false; vm.deconnecter() })
                    }
                }
            })
        },
        floatingActionButton = { ExtendedFloatingActionButton({ vm.ouvrirFormulaire() }) { Text("+ Déclarer une panne") } },
    ) { pad ->
        LazyColumn(Modifier.padding(pad).fillMaxSize(), contentPadding = PaddingValues(12.dp, 12.dp, 12.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            items(vm.brouillons, key = { it.uuid }) { b ->
                Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFFFF4E5))) {
                    Column(Modifier.padding(12.dp)) {
                        Text(b.titre, style = MaterialTheme.typography.titleMedium)
                        Text(b.erreur?.let { "Refusée : $it" } ?: "En attente d'envoi (réseau indisponible)", style = MaterialTheme.typography.bodySmall)
                        Row {
                            if (b.erreur == null) TextButton({ vm.reessayer(b.uuid) }) { Text("Réessayer") }
                            TextButton({ vm.abandonner(b.uuid) }) { Text("Abandonner") }
                        }
                    }
                }
            }
            if (vm.taches.isEmpty() && vm.brouillons.isEmpty() && !vm.chargement)
                item { Text("Aucune panne déclarée pour le moment.", Modifier.padding(16.dp)) }
            items(vm.taches, key = { it.id }) { t ->
                Card(onClick = { vm.ouvrirDetail(t.id) }, modifier = Modifier.fillMaxWidth()) {
                    Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                            Pastille(t.statut, couleurStatut(t.statut))
                            Text(t.reference ?: "", style = MaterialTheme.typography.labelMedium)
                        }
                        Text(t.titre, style = MaterialTheme.typography.titleMedium)
                        Text(
                            when {
                                t.assigne != null && !t.enFile -> "Pris en charge par ${t.assigne.nom}"
                                t.statut == "Terminé" || t.statut == "Annulé" -> ""
                                else -> "En attente de prise en charge"
                            }, style = MaterialTheme.typography.bodySmall,
                        )
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranDeclarer(vm: AppViewModel) {
    var titre by remember { mutableStateOf("") }
    var desc by remember { mutableStateOf("") }
    var prio by remember { mutableStateOf("Moyenne") }
    var service by remember { mutableStateOf<Service?>(vm.utilisateur?.service) }
    var materiel by remember { mutableStateOf<Materiel?>(null) }
    Scaffold(topBar = { TopAppBar(title = { Text("Déclarer une panne") }, navigationIcon = { TextButton({ vm.retour() }) { Text("Retour") } }) }) { pad ->
        Column(Modifier.padding(pad).padding(16.dp).verticalScroll(rememberScrollState()).imePadding(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            OutlinedTextField(titre, { titre = it }, label = { Text("Objet de la panne *") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(desc, { desc = it }, label = { Text("Description") }, minLines = 4, modifier = Modifier.fillMaxWidth())
            Choix("Priorité", vm.referentiels.priorites, prio, { it }) { prio = it ?: prio }
            Choix("Service", vm.referentiels.services, service, { it.nom }, optionnel = true) { service = it }
            if (vm.materiels.isNotEmpty())
                Choix("Matériel concerné", vm.materiels, materiel, { it.libelle }, optionnel = true) { materiel = it }
            Button({ vm.declarer(titre.trim(), desc.trim(), prio, service?.id, materiel?.id) }, enabled = titre.isNotBlank() && !vm.chargement,
                modifier = Modifier.fillMaxWidth()) { Text("Envoyer") }
        }
    }
}

/** Liste déroulante simple ; [optionnel] ajoute une entrée « Aucun ». */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun <T> Choix(label: String, options: List<T>, valeur: T?, texte: (T) -> String, optionnel: Boolean = false, onChoix: (T?) -> Unit) {
    var ouvert by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(ouvert, { ouvert = it }) {
        OutlinedTextField(valeur?.let(texte) ?: "Aucun", {}, readOnly = true, label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(ouvert) },
            modifier = Modifier.menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable).fillMaxWidth())
        ExposedDropdownMenu(ouvert, { ouvert = false }) {
            if (optionnel) DropdownMenuItem({ Text("Aucun") }, { onChoix(null); ouvert = false })
            options.forEach { o -> DropdownMenuItem({ Text(texte(o)) }, { onChoix(o); ouvert = false }) }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranDetail(vm: AppViewModel) {
    val t = vm.detail
    var commentaire by remember { mutableStateOf("") }
    var confirmer by remember { mutableStateOf(false) }
    Scaffold(topBar = { TopAppBar(title = { Text(t?.reference ?: "Panne") }, navigationIcon = { TextButton({ vm.retour() }) { Text("Retour") } }) }) { pad ->
        if (t == null) { Box(Modifier.padding(pad).fillMaxSize()); return@Scaffold }
        Column(Modifier.padding(pad).padding(16.dp).verticalScroll(rememberScrollState()).imePadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(t.titre, style = MaterialTheme.typography.headlineSmall)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) { Pastille(t.statut, couleurStatut(t.statut)); Pastille("Priorité ${t.priorite}", Color.DarkGray) }
            Text(when {
                t.assigne != null && !t.enFile -> "Pris en charge par ${t.assigne.nom}"
                t.statut == "Terminé" || t.statut == "Annulé" -> "Clôturée"
                else -> "En attente de prise en charge par le service IT"
            }, style = MaterialTheme.typography.titleSmall)
            if (!t.description.isNullOrBlank()) Text(t.description)
            t.materiel?.libelle?.let { Text("Matériel : $it") }
            t.service?.let { Text("Service : $it") }
            if (t.statut == "En attente" && !t.motifAttente.isNullOrBlank()) Text("Motif d'attente : ${t.motifAttente}")
            HorizontalDivider()
            Text("Historique", style = MaterialTheme.typography.titleMedium)
            t.evenements.forEach { e ->
                Column {
                    Text(libelleEvenement(e), style = MaterialTheme.typography.bodyMedium)
                    Text(dateLisible(e.createdAt), style = MaterialTheme.typography.bodySmall, color = Color.Gray)
                }
            }
            if (t.actions.commenter) {
                OutlinedTextField(commentaire, { commentaire = it }, label = { Text("Ajouter un commentaire") }, modifier = Modifier.fillMaxWidth())
                Button({ vm.commenter(commentaire.trim()); commentaire = "" }, enabled = commentaire.isNotBlank() && !vm.chargement) { Text("Envoyer le commentaire") }
            }
            if (t.actions.annuler) OutlinedButton({ confirmer = true }) { Text("Annuler cette panne") }
        }
        if (confirmer) AlertDialog(onDismissRequest = { confirmer = false },
            title = { Text("Annuler la panne ?") }, text = { Text("Le service IT sera informé que la panne est annulée.") },
            confirmButton = { TextButton({ confirmer = false; vm.annuler("Annulée par le déclarant") }) { Text("Annuler la panne") } },
            dismissButton = { TextButton({ confirmer = false }) { Text("Garder") } })
    }
}

private fun libelleEvenement(e: Evenement): String {
    val qui = e.acteur?.nom ?: "Système"
    return when (e.type) {
        "creation" -> "Panne déclarée par $qui"
        "prise_en_charge" -> "$qui a pris la panne en charge"
        "affectation" -> "Affectée à ${e.nouveauAssigne?.nom ?: "la file Service IT"}"
        "statut" -> "$qui : ${e.ancienStatut ?: "?"} → ${e.nouveauStatut ?: "?"}"
        "commentaire" -> "$qui : ${e.commentaire ?: ""}"
        else -> "${e.type} ($qui)"
    } + if (e.type != "commentaire" && !e.commentaire.isNullOrBlank()) "\n« ${e.commentaire} »" else ""
}
