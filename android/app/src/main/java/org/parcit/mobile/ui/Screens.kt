package org.parcit.mobile.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import kotlinx.coroutines.launch
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Assignment
import androidx.compose.material.icons.automirrored.rounded.Logout
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.*
import androidx.compose.material3.*
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay
import org.parcit.mobile.AppViewModel
import org.parcit.mobile.BuildConfig
import org.parcit.mobile.Ecran
import org.parcit.mobile.data.*
import org.parcit.mobile.principal

@Composable
fun Root(vm: AppViewModel) {
    val snack = remember { SnackbarHostState() }
    LaunchedEffect(vm.message) { vm.message?.let { snack.showSnackbar(it); vm.messageLu() } }
    val u = vm.utilisateur
    val barre = u != null && vm.ecran.principal
    FondVerre(Modifier.fillMaxSize()) {
    Scaffold(
        containerColor = Color.Transparent, contentWindowInsets = WindowInsets(0, 0, 0, 0),
        snackbarHost = { SnackbarHost(snack) },
        bottomBar = {
            if (u != null && vm.ecran.principal) NavigationBar(containerColor = MaterialTheme.colorScheme.surfaceContainer.copy(alpha = .72f), tonalElevation = 0.dp) {
                @Composable fun onglet(e: Ecran, icone: ImageVector, texte: String, badge: Int = 0) = NavigationBarItem(
                    vm.ecran == e, { vm.aller(e) },
                    { BadgedBox({ if (badge > 0) Badge { Text("$badge") } }) { Icon(icone, texte) } }, label = { Text(texte) },
                    colors = NavigationBarItemDefaults.colors(indicatorColor = MaterialTheme.colorScheme.primary, selectedIconColor = MaterialTheme.colorScheme.onPrimary, selectedTextColor = MaterialTheme.colorScheme.primary),
                )
                onglet(Ecran.Liste, Icons.Rounded.Build, "Mes pannes")
                if (u.voitInterventions) onglet(Ecran.Interventions, Icons.AutoMirrored.Rounded.Assignment, "Interventions")
                if (u.voitParc) onglet(Ecran.Parc, Icons.Rounded.Computer, "Parc")
                onglet(Ecran.Notifications, Icons.Rounded.Notifications, "Alertes", vm.nonLues)
            }
        },
    ) { pad ->
        Column(Modifier.padding(pad).then(if (barre) Modifier else Modifier.navigationBarsPadding()).fillMaxSize()) {
        Box(Modifier.weight(1f).fillMaxWidth()) {
            if (u == null) EcranConnexion(vm)
            else when (val e = vm.ecran) {
                Ecran.Liste -> EcranListe(vm)
                Ecran.Declarer -> { BackHandler { vm.retour() }; EcranDeclarer(vm) }
                Ecran.Notifications -> { BackHandler { vm.aller(Ecran.Liste) }; EcranNotifications(vm) }
                Ecran.Interventions -> { BackHandler { vm.aller(Ecran.Liste) }; EcranInterventions(vm) }
                Ecran.Parc -> { BackHandler { vm.aller(Ecran.Liste) }; EcranParc(vm) }
                Ecran.Scan -> { BackHandler { vm.retour() }; EcranScan(vm) }
                is Ecran.MaterielDetail -> { BackHandler { vm.retour() }; EcranFiche(vm) }
                is Ecran.MaterielForm -> { BackHandler { vm.retour() }; EcranFormMateriel(vm, e.id) }
                is Ecran.Detail -> { BackHandler { vm.retour() }; EcranDetail(vm) }
                is Ecran.PanneForm -> { BackHandler { vm.retour() }; EcranFormPanne(vm) }
                Ecran.Utilisateurs -> { BackHandler { vm.retour() }; EcranUtilisateurs(vm) }
                is Ecran.UtilisateurForm -> { BackHandler { vm.retour() }; EcranFormUtilisateur(vm, e.id) }
                Ecran.Services -> { BackHandler { vm.retour() }; EcranServices(vm) }
                Ecran.Sync -> { BackHandler { vm.retour() }; EcranSync(vm) }
                Ecran.Inventaires -> { BackHandler { vm.retour() }; EcranInventaires(vm) }
                is Ecran.InventaireDetail -> { BackHandler { vm.retour() }; EcranInventaire(vm) }
                Ecran.Semaine -> { BackHandler { vm.retour() }; EcranSemaine(vm) }
                Ecran.Renouvellement -> { BackHandler { vm.retour() }; EcranRenouvellement(vm) }
            }
            vm.alerte?.let { n ->
                AlertDialog(onDismissRequest = { vm.fermerAlerte() }, icon = { Icon(iconeNotification(n.type), null) },
                    title = { Text(n.titre) }, text = { Text(n.message) },
                    confirmButton = {
                        if (n.peutPrendre) Button({ vm.prendre(n.tache.id) }) { Text("Je prends") }
                        else Button({ vm.ouvrirNotification(n) }) { Text("Voir") }
                    },
                    dismissButton = {
                        Row {
                            if (n.peutPrendre) TextButton({ vm.ouvrirNotification(n) }) { Text("Voir") }
                            TextButton({ vm.fermerAlerte() }) { Text("Plus tard") }
                        }
                    })
            }
            if (vm.chargement && vm.ecran != Ecran.Interventions) LinearProgressIndicator(Modifier.fillMaxWidth().align(Alignment.TopCenter))
        }
        if (u != null) {
            if (vm.sync.enCours) LinearProgressIndicator(Modifier.fillMaxWidth().height(2.dp))
            if (!vm.reseau || vm.horsLigne != null) BandeauEtat(true, "Hors ligne · dernière synchro ${heureCourte(vm.sync.derniere ?: vm.horsLigne ?: System.currentTimeMillis())}", "Détails") { vm.aller(Ecran.Sync) }
            else if (vm.sync.enAttente > 0 && !vm.sync.enCours && vm.ecran != Ecran.Sync) BandeauEtat(false, "${vm.sync.enAttente} modification(s) en attente d'envoi", "Synchroniser") { vm.synchroniser() }
        }
        }
    }
    }
}

