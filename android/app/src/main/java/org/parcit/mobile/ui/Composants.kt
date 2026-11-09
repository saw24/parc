package org.parcit.mobile.ui

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import org.parcit.mobile.data.Evenement
import java.time.Duration
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

// ------------------------------------------------------------------ Dates

fun dateLisible(iso: String?): String = try {
    OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault())
        .format(DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT))
} catch (e: Exception) { "" }

/** « il y a 5 min », « hier », sinon la date. */
fun dateRelative(iso: String?): String = try {
    val d = OffsetDateTime.parse(iso)
    val m = Duration.between(d, OffsetDateTime.now()).toMinutes()
    when {
        m < 1 -> "à l'instant"; m < 60 -> "il y a $m min"; m < 60 * 24 -> "il y a ${m / 60} h"; m < 60 * 48 -> "hier"
        else -> d.atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM))
    }
} catch (e: Exception) { "" }

// ------------------------------------------------------------------ Couleurs métier

/** Couleur du texte/du point pour un statut de panne ; le fond est cette couleur à faible opacité. */
fun couleurStatutPublic(s: String) = when (s) {
    "Terminé" -> Color(0xFF16A34A); "En cours" -> Color(0xFF2563EB); "En attente" -> Color(0xFF7C3AED)
    "Annulé" -> Color(0xFF64748B); else -> Color(0xFFD97706)   // À faire
}

fun couleurPriorite(p: String) = when (p) { "Haute" -> Color(0xFFDC2626); "Basse" -> Color(0xFF64748B); else -> Color(0xFFD97706) }

fun couleurMateriel(s: String) = when (s) {
    "En service" -> Color(0xFF16A34A); "En panne" -> Color(0xFFDC2626); "En attente" -> Color(0xFFD97706); else -> Color(0xFF64748B)
}

// ------------------------------------------------------------------ Éléments de base

@Composable
fun Puce(texte: String, couleur: Color, modifier: Modifier = Modifier, icone: ImageVector? = null) = Surface(
    color = couleur.copy(alpha = .14f), shape = CircleShape, modifier = modifier,
) {
    Row(Modifier.padding(horizontal = 10.dp, vertical = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        if (icone != null) Icon(icone, null, Modifier.size(12.dp), tint = couleur)
        Text(texte, color = couleur, style = MaterialTheme.typography.labelMedium, maxLines = 1)
    }
}

@Composable fun PuceStatut(statut: String) = Puce(statut, couleurStatutPublic(statut))
@Composable fun Pastille(texte: String, couleur: Color) = Puce(texte, couleur)

@Composable
fun Carte(modifier: Modifier = Modifier, onClick: (() -> Unit)? = null, couleur: Color = MaterialTheme.colorScheme.surfaceContainer, contenu: @Composable ColumnScope.() -> Unit) {
    val bordure = BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant)
    if (onClick != null) Card(onClick, modifier.fillMaxWidth(), shape = MaterialTheme.shapes.medium,
        colors = CardDefaults.cardColors(containerColor = couleur), border = bordure, content = contenu)
    else Card(modifier.fillMaxWidth(), shape = MaterialTheme.shapes.medium,
        colors = CardDefaults.cardColors(containerColor = couleur), border = bordure, content = contenu)
}

