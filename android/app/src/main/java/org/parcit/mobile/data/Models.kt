package org.parcit.mobile.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable data class Ref(val id: Int? = null, val nom: String? = null, val libelle: String? = null)
@Serializable data class Service(val id: Int, val nom: String)

@Serializable data class Utilisateur(
    val id: Int, val nom: String, val login: String, val email: String? = null, val telephone: String? = null,
    val role: String = "", val service: Service? = null, val permissions: List<String> = emptyList(),
    @SerialName("agent_it") val agentIt: Boolean = false, val notifications: Preferences = Preferences(),
) { fun peut(code: String) = code in permissions }

@Serializable data class Dates(
    val creation: String? = null, @SerialName("prise_en_charge") val priseEnCharge: String? = null,
    val resolution: String? = null,
)

@Serializable data class Actions(
    val prendre: Boolean = false, val affecter: Boolean = false,
    @SerialName("changer_statut") val changerStatut: Boolean = false, val rouvrir: Boolean = false,
    val annuler: Boolean = false, val commenter: Boolean = false,
)

@Serializable data class Evenement(
    val id: Int, val type: String, @SerialName("created_at") val createdAt: String? = null,
    val acteur: Ref? = null, @SerialName("ancien_statut") val ancienStatut: String? = null,
    @SerialName("nouveau_statut") val nouveauStatut: String? = null,
    @SerialName("nouveau_assigne") val nouveauAssigne: Ref? = null, val commentaire: String? = null,
)

@Serializable data class Tache(
    val id: Int, val reference: String? = null, val titre: String, val description: String? = null,
    val priorite: String = "Moyenne", val statut: String = "À faire", val service: String? = null,
    val materiel: Ref? = null, val assigne: Ref? = null, @SerialName("en_file") val enFile: Boolean = false,
    val declarant: Ref? = null, @SerialName("motif_attente") val motifAttente: String? = null,
    val dates: Dates = Dates(), val version: Int = 0, @SerialName("updated_at") val updatedAt: String? = null,
    val actions: Actions = Actions(), val evenements: List<Evenement> = emptyList(),
)

@Serializable data class Materiel(val id: Int, val libelle: String)

@Serializable data class Referentiels(
    val services: List<Service> = emptyList(), val priorites: List<String> = listOf("Basse", "Moyenne", "Haute"),
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