private fun heureCourte(ms: Long) = java.text.SimpleDateFormat("dd/MM 'à' HH:mm", java.util.Locale.FRANCE).format(java.util.Date(ms))

/** Bandeau au-dessus de la barre du bas : hors ligne (copie locale) ou modifications en attente d'envoi. */
@Composable
private fun BandeauEtat(horsLigne: Boolean, texte: String, action: String, onAction: () -> Unit) {
    Surface(
        color = if (horsLigne) MaterialTheme.colorScheme.tertiaryContainer else MaterialTheme.colorScheme.secondaryContainer,
        contentColor = if (horsLigne) MaterialTheme.colorScheme.onTertiaryContainer else MaterialTheme.colorScheme.onSecondaryContainer,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(Modifier.padding(start = 16.dp, end = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Icon(if (horsLigne) Icons.Rounded.CloudOff else Icons.Rounded.CloudSync, null, Modifier.size(18.dp))
            Text(texte, Modifier.weight(1f), style = MaterialTheme.typography.labelLarge)
            TextButton(onAction) { Text(action) }
        }
    }
}

// ------------------------------------------------------------------ Connexion

@Composable
fun EcranConnexion(vm: AppViewModel) {
    var serveur by remember { mutableStateOf(vm.serveur) }
    var serveurOuvert by remember { mutableStateOf(false) }
    var touches by remember { mutableIntStateOf(0) }
    var test by remember { mutableStateOf<Pair<Boolean, String>?>(null) }
    var enTest by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    var login by remember { mutableStateOf("") }
    var mdp by remember { mutableStateOf("") }
    var visible by remember { mutableStateOf(false) }
    val pret = !vm.chargement && login.isNotBlank() && mdp.isNotBlank() && serveur.isNotBlank()
    Box(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color(0xFF0F1B33), Color(0xFF1D4ED8))))) {
    Column(
        Modifier.fillMaxSize().statusBarsPadding().verticalScroll(rememberScrollState()).padding(horizontal = 24.dp).imePadding(),
        verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.height(32.dp))
        Surface(color = Color.White.copy(alpha = .14f), shape = MaterialTheme.shapes.extraLarge, modifier = Modifier.size(84.dp)
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) {
                touches++
                if (touches >= 7) { touches = 0; serveurOuvert = !serveurOuvert }
            }) {
            Icon(Icons.Rounded.Computer, null, Modifier.padding(22.dp), tint = Color.White)
        }
        Spacer(Modifier.height(20.dp))
        Text("Parc IT", style = MaterialTheme.typography.headlineMedium, color = Color.White)
        Text("Déclarez et suivez vos pannes", style = MaterialTheme.typography.bodyLarge, color = Color.White.copy(alpha = .75f))
        Spacer(Modifier.height(28.dp))
        Surface(color = MaterialTheme.colorScheme.surfaceContainer, shape = MaterialTheme.shapes.large, shadowElevation = 8.dp) {
        Column(Modifier.padding(20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Champ(login, { login = it }, "Identifiant", icone = Icons.Rounded.Person)
            OutlinedTextField(
                mdp, { mdp = it }, Modifier.fillMaxWidth(), label = { Text("Mot de passe") }, singleLine = true, shape = MaterialTheme.shapes.medium,
                leadingIcon = { Icon(Icons.Rounded.Lock, null) },
                trailingIcon = { IconButton({ visible = !visible }) { Icon(if (visible) Icons.Rounded.VisibilityOff else Icons.Rounded.Visibility, "Afficher le mot de passe") } },
                visualTransformation = if (visible) VisualTransformation.None else PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                colors = OutlinedTextFieldDefaults.colors(focusedContainerColor = MaterialTheme.colorScheme.surfaceContainer,
                    unfocusedContainerColor = MaterialTheme.colorScheme.surfaceContainer, unfocusedBorderColor = MaterialTheme.colorScheme.outline),
            )
            if (serveurOuvert) Champ(serveur, { serveur = it }, "Adresse du serveur", icone = Icons.Rounded.Language)
            BoutonPrincipal("Se connecter", { vm.connecter(serveur, login, mdp) }, enabled = pret)
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(if (serveurOuvert) "Mode édition de l'adresse" else "Serveur : ${serveur.removePrefix("https://").removePrefix("http://")}",
                    Modifier.weight(1f), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                TextButton({ enTest = true; test = null; scope.launch { test = vm.testerServeur(serveur); enTest = false } }, enabled = !enTest && serveur.isNotBlank()) {
                    if (enTest) CircularProgressIndicator(Modifier.size(16.dp), strokeWidth = 2.dp) else Text("Tester")
                }
            }
            test?.let { (ok, msg) -> Text(msg, style = MaterialTheme.typography.bodySmall, color = if (ok) Color(0xFF15803D) else MaterialTheme.colorScheme.error) }
        }
        }
        Spacer(Modifier.height(24.dp))
    }
    }
}

