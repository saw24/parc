package org.parcit.mobile.ui

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

private val Claire = lightColorScheme(
    primary = Color(0xFF1D4ED8), onPrimary = Color.White,
    primaryContainer = Color(0xFFDCE6FF), onPrimaryContainer = Color(0xFF0B2A7A),
    secondary = Color(0xFF475569), onSecondary = Color.White,
    secondaryContainer = Color(0xFFE2E8F0), onSecondaryContainer = Color(0xFF1E293B),
    tertiary = Color(0xFF0F766E), tertiaryContainer = Color(0xFFCCFBF1), onTertiaryContainer = Color(0xFF042F2E),
    background = Color(0xFFF6F8FC), onBackground = Color(0xFF0F172A),
    surface = Color(0xFFF6F8FC), onSurface = Color(0xFF0F172A),
    surfaceVariant = Color(0xFFEAEFF7), onSurfaceVariant = Color(0xFF5B6678),
    surfaceContainerLowest = Color.White, surfaceContainerLow = Color.White,
    surfaceContainer = Color(0xFFFFFFFF), surfaceContainerHigh = Color(0xFFEEF2F8), surfaceContainerHighest = Color(0xFFE6ECF5),
    outline = Color(0xFFB8C2D3), outlineVariant = Color(0xFFE1E7F0),
    error = Color(0xFFDC2626), onError = Color.White, errorContainer = Color(0xFFFEE2E2), onErrorContainer = Color(0xFF7F1D1D),
)

private val Sombre = darkColorScheme(
    primary = Color(0xFF8FB0FF), onPrimary = Color(0xFF0B1F5C),
    primaryContainer = Color(0xFF1E3A8A), onPrimaryContainer = Color(0xFFDCE6FF),
    secondary = Color(0xFFB4BFD1), onSecondary = Color(0xFF1E293B),
    secondaryContainer = Color(0xFF2A3548), onSecondaryContainer = Color(0xFFE2E8F0),
    tertiary = Color(0xFF5EEAD4), tertiaryContainer = Color(0xFF134E4A), onTertiaryContainer = Color(0xFFCCFBF1),
    background = Color(0xFF0B1220), onBackground = Color(0xFFE6EBF5),
    surface = Color(0xFF0B1220), onSurface = Color(0xFFE6EBF5),
    surfaceVariant = Color(0xFF1B2538), onSurfaceVariant = Color(0xFF9AA6BC),
    surfaceContainerLowest = Color(0xFF0B1220), surfaceContainerLow = Color(0xFF121A2B),
    surfaceContainer = Color(0xFF121A2B), surfaceContainerHigh = Color(0xFF1B2538), surfaceContainerHighest = Color(0xFF243049),
    outline = Color(0xFF3A465C), outlineVariant = Color(0xFF263147),
    error = Color(0xFFFF8A8A), onError = Color(0xFF450A0A), errorContainer = Color(0xFF5B1A1A), onErrorContainer = Color(0xFFFEE2E2),
)

private val Formes = Shapes(
    extraSmall = RoundedCornerShape(8.dp), small = RoundedCornerShape(12.dp), medium = RoundedCornerShape(16.dp),
    large = RoundedCornerShape(20.dp), extraLarge = RoundedCornerShape(28.dp),
)

private val Base = Typography()
private val Typo = Typography(
    headlineMedium = Base.headlineMedium.copy(fontSize = 26.sp, lineHeight = 32.sp, fontWeight = FontWeight.Bold),
    headlineSmall = Base.headlineSmall.copy(fontSize = 22.sp, lineHeight = 28.sp, fontWeight = FontWeight.Bold),
    titleLarge = Base.titleLarge.copy(fontSize = 20.sp, fontWeight = FontWeight.SemiBold),
    titleMedium = Base.titleMedium.copy(fontSize = 16.sp, lineHeight = 22.sp, fontWeight = FontWeight.SemiBold),
    titleSmall = Base.titleSmall.copy(fontSize = 14.sp, fontWeight = FontWeight.SemiBold),
    bodyLarge = Base.bodyLarge.copy(fontSize = 16.sp, lineHeight = 24.sp),
    bodyMedium = Base.bodyMedium.copy(fontSize = 14.sp, lineHeight = 20.sp),
    bodySmall = Base.bodySmall.copy(fontSize = 12.sp, lineHeight = 16.sp),
    labelLarge = Base.labelLarge.copy(fontSize = 14.sp, fontWeight = FontWeight.SemiBold),
    labelMedium = Base.labelMedium.copy(fontSize = 12.sp, fontWeight = FontWeight.Medium),
    labelSmall = TextStyle(fontSize = 11.sp, fontWeight = FontWeight.Medium),
)

@Composable
fun ParcTheme(sombre: Boolean, content: @Composable () -> Unit) = MaterialTheme(
    colorScheme = if (sombre) Sombre else Claire, shapes = Formes, typography = Typo, content = content,
)
