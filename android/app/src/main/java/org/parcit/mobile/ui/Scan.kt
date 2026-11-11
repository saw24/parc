package org.parcit.mobile.ui

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CornerSize
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.DocumentScanner
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.QrCodeScanner
import androidx.compose.material3.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.text.TextRecognition
import com.google.mlkit.vision.text.latin.TextRecognizerOptions
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import org.parcit.mobile.AppViewModel
import org.parcit.mobile.data.InfosEtiquette
import org.parcit.mobile.data.analyserEtiquette
import java.util.concurrent.atomic.AtomicBoolean

/** Zone caméra avec cadre de visée et demande d'autorisation ; appelle [onCode] une seule fois par lecture. */
@Composable
fun ZoneScan(modifier: Modifier = Modifier, texte: MutableState<String>? = null, onCode: (String) -> Unit) {
    val ctx = LocalContext.current
    var autorise by remember { mutableStateOf(ContextCompat.checkSelfPermission(ctx, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) }
    val demande = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { autorise = it }
    LaunchedEffect(Unit) { if (!autorise) demande.launch(Manifest.permission.CAMERA) }
    Box(modifier.clip(MaterialTheme.shapes.large).background(Color.Black)) {
        if (autorise) {
            CameraScan(Modifier.fillMaxSize(), texte, onCode)
            Canvas(Modifier.fillMaxSize()) {
                val c = minOf(size.width, size.height) * 0.62f
                drawRoundRect(Color.White, Offset((size.width - c) / 2, (size.height - c) / 2), Size(c, c), CornerRadius(28.dp.toPx()), style = Stroke(width = 3.dp.toPx()))
            }
            Text("Placez l'étiquette dans le cadre", Modifier.align(Alignment.BottomCenter).padding(16.dp), color = Color.White, style = MaterialTheme.typography.bodyMedium)
        } else EtatVide(Icons.Rounded.QrCodeScanner, "Caméra non autorisée", "Autorisez la caméra, ou saisissez le numéro de série à la main.")
    }
}

/** Extrait le numéro de série d'une étiquette « … | SN:xxx | ID:12 » ; sinon renvoie le code tel quel (code-barres simple). */
fun numeroDeSerie(code: String): String =
    Regex("SN:\\s*([^|]+)").find(code)?.groupValues?.get(1)?.trim()?.takeIf { it.isNotEmpty() } ?: code.trim()

/** Scanner plein écran en fenêtre : l'écran appelant (formulaire) garde sa saisie en cours. */
@Composable
fun DialogueScan(titre: String, onCode: (String) -> Unit, onFermer: () -> Unit) {
    Dialog(onFermer, DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
        Surface(Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            Column(Modifier.fillMaxSize().systemBarsPadding()) {
                EnTete(titre, retour = onFermer)
                ZoneScan(Modifier.weight(1f).fillMaxWidth().padding(16.dp), onCode = onCode)
            }
        }
    }
}

/** Lecture des étiquettes QR du parc (contenu « … | SN:xxx | ID:12 »), avec saisie manuelle du numéro de série en secours. */
@Composable
fun EcranScan(vm: AppViewModel) {
    var manuel by remember { mutableStateOf("") }
    val texte = remember { mutableStateOf("") }
    var lecture by remember { mutableStateOf<InfosEtiquette?>(null) }
    Column(Modifier.fillMaxSize()) {
        EnTete("Scanner une étiquette", retour = { vm.retour() })
        ZoneScan(Modifier.weight(1f).fillMaxWidth().padding(horizontal = 16.dp), texte) { vm.codeScanne(it) }
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            // Lecture intelligente : étiquette constructeur, écran « À propos », zone de texte affichant les caractéristiques.
            BoutonPrincipal(if (texte.value.isBlank()) "Lire le texte (cadrez l'étiquette ou l'écran)" else "Lire le texte détecté", {
                lecture = analyserEtiquette(texte.value).also { vm.dernierTexteLu = texte.value }
            }, enabled = texte.value.isNotBlank(), icone = Icons.Rounded.DocumentScanner)
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                Champ(manuel, { manuel = it }, "Numéro de série", Modifier.weight(1f), icone = Icons.Rounded.QrCode2)
                BoutonSecondaire("Chercher", { vm.codeScanne(manuel.trim()) }, enabled = manuel.isNotBlank() && !vm.chargement)
            }
        }
    }
    lecture?.let { i -> DialogueEtiquette(vm, i, texte.value) { lecture = null } }
}