// ------------------------------------------------------------------ Mes pannes

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranListe(vm: AppViewModel) {
    var onglet by remember { mutableIntStateOf(0) }   // 0 ouvertes, 1 terminées, 2 toutes
    var menu by remember { mutableStateOf(false) }
    var rafraichit by remember { mutableStateOf(false) }
    LaunchedEffect(rafraichit) { if (rafraichit) { vm.actualiser(); delay(900); rafraichit = false } }
    val ouvert = { t: Tache -> t.statut != "Terminé" && t.statut != "Annulé" }
    val visibles = when (onglet) { 0 -> vm.taches.filter(ouvert); 1 -> vm.taches.filterNot(ouvert); else -> vm.taches }
    val prenom = vm.utilisateur?.nom?.substringBefore(' ') ?: ""

    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            EnTete("Bonjour $prenom", "Suivez l'état de vos déclarations", actions = {
                Box {
                    IconButton({ menu = true }) { Avatar(vm.utilisateur?.nom, 36) }
                    DropdownMenu(menu, { menu = false }) {
                        Text(vm.utilisateur?.nom ?: "", Modifier.padding(horizontal = 16.dp, vertical = 4.dp), style = MaterialTheme.typography.titleSmall)
                        Text(listOfNotNull(vm.utilisateur?.role, vm.utilisateur?.service?.nom).joinToString(" · "), Modifier.padding(horizontal = 16.dp), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                        HorizontalDivider(Modifier.padding(vertical = 8.dp))
                        if (vm.utilisateur?.voitUtilisateurs == true) DropdownMenuItem(text = { Text("Utilisateurs") }, leadingIcon = { Icon(Icons.Rounded.Group, null) },
                            onClick = { menu = false; vm.ouvrirUtilisateurs() })
                        if (vm.utilisateur?.gereServices == true) DropdownMenuItem(text = { Text("Services") }, leadingIcon = { Icon(Icons.Rounded.Business, null) },
                            onClick = { menu = false; vm.ouvrirServices() })
                        if (vm.utilisateur?.voitInventaires == true) DropdownMenuItem(text = { Text("Inventaires") }, leadingIcon = { Icon(Icons.Rounded.Checklist, null) },
                            onClick = { menu = false; vm.aller(Ecran.Inventaires) })
                        DropdownMenuItem(text = { Text("Ma semaine") }, leadingIcon = { Icon(Icons.Rounded.CalendarMonth, null) },
                            onClick = { menu = false; vm.aller(Ecran.Semaine) })
                        if (vm.utilisateur?.voitRenouvellements == true) DropdownMenuItem(text = { Text("Renouvellement") }, leadingIcon = { Icon(Icons.Rounded.Autorenew, null) },
                            onClick = { menu = false; vm.aller(Ecran.Renouvellement) })
                        DropdownMenuItem(text = { Text(if (vm.sync.enAttente > 0) "Synchronisation (${vm.sync.enAttente})" else "Synchronisation") },
                            leadingIcon = { Icon(Icons.Rounded.Sync, null) }, onClick = { menu = false; vm.aller(Ecran.Sync) })
                        DropdownMenuItem(text = { Text(if (vm.sombre) "Thème clair" else "Thème sombre") },
                            leadingIcon = { Icon(if (vm.sombre) Icons.Rounded.LightMode else Icons.Rounded.DarkMode, null) },
                            onClick = { vm.basculerTheme() })
                        DropdownMenuItem(text = { Text("Se déconnecter") }, leadingIcon = { Icon(Icons.AutoMirrored.Rounded.Logout, null) },
                            onClick = { menu = false; vm.deconnecter() })
                    }
                }
            })
            PullToRefreshBox(false, { rafraichit = true }, Modifier.weight(1f)) {
                LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 16.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    vm.miseAJour?.let { c -> item { BandeauMiseAJour(c) } }
                    item { Raccourcis(vm) }
                    item {
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            Stat("Ouvertes", vm.taches.count(ouvert), couleurStatutPublic("En cours"), Modifier.weight(1f))
                            Stat("En attente", vm.taches.count { it.statut == "En attente" }, couleurStatutPublic("En attente"), Modifier.weight(1f))
                            Stat("Terminées", vm.taches.count { it.statut == "Terminé" }, couleurStatutPublic("Terminé"), Modifier.weight(1f))
                        }
                    }
                    item {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            listOf("Ouvertes", "Terminées", "Toutes").forEachIndexed { i, l -> FilterChip(onglet == i, { onglet = i }, { Text(l) }) }
                        }
                    }
                    items(vm.brouillons, key = { it.uuid }) { b ->
                        Carte(couleur = MaterialTheme.colorScheme.tertiaryContainer) {
                            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                    Icon(if (b.erreur == null) Icons.Rounded.CloudOff else Icons.Rounded.ErrorOutline, null, Modifier.size(18.dp))
                                    Text(b.titre, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                }
                                Text(b.erreur?.let { "Refusée : $it" } ?: "En attente d'envoi : sera envoyée dès le retour du réseau.", style = MaterialTheme.typography.bodySmall)
                                Row {
                                    if (b.erreur == null) TextButton({ vm.reessayer(b.uuid) }) { Text("Réessayer") }
                                    TextButton({ vm.abandonner(b.uuid) }) { Text("Abandonner") }
                                }
                            }
                        }
                    }
                    if (visibles.isEmpty() && vm.brouillons.isEmpty() && !vm.chargement) item {
                        if (vm.taches.isEmpty()) EtatVide(Icons.Rounded.CheckCircle, "Aucune panne déclarée", "Quelque chose ne fonctionne pas ? Touchez « Déclarer une panne ».")
                        else EtatVide(Icons.Rounded.Inbox, "Rien dans cette liste")
                    }
                    items(visibles, key = { it.id }) { t -> CartePanne(t) { vm.ouvrirDetail(t.id) } }
                }
            }
        }
        ExtendedFloatingActionButton(
            text = { Text("Déclarer une panne") }, icon = { Icon(Icons.Rounded.Add, null) },
            onClick = { vm.ouvrirFormulaire() }, modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp),
            containerColor = MaterialTheme.colorScheme.primary, contentColor = MaterialTheme.colorScheme.onPrimary,
        )
    }
}

