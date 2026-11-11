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
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import kotlinx.coroutines.delay
import org.parcit.mobile.AppViewModel
import org.parcit.mobile.data.*

@Composable
private fun couleurStatutInv(s: String): Color = when (s) {
    "Présent" -> Color(0xFF16A34A)
    "Déplacé" -> Color(0xFFD97706)
    "Manquant" -> MaterialTheme.colorScheme.error
    "Non référencé" -> Color(0xFF7C3AED)
    else -> MaterialTheme.colorScheme.onSurfaceVariant
}

@Composable
fun EcranInventaires(vm: AppViewModel) {
    Column(Modifier.fillMaxSize()) {
        EnTete("Inventaires", "${vm.inventaires.count { it.statut == "Ouvert" }} campagne(s) ouverte(s)", retour = { vm.retour() })
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 12.dp, 16.dp, 32.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            if (vm.inventaires.isEmpty() && !vm.chargement) item { EtatVide(Icons.Rounded.Checklist, "Aucune campagne", "Les campagnes d'inventaire se créent depuis le site web.") }
            items(vm.inventaires, key = { it.id }) { i ->
                val c = i.compteurs
                Carte(onClick = { vm.ouvrirInventaire(i.id) }) {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(i.titre, Modifier.weight(1f), style = MaterialTheme.typography.titleSmall)
                            Puce(i.statut, if (i.statut == "Ouvert") MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Text(listOfNotNull(i.perimetre.service, i.perimetre.type).joinToString(" · ").ifBlank { "Tout le parc" } + (i.dateLimite?.let { " · avant le $it" } ?: ""),
                            style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        LinearProgressIndicator(progress = { if (c.attendus == 0) 0f else c.pointes.toFloat() / c.attendus }, Modifier.fillMaxWidth())
                        Text("${c.pointes} / ${c.attendus} pointés · ${c.deplaces} déplacé(s) · ${c.manquants} manquant(s)", style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }
    }
}

@Composable
fun EcranInventaire(vm: AppViewModel) {
    val inv = vm.inventaire
    var filtre by remember { mutableStateOf("À pointer") }
    var scan by remember { mutableStateOf(false) }
    var choix by remember { mutableStateOf<LigneInv?>(null) }
    var confirmerCloture by remember { mutableStateOf(false) }
    val ouvert = inv?.statut == "Ouvert"
    val peutPointer = vm.utilisateur?.pointeInventaires == true && ouvert

    Column(Modifier.fillMaxSize()) {
        EnTete(inv?.titre ?: "Inventaire", inv?.let { "${it.compteurs.pointes} / ${it.compteurs.attendus} pointés" }, retour = { vm.retour() })
        if (inv == null) { EtatVide(Icons.Rounded.Checklist, "Chargement…"); return@Column }
        val c = inv.compteurs
        Column(Modifier.padding(horizontal = 16.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            LinearProgressIndicator(progress = { if (c.attendus == 0) 0f else c.pointes.toFloat() / c.attendus }, Modifier.fillMaxWidth())
            Text("Présents ${c.presents} · Déplacés ${c.deplaces} · Manquants ${c.manquants} · À pointer ${c.aPointer}" +
                (if (c.horsPerimetre + c.nonReferences > 0) " · En plus ${c.horsPerimetre + c.nonReferences}" else ""), style = MaterialTheme.typography.bodySmall)
            inv.description?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            if (peutPointer) BoutonPrincipal("Scanner les étiquettes", { scan = true }, icone = Icons.Rounded.QrCodeScanner)
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.fillMaxWidth()) {
                listOf("À pointer", "Présent", "Déplacé", "Manquant", "Tout").forEach { f -> FilterChip(filtre == f, { filtre = f }, { Text(f, maxLines = 1) }) }
            }
        }
        val lignes = inv.lignes.filter { filtre == "Tout" || it.statut == filtre || (filtre == "Présent" && it.horsPerimetre && it.statut != "Non référencé") }
        LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(16.dp, 4.dp, 16.dp, 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (lignes.isEmpty()) item { EtatVide(Icons.Rounded.CheckCircle, if (filtre == "À pointer") "Tout est pointé" else "Aucune ligne") }
            items(lignes, key = { it.id }) { l ->
                Carte(onClick = if (peutPointer && l.materiel != null) ({ choix = l }) else null) {
                    Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Column(Modifier.weight(1f)) {
                            Text(l.materiel?.libelle ?: (l.codeScanne ?: "?"), style = MaterialTheme.typography.titleSmall)
                            val sous = listOfNotNull(l.materiel?.service, l.materiel?.utilisateur, if (l.horsPerimetre) "hors périmètre" else null, l.observation).joinToString(" · ")
                            if (sous.isNotBlank()) Text(sous, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Puce(l.statut, couleurStatutInv(l.statut))
                    }
                }
            }
        }
        if (vm.utilisateur?.gereInventaires == true && ouvert) {
            Surface(color = MaterialTheme.colorScheme.surfaceContainer, tonalElevation = 2.dp) {
                Box(Modifier.padding(16.dp)) { BoutonSecondaire("Clore la campagne", { confirmerCloture = true }, icone = Icons.Rounded.Lock, enabled = !vm.chargement) }
            }
        }
    }

    if (scan) Dialog({ scan = false }, DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
        Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            Column(Modifier.fillMaxSize().systemBarsPadding()) {
                EnTete("Pointage", "${vm.inventaire?.compteurs?.pointes ?: 0} / ${vm.inventaire?.compteurs?.attendus ?: 0} pointés", retour = { scan = false })
                // Scan continu : la zone est recréée après chaque lecture (avec un court délai contre les doubles lectures).
                var cle by remember { mutableIntStateOf(0) }
                key(cle) { ZoneScan(Modifier.weight(1f).fillMaxWidth().padding(16.dp)) { code -> vm.pointer(null, code) } }
                LaunchedEffect(vm.dernierPointage) { if (vm.dernierPointage != null) { delay(1800); cle++ } }
                Text(vm.dernierPointage ?: "Scannez une étiquette pour la pointer comme « Présent ».", Modifier.padding(16.dp), style = MaterialTheme.typography.titleSmall)
            }
        }
    }

    choix?.let { l ->
        var observation by remember { mutableStateOf(l.observation ?: "") }
        AlertDialog(onDismissRequest = { choix = null }, title = { Text(l.materiel?.libelle ?: "") },
            text = { Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Champ(observation, { observation = it }, "Observation (où est-il ?)", minLignes = 2)
                Text("Obligatoire pour « Déplacé ».", style = MaterialTheme.typography.bodySmall)
            } },
            confirmButton = { Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                TextButton({ vm.pointer(l.materiel!!.id, null, "Présent", observation); choix = null }) { Text("Présent") }
                TextButton({ vm.pointer(l.materiel!!.id, null, "Déplacé", observation); choix = null }, enabled = observation.isNotBlank()) { Text("Déplacé") }
                TextButton({ vm.pointer(l.materiel!!.id, null, "Manquant", observation); choix = null }) { Text("Manquant") }
            } },
            dismissButton = { TextButton({ choix = null }) { Text("Annuler") } })
    }

    if (confirmerCloture) AlertDialog(onDismissRequest = { confirmerCloture = false }, title = { Text("Clore la campagne ?") },
        text = { Text("Les ${vm.inventaire?.compteurs?.aPointer ?: 0} matériel(s) non pointé(s) seront marqués « Manquant ». Nécessite une connexion.") },
        confirmButton = { TextButton({ confirmerCloture = false; vm.cloturerInventaire() }) { Text("Clore") } },
        dismissButton = { TextButton({ confirmerCloture = false }) { Text("Annuler") } })
}