/** En-tête de page : bouton retour facultatif, titre, actions à droite. */
@Composable
fun EnTete(titre: String, sousTitre: String? = null, retour: (() -> Unit)? = null, actions: @Composable RowScope.() -> Unit = {}) {
    Row(Modifier.fillMaxWidth().padding(start = if (retour != null) 4.dp else 20.dp, end = 8.dp, top = 8.dp, bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        if (retour != null) IconButton(retour) { Icon(Icons.AutoMirrored.Rounded.ArrowBack, "Retour") }
        Column(Modifier.weight(1f).padding(start = if (retour != null) 4.dp else 0.dp)) {
            Text(titre, style = if (retour != null) MaterialTheme.typography.titleLarge else MaterialTheme.typography.headlineMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (sousTitre != null) Text(sousTitre, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        actions()
    }
}

@Composable
fun EtatVide(icone: ImageVector = Icons.Rounded.Inbox, titre: String, texte: String? = null) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 32.dp, vertical = 48.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = CircleShape) {
            Icon(icone, null, Modifier.padding(18.dp).size(32.dp), tint = MaterialTheme.colorScheme.onPrimaryContainer)
        }
        Text(titre, style = MaterialTheme.typography.titleMedium)
        if (texte != null) Text(texte, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
fun LigneInfo(icone: ImageVector, libelle: String, valeur: String?) {
    if (valeur.isNullOrBlank()) return
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        Icon(icone, null, Modifier.size(20.dp).padding(top = 2.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
        Column {
            Text(libelle, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            Text(valeur, style = MaterialTheme.typography.bodyLarge)
        }
    }
}

@Composable
fun Titre(texte: String, modifier: Modifier = Modifier) =
    Text(texte, style = MaterialTheme.typography.titleMedium, modifier = modifier.padding(top = 8.dp))

@Composable
fun Avatar(nom: String?, taille: Int = 32) {
    val ini = (nom ?: "?").split(' ', '-').filter { it.isNotBlank() }.take(2).joinToString("") { it.first().uppercase() }.ifBlank { "?" }
    Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = CircleShape, modifier = Modifier.size(taille.dp)) {
        Box(contentAlignment = Alignment.Center) {
            Text(ini, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onPrimaryContainer)
        }
    }
}

@Composable
fun BoutonPrincipal(texte: String, onClick: () -> Unit, modifier: Modifier = Modifier, enabled: Boolean = true, icone: ImageVector? = null) =
    Button(onClick, modifier.fillMaxWidth().heightIn(min = 52.dp), enabled = enabled, shape = MaterialTheme.shapes.medium) {
        if (icone != null) { Icon(icone, null, Modifier.size(18.dp)); Spacer(Modifier.width(8.dp)) }
        Text(texte)
    }

@Composable
fun BoutonSecondaire(texte: String, onClick: () -> Unit, modifier: Modifier = Modifier, enabled: Boolean = true, icone: ImageVector? = null) =
    FilledTonalButton(onClick, modifier.heightIn(min = 44.dp), enabled = enabled, shape = MaterialTheme.shapes.medium) {
        if (icone != null) { Icon(icone, null, Modifier.size(18.dp)); Spacer(Modifier.width(6.dp)) }
        Text(texte)
    }

/** Champ de saisie arrondi. */
@Composable
fun Champ(
    valeur: String, onChange: (String) -> Unit, libelle: String, modifier: Modifier = Modifier, minLignes: Int = 1,
    erreur: Boolean = false, icone: ImageVector? = null, singleLine: Boolean = minLignes == 1, action: (@Composable () -> Unit)? = null,
) = OutlinedTextField(
    valeur, onChange, modifier.fillMaxWidth(), label = { Text(libelle) }, minLines = minLignes, singleLine = singleLine, isError = erreur,
    shape = MaterialTheme.shapes.medium, leadingIcon = icone?.let { { Icon(it, null) } }, trailingIcon = action,
    colors = OutlinedTextFieldDefaults.colors(
        focusedContainerColor = MaterialTheme.colorScheme.surfaceContainer, unfocusedContainerColor = MaterialTheme.colorScheme.surfaceContainer,
        unfocusedBorderColor = MaterialTheme.colorScheme.outline,
    ),
)

/** Liste déroulante ; [optionnel] ajoute « Aucun ». */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun <T> Choix(label: String, options: List<T>, valeur: T?, texte: (T) -> String, optionnel: Boolean = false, onChoix: (T?) -> Unit) {
    var ouvert by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(ouvert, { ouvert = it }) {
        OutlinedTextField(valeur?.let(texte) ?: "Aucun", {}, readOnly = true, label = { Text(label) }, shape = MaterialTheme.shapes.medium,
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(ouvert) },
            colors = OutlinedTextFieldDefaults.colors(
                focusedContainerColor = MaterialTheme.colorScheme.surfaceContainer, unfocusedContainerColor = MaterialTheme.colorScheme.surfaceContainer,
                unfocusedBorderColor = MaterialTheme.colorScheme.outline),
            modifier = Modifier.menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable).fillMaxWidth())
        ExposedDropdownMenu(ouvert, { ouvert = false }) {
            if (optionnel) DropdownMenuItem({ Text("Aucun") }, { onChoix(null); ouvert = false })
            options.forEach { o -> DropdownMenuItem({ Text(texte(o)) }, { onChoix(o); ouvert = false }) }
        }
    }
}

/** Frise chronologique de l'historique d'une panne. */
@Composable
fun Frise(evenements: List<Evenement>, libelle: (Evenement) -> String) {
    evenements.forEachIndexed { i, e ->
        val dernier = i == evenements.lastIndex
        Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Column(Modifier.width(12.dp).fillMaxHeight(), horizontalAlignment = Alignment.CenterHorizontally) {
                Box(Modifier.padding(top = 5.dp).size(10.dp).background(if (dernier) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outline, CircleShape))
                if (!dernier) Box(Modifier.width(2.dp).weight(1f).background(MaterialTheme.colorScheme.outlineVariant))
            }
            Column(Modifier.padding(bottom = if (dernier) 0.dp else 16.dp)) {
                Text(libelle(e), style = MaterialTheme.typography.bodyMedium)
                Text(dateLisible(e.createdAt), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
    }
}

@Composable
fun Bandeau(texte: String, couleur: Color = MaterialTheme.colorScheme.errorContainer, contenu: @Composable ColumnScope.() -> Unit = {}) =
    Surface(color = couleur, shape = MaterialTheme.shapes.medium, modifier = Modifier.fillMaxWidth()) {
        Column(Modifier.padding(14.dp)) { Text(texte, style = MaterialTheme.typography.titleSmall); contenu() }
    }