@Composable
private fun Stat(libelle: String, valeur: Int, couleur: Color, modifier: Modifier) = Card(
    modifier, shape = MaterialTheme.shapes.medium, colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceContainer.copy(alpha = .66f)),
    border = BorderStroke(1.dp, if (MaterialTheme.colorScheme.background.luminance() < .5f) Color.White.copy(alpha = .10f) else Color.White.copy(alpha = .85f)),
    elevation = CardDefaults.cardElevation(0.dp),
) {
    Column(Modifier.padding(14.dp)) {
        Text("$valeur", style = MaterialTheme.typography.headlineSmall, color = couleur)
        Text(libelle, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

/** Grille de raccourcis (tuiles arrondies) selon les permissions. */
@Composable
private fun Raccourcis(vm: AppViewModel) {
    val u = vm.utilisateur ?: return
    val tuiles = buildList {
        add(Triple("Déclarer", Icons.Rounded.Add, { vm.ouvrirFormulaire() }))
        if (u.voitInterventions) add(Triple("Interventions", Icons.AutoMirrored.Rounded.Assignment, { vm.aller(Ecran.Interventions) }))
        if (u.voitParc) add(Triple("Parc", Icons.Rounded.Computer, { vm.aller(Ecran.Parc) }))
        if (u.voitParc) add(Triple("Scanner", Icons.Rounded.QrCodeScanner, { vm.aller(Ecran.Scan) }))
        add(Triple("Alertes", Icons.Rounded.Notifications, { vm.aller(Ecran.Notifications) }))
    }
    val teintes = listOf(Color(0xFF2563EB), Color(0xFFF97316), Color(0xFF0F766E), Color(0xFF7C3AED), Color(0xFFDC2626))
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        tuiles.chunked(2).forEachIndexed { r, ligne ->
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                ligne.forEachIndexed { c, (titre, icone, action) ->
                    val teinte = teintes[(r * 2 + c) % teintes.size]
                    Card(action, Modifier.weight(1f), shape = MaterialTheme.shapes.medium,
                        colors = CardDefaults.cardColors(containerColor = MaterialTheme.colorScheme.surfaceContainer.copy(alpha = .66f)),
                        border = BorderStroke(1.dp, if (MaterialTheme.colorScheme.background.luminance() < .5f) Color.White.copy(alpha = .10f) else Color.White.copy(alpha = .85f)),
                        elevation = CardDefaults.cardElevation(0.dp)) {
                        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            Surface(color = teinte.copy(alpha = .14f), shape = CircleShape) {
                                Icon(icone, null, Modifier.padding(9.dp).size(22.dp), tint = teinte)
                            }
                            Text(titre, style = MaterialTheme.typography.titleSmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                    }
                }
                if (ligne.size == 1) Spacer(Modifier.weight(1f))
            }
        }
    }
}

/** Carte d'une panne, avec un liseré de couleur selon la priorité. */
@Composable
fun CartePanne(t: Tache, extra: @Composable ColumnScope.() -> Unit = {}, onClick: () -> Unit) = Carte(onClick = onClick) {
    Row(Modifier.height(IntrinsicSize.Min)) {
        Box(Modifier.width(5.dp).fillMaxHeight().background(couleurPriorite(t.priorite)))
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                PuceStatut(t.statut)
                if (t.sla.retardPriseEnCharge || t.sla.retardResolution) Puce("En retard", MaterialTheme.colorScheme.error, icone = Icons.Rounded.Schedule)
                Spacer(Modifier.weight(1f))
                Text(t.reference ?: "", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Text(t.titre, style = MaterialTheme.typography.titleMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
            val ligne = when {
                t.assigne != null && !t.enFile -> "Pris en charge par ${t.assigne.nom}"
                t.statut == "Terminé" -> "Résolue"
                t.statut == "Annulé" -> "Annulée"
                else -> "En attente de prise en charge"
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.Person, null, Modifier.size(14.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
                Text(ligne, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Spacer(Modifier.weight(1f))
                Text(dateRelative(t.updatedAt ?: t.dates.creation), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            extra()
        }
    }
}

@Composable
fun BandeauMiseAJour(c: Config) {
    val ctx = androidx.compose.ui.platform.LocalContext.current
    Carte(couleur = MaterialTheme.colorScheme.primaryContainer) {
        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Icon(Icons.Rounded.SystemUpdate, null, tint = MaterialTheme.colorScheme.onPrimaryContainer)
            Column(Modifier.weight(1f)) {
                Text("Version ${c.versionCourante} disponible", style = MaterialTheme.typography.titleSmall, color = MaterialTheme.colorScheme.onPrimaryContainer)
                c.notes?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onPrimaryContainer) }
            }
            if (!c.apkUrl.isNullOrBlank()) FilledTonalButton({
                runCatching { ctx.startActivity(android.content.Intent(android.content.Intent.ACTION_VIEW, android.net.Uri.parse(c.apkUrl))) }
            }) { Text("Télécharger") }
        }
    }
}

// ------------------------------------------------------------------ Déclaration

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranDeclarer(vm: AppViewModel) {
    var titre by remember { mutableStateOf("") }
    var desc by remember { mutableStateOf("") }
    var prio by remember { mutableStateOf("Moyenne") }
    var service by remember { mutableStateOf<Service?>(vm.utilisateur?.service) }
    var materiel by remember { mutableStateOf(vm.materielPrefill) }
    Column(Modifier.fillMaxSize()) {
        EnTete("Déclarer une panne", retour = { vm.retour() })
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Champ(titre, { titre = it }, "Que se passe-t-il ? *")
            Champ(desc, { desc = it }, "Détails (facultatif)", minLignes = 4)
            Text("Urgence", style = MaterialTheme.typography.titleSmall)
            SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth()) {
                val niveaux = vm.referentiels.priorites
                niveaux.forEachIndexed { i, p ->
                    SegmentedButton(prio == p, { prio = p }, SegmentedButtonDefaults.itemShape(i, niveaux.size), label = { Text(p) })
                }
            }
            Choix("Service concerné *", vm.referentiels.services, service, { it.nom }) { service = it }
            if (vm.utilisateur?.service == null)
                Text("Votre compte n'est lié à aucun service : choisissez-le ici. Demandez à un administrateur de renseigner votre service pour qu'il soit proposé automatiquement.",
                    style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            if (vm.materiels.isNotEmpty() || vm.materielPrefill != null)
                Choix("Matériel concerné", (vm.materiels + listOfNotNull(vm.materielPrefill)).distinctBy { it.id }, materiel, { it.libelle }, optionnel = true) { materiel = it }
        }
        Surface(color = MaterialTheme.colorScheme.surfaceContainer, tonalElevation = 2.dp) {
            Box(Modifier.padding(16.dp)) {
                BoutonPrincipal("Envoyer la déclaration", { vm.declarer(titre.trim(), desc.trim(), prio, service?.id, materiel?.id) },
                    enabled = titre.isNotBlank() && service != null && !vm.chargement, icone = Icons.AutoMirrored.Rounded.Send)
            }
        }
    }
}

// ------------------------------------------------------------------ Détail

@Composable
fun EcranDetail(vm: AppViewModel) {
    val t = vm.detail
    var commentaire by remember { mutableStateOf("") }
    var confirmer by remember { mutableStateOf(false) }
    var supprimer by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize()) {
        EnTete(t?.reference ?: "Panne", retour = { vm.retour() }, actions = {
            if (t?.actions?.modifier == true) IconButton({ vm.ouvrirFormPanne() }) { Icon(Icons.Rounded.Edit, "Modifier la panne") }
            if (t?.actions?.supprimer == true) IconButton({ supprimer = true }) { Icon(Icons.Rounded.Delete, "Supprimer la panne", tint = MaterialTheme.colorScheme.error) }
        })
        if (t == null) { Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator() }; return@Column }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Carte {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        PuceStatut(t.statut)
                        Puce("Priorité ${t.priorite}", couleurPriorite(t.priorite))
                        if (t.sla.retardPriseEnCharge || t.sla.retardResolution) Puce("En retard", MaterialTheme.colorScheme.error, icone = Icons.Rounded.Schedule)
                    }
                    Text(t.titre, style = MaterialTheme.typography.headlineSmall)
                    if (!t.description.isNullOrBlank()) Text(t.description, style = MaterialTheme.typography.bodyLarge)
                }
            }
            Carte {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        if (t.assigne != null && !t.enFile) {
                            Avatar(t.assigne.nom, 40)
                            Column { Text("Prise en charge par", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant); Text(t.assigne.nom ?: "", style = MaterialTheme.typography.titleSmall) }
                        } else {
                            val fini = t.statut == "Terminé" || t.statut == "Annulé"
                            Surface(color = MaterialTheme.colorScheme.secondaryContainer, shape = CircleShape, modifier = Modifier.size(40.dp)) {
                                Icon(if (fini) Icons.Rounded.CheckCircle else Icons.Rounded.HourglassTop, null, Modifier.padding(10.dp))
                            }
                            Text(if (fini) "Clôturée" else "En attente de prise en charge par le service IT", style = MaterialTheme.typography.titleSmall)
                        }
                    }
                    LigneInfo(Icons.Rounded.Person, "Déclarée par", t.declarant?.nom)
                    LigneInfo(Icons.Rounded.Computer, "Matériel", t.materiel?.libelle)
                    LigneInfo(Icons.Rounded.Business, "Service", t.service)
                    LigneInfo(Icons.Rounded.Schedule, "Déclarée", dateLisible(t.dates.creation))
                    if (t.statut == "En attente") LigneInfo(Icons.Rounded.HourglassTop, "Motif d'attente", t.motifAttente)
                }
            }
            ActionsAgent(vm, t)
            if (t.actions.prendre) BoutonPrincipal("Je prends cette panne", { vm.prendre(t.id) }, enabled = !vm.chargement, icone = Icons.Rounded.PanTool)
            if (t.evenements.isNotEmpty()) {
                Titre("Historique")
                Carte { Column(Modifier.padding(16.dp)) { Frise(t.evenements, ::libelleEvenement) } }
            }
            if (t.actions.commenter && !vm.estEquipe(t)) {
                Titre("Ajouter un message")
                Champ(commentaire, { commentaire = it }, "Votre message", minLignes = 2)
                BoutonSecondaire("Envoyer", { vm.commenter(commentaire.trim()); commentaire = "" }, enabled = commentaire.isNotBlank() && !vm.chargement, icone = Icons.AutoMirrored.Rounded.Send)
            }
            if (t.actions.annuler) TextButton({ confirmer = true }, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error)) {
                Icon(Icons.Rounded.Close, null, Modifier.size(18.dp)); Spacer(Modifier.width(6.dp)); Text("Annuler cette panne")
            }
        }
    }
    if (supprimer) AlertDialog(onDismissRequest = { supprimer = false },
        title = { Text("Supprimer la panne ?") }, text = { Text("La panne et son historique seront définitivement supprimés. Cette action est irréversible.") },
        confirmButton = { TextButton({ supprimer = false; vm.supprimerPanne() }, colors = ButtonDefaults.textButtonColors(contentColor = MaterialTheme.colorScheme.error)) { Text("Supprimer") } },
        dismissButton = { TextButton({ supprimer = false }) { Text("Garder") } })
    if (confirmer) AlertDialog(onDismissRequest = { confirmer = false },
        title = { Text("Annuler la panne ?") }, text = { Text("Le service IT sera informé que la panne est annulée.") },
        confirmButton = { TextButton({ confirmer = false; vm.annuler("Annulée par le déclarant") }) { Text("Annuler la panne") } },
        dismissButton = { TextButton({ confirmer = false }) { Text("Garder") } })
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

