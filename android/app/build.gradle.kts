import java.util.Properties

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
    id("org.jetbrains.kotlin.plugin.serialization")
}

// Signature de la version de production : keystore.properties (hors dépôt) contient
// storeFile, storePassword, keyAlias, keyPassword. Sans ce fichier, la version release n'est pas signée.
val signature = Properties().apply { rootProject.file("keystore.properties").takeIf { it.exists() }?.inputStream()?.use { load(it) } }

android {
    namespace = "org.parcit.mobile"
    compileSdk = 36
    defaultConfig {
        applicationId = "org.parcit.mobile"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"
        // Adresse du serveur proposée par défaut (modifiable sur l'écran de connexion).
        buildConfigField("String", "DEFAULT_SERVER", "\"https://parc.example.org/parc\"")
    }
    buildFeatures { compose = true; buildConfig = true }
    signingConfigs {
        if (signature.isNotEmpty()) create("production") {
            storeFile = rootProject.file(signature.getProperty("storeFile"))
            storePassword = signature.getProperty("storePassword")
            keyAlias = signature.getProperty("keyAlias")
            keyPassword = signature.getProperty("keyPassword")
        }
    }
    buildTypes {
        debug { manifestPlaceholders["usesCleartext"] = "true"; buildConfigField("String", "DEFAULT_SERVER", "\"http://10.0.2.2/parc\"") }
        release { manifestPlaceholders["usesCleartext"] = "false"; if (signature.isNotEmpty()) signingConfig = signingConfigs.getByName("production"); isMinifyEnabled = true; proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro") }
    }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
}

dependencies {
    implementation(platform("androidx.compose:compose-bom:2026.05.00"))
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.activity:activity-compose:1.13.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.10.0")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.10.0")
    implementation("androidx.core:core-ktx:1.18.0")
    implementation("androidx.work:work-runtime:2.10.0")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.11.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")
    implementation("androidx.camera:camera-camera2:1.4.2")
    implementation("androidx.camera:camera-lifecycle:1.4.2")
    implementation("androidx.camera:camera-view:1.4.2")
    implementation("com.google.mlkit:barcode-scanning:17.3.0")   // modèle embarqué : fonctionne sans services Google Play
    debugImplementation("androidx.compose.ui:ui-tooling")
}
