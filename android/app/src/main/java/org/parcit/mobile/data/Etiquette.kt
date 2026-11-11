package org.parcit.mobile.data

/** Informations lues sur une étiquette (ou un écran « À propos ») : tout est modifiable avant usage. */
data class InfosEtiquette(
    val marque: String = "", val modele: String = "", val numSerie: String = "",
    val autres: List<String> = emptyList(),
) { val vide get() = marque.isBlank() && modele.isBlank() && numSerie.isBlank() }

private val MARQUES = listOf(
    "Dell", "HP", "Hewlett-Packard", "Hewlett Packard", "Lenovo", "Asus", "Acer", "Apple", "Samsung", "Toshiba", "Cisco", "Huawei", "MSI", "Microsoft",
    "Epson", "Canon", "Brother", "Ricoh", "Xerox", "Kyocera", "Lexmark", "Logitech", "TP-Link", "Ubiquiti", "Fujitsu", "Sony", "LG", "Philips",
    "Eaton", "APC", "Zebra", "Hikvision", "Dahua", "D-Link", "Netgear", "MikroTik", "Synology", "Western Digital", "Seagate", "Kingston", "SanDisk",
    "Intel", "Gigabyte", "Zotac", "Fortinet", "Juniper", "Aruba", "Ruijie", "Tenda", "Xiaomi", "Honor", "Oppo", "Infinix", "Tecno", "Itel", "Nokia",
)

// Libellés qui précèdent le numéro de série, le modèle ou la marque (étiquettes constructeur et écrans « À propos »).
private val LIBELLE_SERIE = Regex("""(?i)(?:serial\s*(?:no\.?|number|num\.?|#)?|s\s*/\s*n|s/n|\bsn\b|service\s*tag|n\s*[°o]\s*(?:de\s*)?s[ée]rie|num[ée]ro\s*de\s*s[ée]rie|product\s*id)\s*[:#.\-]?\s*((?=[A-Z0-9\-/]*\d)[A-Z0-9][A-Z0-9\-/]{4,})""")
private val LIBELLE_MODELE = Regex("""(?i)(?:model\s*(?:name|no\.?|number)?|system\s*model|product\s*name|mod[èe]le|mod\.|type|p\s*/\s*n|part\s*(?:no\.?|number))\s*[:#.\-]?\s*(.+)""")
private val LIBELLE_MARQUE = Regex("""(?i)(?:manufacturer|fabricant|marque|brand|vendor|made\s*by)\s*[:#.\-]?\s*(.+)""")
private val MAC = Regex("""(?i)\b(?:[0-9a-f]{2}[:\-]){5}[0-9a-f]{2}\b""")
private val JETON = Regex("""[A-Z0-9][A-Z0-9\-]{7,24}""")

/** Corrige les confusions fréquentes de l'OCR dans un numéro de série (espaces, O/0 restent à la main : on ne devine pas). */
private fun nettoyerSerie(s: String) = s.trim().trim('.', ':', ',', ';').replace(" ", "").uppercase()

/**
 * Extrait marque, modèle et numéro de série d'un texte reconnu. Ordre de confiance :
 * libellés explicites (« S/N : … », « Model : … »), puis marque connue, puis meilleur jeton alphanumérique ressemblant à un numéro de série.
 */
fun analyserEtiquette(texte: String): InfosEtiquette {
    val lignes = texte.lines().map { it.trim() }.filter { it.isNotEmpty() }
    if (lignes.isEmpty()) return InfosEtiquette()

    var serie = ""
    for (l in lignes) { LIBELLE_SERIE.find(l)?.let { serie = nettoyerSerie(it.groupValues[1]); return@let }; if (serie.isNotEmpty()) break }
    // Libellé seul sur une ligne et valeur à la suivante (« Serial Number » puis « ABC123456 »).
    if (serie.isEmpty()) for (i in 0 until lignes.size - 1) {
        if (Regex("""(?i)^(serial\s*(no\.?|number)?|s\s*/\s*n|service\s*tag|n°\s*de\s*s[ée]rie)\s*[:#]?$""").matches(lignes[i])) {
            val v = nettoyerSerie(lignes[i + 1]); if (v.length >= 5) { serie = v; break }
        }
    }

    var modele = ""
    for (l in lignes) { LIBELLE_MODELE.find(l)?.let { val v = it.groupValues[1].trim().trim(':', '.'); if (v.length in 2..60 && !MAC.containsMatchIn(v)) { modele = v } }; if (modele.isNotEmpty()) break }

    var marque = ""
    for (l in lignes) { LIBELLE_MARQUE.find(l)?.let { marque = it.groupValues[1].trim().take(40) }; if (marque.isNotEmpty()) break }
    val brut = texte
    val connue = MARQUES.firstOrNull { Regex("""(?i)(?<![A-Za-z0-9])${Regex.escape(it)}(?![A-Za-z0-9])""").containsMatchIn(brut) }
    if (connue != null) marque = MARQUES.first { it.equals(connue, ignoreCase = true) }.let { if (it.equals("Hewlett Packard", true) || it.equals("Hewlett-Packard", true)) "HP" else it }
    else if (marque.isNotEmpty()) marque = marque.split(Regex("""\s+""")).firstOrNull()?.takeIf { it.length > 1 } ?: marque

    // Repli : le jeton le plus « numéro de série » (lettres et chiffres, assez long, pas une MAC ni une date).
    if (serie.isEmpty()) {
        serie = lignes.flatMap { l -> JETON.findAll(l.uppercase().replace(MAC, " ")).map { it.value }.toList() }
            .filter { t -> t.any(Char::isDigit) && t.any(Char::isLetter) && !Regex("""\d{2}[-/]\d{2}[-/]\d{2,4}""").matches(t) }
            .maxByOrNull { it.length } ?: ""
    }

    val autres = mutableListOf<String>()
    MAC.findAll(texte).forEach { autres += "MAC " + it.value.uppercase() }
    return InfosEtiquette(marque, modele, serie, autres.distinct())
}