// ------------------------------------------------------------------ Alertes

fun iconeNotification(type: String): ImageVector = when (type) {
    "nouvelle" -> Icons.Rounded.ReportProblem; "prise_en_charge" -> Icons.Rounded.Build
    "affectee", "affectation" -> Icons.Rounded.PersonAdd; "statut" -> Icons.Rounded.SwapHoriz
    "commentaire" -> Icons.Rounded.ChatBubble; else -> Icons.Rounded.Notifications
}

@Composable
fun EcranNotifications(vm: AppViewModel) {
    Column(Modifier.fillMaxSize()) {
        EnTete("Alertes", if (vm.nonLues > 0) "${vm.nonLues} non lue(s)" else "Tout est à jour", actions = {
            if (vm.nonLues > 0) TextButton({ vm.toutMarquerLu() }) { Text("Tout lire") }
        })
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp, 4.dp, 16.dp, 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            if (vm.notifications.isEmpty()) item { EtatVide(Icons.Rounded.Notifications, "Aucune alerte", "Vous serez prévenu ici de chaque évolution de vos pannes.") }
            items(vm.notifications, key = { it.id }) { n ->
                Carte(onClick = { vm.ouvrirNotification(n) }, couleur = if (n.lue) MaterialTheme.colorScheme.surfaceContainer else MaterialTheme.colorScheme.primaryContainer.copy(alpha = .45f)) {
                    Row(Modifier.padding(14.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        Surface(color = MaterialTheme.colorScheme.primaryContainer, shape = CircleShape, modifier = Modifier.size(40.dp)) {
                            Icon(iconeNotification(n.type), null, Modifier.padding(10.dp), tint = MaterialTheme.colorScheme.onPrimaryContainer)
                        }
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(n.titre, Modifier.weight(1f), style = MaterialTheme.typography.titleSmall)
                                if (!n.lue) Box(Modifier.size(8.dp).background(MaterialTheme.colorScheme.primary, CircleShape))
                            }
                            Text(n.message, style = MaterialTheme.typography.bodyMedium, maxLines = 3, overflow = TextOverflow.Ellipsis)
                            Text(dateRelative(n.createdAt), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                            if (n.peutPrendre) BoutonSecondaire("Je prends", { vm.prendre(n.tache.id) }, enabled = !vm.chargement, icone = Icons.Rounded.PanTool)
                        }
                    }
                }
            }
        }
    }
}
