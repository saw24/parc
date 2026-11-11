package org.parcit.mobile.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable data class Ref(val id: Int? = null, val nom: String? = null, val libelle: String? = null)
@Serializable data class Service(val id: Int, val nom: String)

@Serializable data class Utilisateur(
    val id: Int, val nom: String, val login: String, val email: String? = null, val telephone: String? = null,
    val role: String = "", val service: Service? = null, val permissions: List<String> = emptyList(),
    @SerialName("agent_it") val agentIt: Boolean = false, val notifications: Preferences = Preferences(),
) {
    fun peut(code: String) = code in permissions
    val voitInterventions get() = peut("tache.voir_toutes")
    val voitParc get() = peut("materiel.voir_tous") || peut("materiel.voir_siens")
    val gereParc get() = peut("materiel.creer") || peut("materiel.modifier")
    val voitUtilisateurs get() = peut("utilisateur.lister") || peut("utilisateur.gerer")
    val gereUtilisateurs get() = peut("utilisateur.gerer")
    val voitInventaires get() = peut("inventaire.voir")
    val pointeInventaires get() = peut("inventaire.pointer")
    val gereInventaires get() = peut("inventaire.gerer")
    val voitRenouvellements get() = peut("renouvellement.voir")
    val proposeRenouvellements get() = peut("renouvellement.proposer")
    val decideRenouvellements get() = peut("renouvellement.decider")
    val gereServices get() = peut("referentiel.gerer") || peut("referentiel.supprimer")
}

@Serializable data class Dates(
    val creation: String? = null, @SerialName("prise_en_charge") val priseEnCharge: String? = null,
    val resolution: String? = null,
)

@Serializable data class Actions(
    val prendre: Boolean = false, val affecter: Boolean = false,
    @SerialName("changer_statut") val changerStatut: Boolean = false, val rouvrir: Boolean = false,
    val annuler: Boolean = false, val commenter: Boolean = false, val modifier: Boolean = false, val supprimer: Boolean = false,
)

@Serializable data class Evenement(
    val id: Int, val type: String, @SerialName("created_at") val createdAt: String? = null,
    val acteur: Ref? = null, @SerialName("ancien_statut") val ancienStatut: String? = null,
    @SerialName("nouveau_statut") val nouveauStatut: String? = null,
    @SerialName("nouveau_assigne") val nouveauAssigne: Ref? = null, val commentaire: String? = null,
)

@Serializable data class Sla(
    @SerialName("retard_prise_en_charge") val retardPriseEnCharge: Boolean = false,
    @SerialName("retard_resolution") val retardResolution: Boolean = false,
)

@Serializable data class Tache(
    val id: Int, val reference: String? = null, val titre: String, val description: String? = null,
    val priorite: String = "Moyenne", val statut: String = "À faire", val service: String? = null,
    val materiel: Ref? = null, val assigne: Ref? = null, @SerialName("en_file") val enFile: Boolean = false,
    val declarant: Ref? = null, @SerialName("motif_attente") val motifAttente: String? = null,
    val dates: Dates = Dates(), val version: Int = 0, @SerialName("updated_at") val updatedAt: String? = null,
    @SerialName("date_relance") val dateRelance: String? = null, val sla: Sla = Sla(),
    @SerialName("motif_cause") val motifCause: String? = null, @SerialName("date_limite") val dateLimite: String? = null,
    @SerialName("actions_texte") val actionsTexte: String? = null,
    val actions: Actions = Actions(), val evenements: List<Evenement> = emptyList(),
)

@Serializable data class Materiel(val id: Int, val libelle: String)

@Serializable data class Referentiels(
    val services: List<Service> = emptyList(), val priorites: List<String> = listOf("Basse", "Moyenne", "Haute"),
    @SerialName("types_materiel") val typesMateriel: List<Service> = emptyList(),
    @SerialName("statuts_materiel") val statutsMateriel: List<String> = listOf("En attente", "En service", "En panne", "Retiré"),
)

@Serializable data class Agent(val id: Int, val nom: String, @SerialName("file_commune") val fileCommune: Boolean = false, val charge: Int = 0)

@Serializable data class UtilisateurRef(val id: Int, val nom: String, val service: Service? = null)

@Serializable data class Fiche(
    val id: Int, val libelle: String, val type: String? = null, val marque: String? = null, val modele: String? = null,
    @SerialName("num_serie") val numSerie: String? = null, @SerialName("date_achat") val dateAchat: String? = null,
    val statut: String = "", val service: Ref? = null, val utilisateur: Ref? = null,
    @SerialName("pannes_ouvertes") val pannesOuvertes: Int = 0,
)

@Serializable data class Affectation(val id: Int, val utilisateur: String? = null, val service: String? = null, val debut: String? = null, val fin: String? = null)

@Serializable data class Config(
    @SerialName("app_version_courante") val versionCourante: String = "", @SerialName("app_version_minimale") val versionMinimale: String = "",
    @SerialName("apk_url") val apkUrl: String? = null, @SerialName("notes_version") val notes: String? = null,
)