/** Résultat de la lecture du texte : champs corrigeables avant de chercher dans le parc ou de créer le matériel. */
@Composable
fun DialogueEtiquette(vm: AppViewModel, infos: InfosEtiquette, brut: String, onFermer: () -> Unit) {
    var marque by remember { mutableStateOf(infos.marque) }
    var modele by remember { mutableStateOf(infos.modele) }
    var serie by remember { mutableStateOf(infos.numSerie) }
    var voirTexte by remember { mutableStateOf(false) }
    val lu = InfosEtiquette(marque.trim(), modele.trim(), serie.trim(), infos.autres)
    AlertDialog(onDismissRequest = onFermer, title = { Text("Texte lu sur l'étiquette") },
        text = { Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            if (infos.vide) Text("Rien d'exploitable n'a été reconnu. Rapprochez-vous, évitez les reflets, ou saisissez les informations.", style = MaterialTheme.typography.bodySmall)
            else Text("Vérifiez et corrigez si besoin (l'OCR peut confondre O/0 ou I/1).", style = MaterialTheme.typography.bodySmall)
            Champ(serie, { serie = it }, "Numéro de série")
            Champ(marque, { marque = it }, "Marque")
            Champ(modele, { modele = it }, "Modèle")
            infos.autres.forEach { Text(it, style = MaterialTheme.typography.bodySmall) }
            TextButton({ voirTexte = !voirTexte }) { Text(if (voirTexte) "Masquer le texte brut" else "Voir le texte brut") }
            if (voirTexte) Text(brut, style = MaterialTheme.typography.bodySmall)
        } },
        confirmButton = { TextButton({ vm.chercherParEtiquette(lu); onFermer() }, enabled = !lu.vide) { Text("Chercher dans le parc") } },
        dismissButton = { Row {
            if (vm.utilisateur?.peut("materiel.creer") == true) TextButton({ vm.creerDepuisEtiquette(lu); onFermer() }, enabled = !lu.vide) { Text("Créer") }
            TextButton(onFermer) { Text("Fermer") }
        } })
}

@Composable
private fun CameraScan(modifier: Modifier, texte: MutableState<String>?, onCode: (String) -> Unit) {
    val owner = LocalLifecycleOwner.current
    val deja = remember { AtomicBoolean(false) }
    val scanner = remember {
        BarcodeScanning.getClient(BarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_QR_CODE, Barcode.FORMAT_CODE_128, Barcode.FORMAT_CODE_39, Barcode.FORMAT_EAN_13).build())
    }
    // Lecture du texte (étiquette, écran « À propos ») : seulement si aucun code-barres n'est lu, au plus ~1,5 fois par seconde.
    val reco = remember { if (texte != null) TextRecognition.getClient(TextRecognizerOptions.DEFAULT_OPTIONS) else null }
    val dernierOcr = remember { longArrayOf(0L) }
    DisposableEffect(Unit) { onDispose { scanner.close(); reco?.close() } }
    AndroidView(modifier = modifier, factory = { c ->
        val vue = PreviewView(c)
        val futur = ProcessCameraProvider.getInstance(c)
        futur.addListener({
            val provider = futur.get()
            val apercu = Preview.Builder().build().also { it.surfaceProvider = vue.surfaceProvider }
            val analyse = ImageAnalysis.Builder().setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST).build()
            analyse.setAnalyzer(ContextCompat.getMainExecutor(c)) { image ->
                val media = image.image
                if (media == null || deja.get()) { image.close(); return@setAnalyzer }
                val entree = InputImage.fromMediaImage(media, image.imageInfo.rotationDegrees)
                scanner.process(entree)
                    .addOnSuccessListener { codes ->
                        val code = codes.firstNotNullOfOrNull { it.rawValue }
                        val maintenant = System.currentTimeMillis()
                        if (code != null) { if (deja.compareAndSet(false, true)) onCode(code); image.close() }
                        else if (reco != null && texte != null && maintenant - dernierOcr[0] > 650) {
                            dernierOcr[0] = maintenant
                            reco.process(entree).addOnSuccessListener { t -> if (t.text.isNotBlank()) texte.value = t.text }.addOnCompleteListener { image.close() }
                        } else image.close()
                    }
                    .addOnFailureListener { image.close() }
            }
            provider.unbindAll()
            provider.bindToLifecycle(owner, CameraSelector.DEFAULT_BACK_CAMERA, apercu, analyse)
        }, ContextCompat.getMainExecutor(c))
        vue
    })
}
