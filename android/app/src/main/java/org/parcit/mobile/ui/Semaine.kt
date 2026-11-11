package org.parcit.mobile.ui

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import org.parcit.mobile.AppViewModel
import org.parcit.mobile.data.EvenementPlanning
import java.time.LocalDate
import java.time.format.TextStyle
import java.util.Locale

private fun libelleType(t: String) = when (t) {
    "echeance" -> "Échéance"; "preventif" -> "Préventif"; "plan" -> "Plan à venir"; "relance" -> "Relance"; "garantie" -> "Garantie"; else -> t
}

@Composable
private fun couleurType(t: String, retard: Boolean): Color = when {
    retard -> MaterialTheme.colorScheme.error
    t == "preventif" -> Color(0xFF0D9488)
    t == "plan" -> Color(0xFF7C3AED)
    t == "relance" -> Color(0xFFD97706)
    t == "garantie" -> Color(0xFF64748B)
    else -> MaterialTheme.colorScheme.primary
}

@Composable
fun EcranSemaine(vm: AppViewModel) {
    val debut = vm.debutSemaine
    val aujourdhui = LocalDate.now()
    val parJour = vm.evenementsSemaine.groupBy { it.date }
    val equipe = vm.utilisateur?.voitInterventions == true
    val fmt = { d: LocalDate -> "${d.dayOfMonth} ${d.month.getDisplayName(TextStyle.SHORT, Locale.FRENCH)}" }

    Column(Modifier.fillMaxSize()) {
        EnTete(if (vm.semaineMoi || !equipe) "Ma semaine" else "Planning de l'équipe", "${fmt(debut)} – ${fmt(debut.plusDays(6))}", retour = { vm.retour() })
        Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
            IconButton({ vm.changerSemaine(-1) }) { Icon(Icons.Rounded.ChevronLeft, "Semaine précédente") }
            TextButton({ vm.changerSemaine(0) }) { Text("Cette semaine") }
            IconButton({ vm.changerSemaine(1) }) { Icon(Icons.Rounded.ChevronRight, "Semaine suivante") }
            Spacer(Modifier.weight(1f))
            if (equipe) FilterChip(vm.semaineMoi, { vm.basculerSemaineMoi() }, { Text("Mes échéances") })
        }
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 4.dp, 16.dp, 32.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            (0L..6L).map { debut.plusDays(it) }.forEach { jour ->
                val liste = parJour[jour.toString()].orEmpty()
                item(key = jour.toString()) {
                    Carte(couleur = if (jour == aujourdhui) MaterialTheme.colorScheme.primaryContainer else MaterialTheme.colorScheme.surfaceContainer) {
                        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(jour.dayOfWeek.getDisplayName(TextStyle.FULL, Locale.FRENCH).replaceFirstChar { it.uppercase() } + " ${jour.dayOfMonth}",
                                    Modifier.weight(1f), style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold)
                                if (jour == aujourdhui) Puce("Aujourd'hui", MaterialTheme.colorScheme.primary)
                            }
                            if (liste.isEmpty()) Text("Rien de prévu", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            liste.forEach { e -> LigneEvenement(e) { e.tache?.let { vm.ouvrirDetail(it.id) } } }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun LigneEvenement(e: EvenementPlanning, ouvrir: () -> Unit) {
    Surface(Modifier.fillMaxWidth(), color = Color.Transparent) {
        Row(Modifier.then(if (e.tache != null) Modifier.clickable(onClick = ouvrir) else Modifier).padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Puce(libelleType(e.type) + if (e.retard) " · retard" else "", couleurType(e.type, e.retard))
            Column(Modifier.weight(1f)) {
                Text(listOfNotNull(e.tache?.reference, e.titre).joinToString(" · "), style = MaterialTheme.typography.bodyMedium)
                val sous = listOfNotNull(e.detail, e.assigne, e.tache?.statut?.takeIf { it.isNotBlank() }).joinToString(" · ")
                if (sous.isNotBlank()) Text(sous, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}
