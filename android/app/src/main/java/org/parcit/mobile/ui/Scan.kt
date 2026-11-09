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
import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.google.mlkit.vision.barcode.BarcodeScannerOptions
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import org.parcit.mobile.AppViewModel
import java.util.concurrent.atomic.AtomicBoolean

/** Lecture des étiquettes QR du parc (contenu « … | SN:xxx | ID:12 »), avec saisie manuelle du numéro de série en secours. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EcranScan(vm: AppViewModel) {
    val ctx = LocalContext.current
    var autorise by remember { mutableStateOf(ContextCompat.checkSelfPermission(ctx, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) }
    val demande = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { autorise = it }
    LaunchedEffect(Unit) { if (!autorise) demande.launch(Manifest.permission.CAMERA) }
    var manuel by remember { mutableStateOf("") }
    Scaffold(topBar = { TopAppBar(title = { Text("Scanner une étiquette") }, navigationIcon = { TextButton({ vm.retour() }) { Text("Retour") } }) }) { pad ->
        Column(Modifier.padding(pad).fillMaxSize(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            if (autorise) CameraScan(Modifier.weight(1f).fillMaxWidth()) { vm.codeScanne(it) }
            else Box(Modifier.weight(1f).fillMaxWidth().padding(16.dp)) { Text("L'accès à la caméra est nécessaire pour scanner. Vous pouvez saisir le numéro de série ci-dessous.") }
            Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(manuel, { manuel = it }, singleLine = true, label = { Text("Numéro de série") }, modifier = Modifier.weight(1f))
                Button({ vm.codeScanne(manuel.trim()) }, enabled = manuel.isNotBlank() && !vm.chargement) { Text("Chercher") }
            }
        }
    }
}

@Composable
private fun CameraScan(modifier: Modifier, onCode: (String) -> Unit) {
    val owner = LocalLifecycleOwner.current
    val deja = remember { AtomicBoolean(false) }
    val scanner = remember {
        BarcodeScanning.getClient(BarcodeScannerOptions.Builder()
            .setBarcodeFormats(Barcode.FORMAT_QR_CODE, Barcode.FORMAT_CODE_128, Barcode.FORMAT_CODE_39, Barcode.FORMAT_EAN_13).build())
    }
    DisposableEffect(Unit) { onDispose { scanner.close() } }
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
                scanner.process(InputImage.fromMediaImage(media, image.imageInfo.rotationDegrees))
                    .addOnSuccessListener { codes ->
                        codes.firstNotNullOfOrNull { it.rawValue }?.let { if (deja.compareAndSet(false, true)) onCode(it) }
                    }
                    .addOnCompleteListener { image.close() }
            }
            provider.unbindAll()
            provider.bindToLifecycle(owner, CameraSelector.DEFAULT_BACK_CAMERA, apercu, analyse)
        }, ContextCompat.getMainExecutor(c))
        vue
    })
}