/** Déclaration saisie sur le téléphone, envoyée dès que le réseau le permet (idempotente via [uuid]). */
@Serializable data class Brouillon(
    val uuid: String, val titre: String, val description: String, val priorite: String,
    val serviceId: Int? = null, val materielId: Int? = null, val cree: Long = System.currentTimeMillis(),
    val erreur: String? = null,
)

@Serializable data class TacheRef(val id: Int, val reference: String? = null, val statut: String = "")

@Serializable data class AppNotification(
    val id: Long, val type: String, val titre: String, val message: String, val tache: TacheRef,
    @SerialName("peut_prendre") val peutPrendre: Boolean = false, val lue: Boolean = false,
    @SerialName("created_at") val createdAt: String? = null,
)

@Serializable data class Preferences(val email: Boolean = true, val sms: Boolean = true)

/** Compte vu par un gestionnaire (liste détaillée des utilisateurs). */
@Serializable data class UtilisateurAdmin(
    val id: Int, val nom: String, val login: String, val email: String? = null, val telephone: String? = null,
    val role: String = "", val service: Service? = null, val actif: Boolean = true,
    @SerialName("connexion_autorisee") val connexionAutorisee: Boolean = true,
    @SerialName("compte_technique") val compteTechnique: Boolean = false,
)

@Serializable data class Role(
    val nom: String, val description: String? = null, val systeme: Boolean = false,
    @SerialName("nb_utilisateurs") val nbUtilisateurs: Int = 0,
)

@Serializable data class ServiceAdmin(
    val id: Int, val nom: String, val description: String? = null,
    @SerialName("nb_utilisateurs") val nbUtilisateurs: Int = 0, @SerialName("nb_materiels") val nbMateriels: Int = 0,
)

// ------------------------------------------------------------------ Renouvellement du matériel
@Serializable data class CritereRenouv(val code: String = "", val texte: String = "")
@Serializable data class MaterielRenouv(val id: Int, val libelle: String, val type: String? = null, val service: String? = null, val utilisateur: String? = null)
@Serializable data class SuggestionRenouv(
    val materiel: MaterielRenouv, @SerialName("age_mois") val ageMois: Int? = null, @SerialName("duree_vie_mois") val dureeVieMois: Int = 60,
    @SerialName("pannes_recentes") val pannesRecentes: Int = 0, val criteres: List<CritereRenouv> = emptyList(), val score: Int = 0,
)
@Serializable data class Renouvellement(
    val id: Int, val materiel: MaterielRenouv, val origine: String = "auto", val statut: String, val motif: String? = null,
    val criteres: List<CritereRenouv> = emptyList(), @SerialName("cout_estime") val coutEstime: Double? = null,
    @SerialName("propose_par") val proposePar: String? = null, @SerialName("decide_par") val decidePar: String? = null,
    val commentaire: String? = null,
)

// ------------------------------------------------------------------ Planning
@Serializable data class TacheRefPlanning(val id: Int, val reference: String? = null, val statut: String = "", val priorite: String = "")
@Serializable data class EvenementPlanning(
    val date: String, val type: String, val titre: String, val detail: String? = null, val assigne: String? = null,
    val retard: Boolean = false, val tache: TacheRefPlanning? = null,
)

// ------------------------------------------------------------------ Inventaires
@Serializable data class CompteursInv(
    val attendus: Int = 0, @SerialName("a_pointer") val aPointer: Int = 0, val presents: Int = 0, val deplaces: Int = 0, val manquants: Int = 0,
    @SerialName("hors_perimetre") val horsPerimetre: Int = 0, @SerialName("non_references") val nonReferences: Int = 0, val pointes: Int = 0,
)
@Serializable data class MaterielInv(
    val id: Int, val libelle: String, @SerialName("num_serie") val numSerie: String? = null, val service: String? = null, val utilisateur: String? = null,
)
@Serializable data class LigneInv(
    val id: Int, val statut: String, @SerialName("hors_perimetre") val horsPerimetre: Boolean = false, val observation: String? = null,
    @SerialName("code_scanne") val codeScanne: String? = null, @SerialName("pointe_par") val pointePar: String? = null,
    val materiel: MaterielInv? = null, val compteurs: CompteursInv? = null,
)
@Serializable data class PerimetreInv(val service: String? = null, val type: String? = null)
@Serializable data class Inventaire(
    val id: Int, val titre: String, val description: String? = null, val perimetre: PerimetreInv = PerimetreInv(),
    @SerialName("date_limite") val dateLimite: String? = null, val statut: String = "Ouvert", val compteurs: CompteursInv = CompteursInv(),
    val lignes: List<LigneInv> = emptyList(),
)

fun calculerCompteurs(lignes: List<LigneInv>): CompteursInv {
    val attendues = lignes.filter { !it.horsPerimetre && it.statut != "Non référencé" }
    val aPointer = attendues.count { it.statut == "À pointer" }
    return CompteursInv(attendus = attendues.size, aPointer = aPointer, presents = attendues.count { it.statut == "Présent" },
        deplaces = attendues.count { it.statut == "Déplacé" }, manquants = attendues.count { it.statut == "Manquant" },
        horsPerimetre = lignes.count { it.horsPerimetre && it.statut != "Non référencé" }, nonReferences = lignes.count { it.statut == "Non référencé" },
        pointes = attendues.size - aPointer)
}
