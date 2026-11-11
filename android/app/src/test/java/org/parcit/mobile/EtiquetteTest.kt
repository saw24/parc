package org.parcit.mobile

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.parcit.mobile.data.analyserEtiquette
import org.junit.Test

class EtiquetteTest {
    @Test fun etiquetteDell() {
        val i = analyserEtiquette("DELL\nLatitude 5420\nService Tag: 7XK2H93\nExpress Service Code: 158 2394 6819\nMade in China")
        assertEquals("Dell", i.marque); assertEquals("7XK2H93", i.numSerie)
    }
    @Test fun etiquetteHpAvecLibelles() {
        val i = analyserEtiquette("HP ProBook 450 G8\nProduct Name: HP ProBook 450 G8\nS/N: 5CD1234XYZ\nMAC: 3C:52:82:AB:CD:EF")
        assertEquals("HP", i.marque); assertEquals("5CD1234XYZ", i.numSerie); assertEquals("HP ProBook 450 G8", i.modele)
        assertTrue(i.autres.any { it.startsWith("MAC 3C:52") })
    }
    @Test fun libelleFrancais() {
        val i = analyserEtiquette("Fabricant : Lenovo\nModèle : ThinkPad T14\nN° de série : PF3ABC12")
        assertEquals("Lenovo", i.marque); assertEquals("ThinkPad T14", i.modele); assertEquals("PF3ABC12", i.numSerie)
    }
    @Test fun valeurSurLigneSuivante() {
        val i = analyserEtiquette("Serial Number\nCND9876ABC\nModel\nLaserJet Pro")
        assertEquals("CND9876ABC", i.numSerie)
    }
    @Test fun ecranAPropos() {
        val i = analyserEtiquette("Device name  DESKTOP-4F2K\nManufacturer: ASUSTeK\nSystem Model: VivoBook X515\nSerial number: R9NXCV12345")
        assertEquals("R9NXCV12345", i.numSerie); assertEquals("VivoBook X515", i.modele)
    }
    @Test fun repliSansLibelle() {
        val i = analyserEtiquette("Canon\nPIXMA G3420\nKXYZ12345\n12/05/2022")
        assertEquals("Canon", i.marque); assertEquals("KXYZ12345", i.numSerie)
    }
    @Test fun texteVide() { assertTrue(analyserEtiquette("   ").vide) }
}
