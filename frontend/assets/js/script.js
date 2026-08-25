// Ajoute automatiquement le token de session à tous les appels vers l'API
// backend (les API métier exigent désormais une session valide). Évite de
// modifier individuellement chacun des appels $.ajax existants.
// Passe par des en-têtes HTTP (pas par `data`) : muter `options.data` dans un
// ajaxPrefilter casse la sérialisation interne de jQuery ("v.data.replace is
// not a function"), les en-têtes n'ont pas ce problème.
$.ajaxPrefilter(function (options) {
    if (typeof options.url === 'string' && options.url.indexOf('backend/api/') !== -1) {
        const sessionToken = localStorage.getItem('session_token');
        const userId = localStorage.getItem('user_id');
        if (sessionToken && userId) {
            options.headers = $.extend({}, options.headers, {
                'X-Session-Token': sessionToken,
                'X-User-Id': userId
            });
        }
    }
});

$(document).ready(function () {

    // --- Paramètres applicatifs (seuils/fréquences), configurables depuis l'écran
    // Paramètres (admin) plutôt que codés en dur. Valeurs de secours ci-dessous au cas
    // où l'API serait indisponible ou l'utilisateur pas encore authentifié ; écrasées par
    // loadAppParametres() (appel synchrone, volontairement, pour éviter toute course avec
    // le reste de l'initialisation qui en dépend).
    let appParams = {
        seuil_echeance_proche_jours: 3,
        seuil_non_prise_en_charge_jours: 3,
        seuil_tache_dormante_jours: 7,
        intervalle_rafraichissement_secondes: 30,
        efficacite_fenetre_jours: 30
    };

    // Filtre période du tableau de bord (année civile en cours par défaut). S'applique aux
    // indicateurs/graphiques liés aux tâches ; pas à l'état du parc matériel (inventaire
    // courant) ni au widget personnel "Mes tâches du jour" (liste d'action "maintenant").
    function premierJourAnnee() {
        return `${new Date().getFullYear()}-01-01`;
    }
    function dernierJourAnnee() {
        return `${new Date().getFullYear()}-12-31`;
    }
    let dashboardPeriode = {
        debut: premierJourAnnee(),
        fin: dernierJourAnnee()
    };
    function periodeQueryString() {
        return `&debut=${dashboardPeriode.debut}&fin=${dashboardPeriode.fin}`;
    }

    function loadAppParametres() {
        $.ajax({
            url: '../backend/api/parametres.php?action=public',
            type: 'GET',
            dataType: 'json',
            async: false, // bootstrap : le reste de l'init utilise ces valeurs dès le départ
            success: function (data) {
                appParams = $.extend(appParams, data);
            },
            error: function () {
                console.error('Paramètres applicatifs indisponibles, valeurs par défaut utilisées.');
            }
        });
    }
    loadAppParametres();

    // Écran Paramètres réservé aux Administrateurs (contient la config SMTP) : masqué du
    // menu pour tout autre rôle. Appliqué tout de suite avec le rôle en cache (évite un
    // flash du lien avant la réponse serveur), puis reconfirmé dans loadUserInfo().
    function applyAdminOnlyUI(role) {
        const estAdmin = role === 'Administrateur';
        $('.sidebar-menu a[href="#parametres"]').closest('li').toggle(estAdmin);
        // Gestion des utilisateurs (même la propre fiche d'un non-admin) : réservée aux
        // admins désormais — un utilisateur passe par "Mon profil" pour la sienne.
        $('.sidebar-menu a[href="#utilisateurs"]').closest('li').toggle(estAdmin);
    }
    applyAdminOnlyUI(localStorage.getItem('user_role'));

    // --- GESTION DE L'AUTHENTIFICATION ---

    // Vérifier si l'utilisateur est connecté
    checkAuthentication();

    // Fonction pour vérifier l'authentification
    function checkAuthentication() {
        const sessionToken = localStorage.getItem('session_token');
        const userId = localStorage.getItem('user_id');
        
        if (!sessionToken || !userId) {
            // Pas de session, rediriger vers la page de connexion
            window.location.href = 'login.html';
            return;
        }
        
        // Vérifier la validité de la session
        $.ajax({
            url: '../backend/api/auth.php?action=check_session',
            type: 'POST',
            data: {
                session_token: sessionToken,
                user_id: userId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Session valide, charger les informations utilisateur
                    loadUserInfo();
                    loadTaskNotifications(true); // Badges + résumé/escalade (arrivée sur le tableau de bord)
                } else {
                    // Session invalide, rediriger vers la page de connexion
                    clearSession();
                    window.location.href = 'login.html';
                }
            },
            error: function() {
                // Erreur de communication, rediriger vers la page de connexion
                clearSession();
                window.location.href = 'login.html';
            }
        });
    }

    // Fonction pour charger les informations utilisateur
    function loadUserInfo() {
        const sessionToken = localStorage.getItem('session_token');
        const userId = localStorage.getItem('user_id');
        
        $.ajax({
            url: '../backend/api/auth.php?action=get_user_info',
            type: 'POST',
            data: {
                session_token: sessionToken,
                user_id: userId
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Afficher les informations utilisateur
                    $('#user-name').text(response.user.nom);
                    $('#user-full-name').text(response.user.nom);
                    $('#user-role').text(response.user.role);
                    // Stocker le login pour la traçabilité
                    if (response.user.login) {
                        localStorage.setItem('user_login', response.user.login);
                    }
                    applyAdminOnlyUI(response.user.role);
                }
            },
            error: function() {
                console.error('Erreur lors du chargement des informations utilisateur');
            }
        });
    }

    // --- Notifications pop (toasts) ---
    // Affiche une notification qui disparaît d'elle-même après quelques secondes.
    // Cliquer dessus (ou sur la croix) la ferme immédiatement. Si `onClick` est fourni,
    // cliquer sur le corps du toast déclenche cette action avant de le fermer (ex: aller
    // directement voir la liste des tâches concernées).
    function showToast(title, message, icon = 'fa-bell', onClick = null) {
        const $container = $('#toast-container');
        const $toast = $(`
            <div class="toast">
                <div class="toast-icon"><i class="fas ${icon}"></i></div>
                <div class="toast-body">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <div class="toast-close"><i class="fas fa-times"></i></div>
            </div>
        `);

        function fermer() {
            $toast.addClass('toast-hiding');
            setTimeout(() => $toast.remove(), 250);
        }

        $toast.on('click', function (e) {
            if (onClick && !$(e.target).closest('.toast-close').length) {
                onClick();
            }
            fermer();
        });
        $container.append($toast);
        setTimeout(fermer, 6000);
    }

    // Navigue vers la page Tâches (vue liste) avec un filtre de date/statut appliqué —
    // réutilisé par les badges du header et les toasts d'alerte pour rendre chaque
    // notification directement actionnable.
    function goToTachesFiltered(filterDateValue) {
        $(`.sidebar-menu a[href="#taches"]`).click();
        $('#btn-vue-liste').trigger('click');
        $('#filter-statut').val('');
        $('#filter-priorite').val('');
        setTimeout(() => loadTaches(filterDateValue), 0);
    }

    // Mémorise les tâches déjà notifiées (par utilisateur), pour ne notifier qu'une
    // seule fois par tâche même après un rechargement de page.
    function getTachesNotifiees() {
        const userId = localStorage.getItem('user_id');
        try {
            return JSON.parse(localStorage.getItem(`notified_task_ids_${userId}`)) || [];
        } catch (e) {
            return [];
        }
    }
    function setTachesNotifiees(ids) {
        const userId = localStorage.getItem('user_id');
        localStorage.setItem(`notified_task_ids_${userId}`, JSON.stringify(ids));
    }

    // Fonction pour charger les notifications de tâches. `showReminders` déclenche en plus
    // le résumé quotidien et l'alerte d'escalade (toasts) — réservé aux moments où
    // l'utilisateur arrive/revient sur le tableau de bord, pas au rafraîchissement
    // silencieux des badges toutes les 30 secondes.
    function loadTaskNotifications(showReminders) {
        const userName   = localStorage.getItem('user_name');
        if (!userName) return;

        $.ajax({
            url: `../backend/api/taches.php?action=user_task_stats&user_name=${userName}`,
            type: 'GET',
            dataType: 'json',
            success: function (stats) {
                const { new_tasks, in_progress_tasks, late_tasks, echeance_proche_tasks, completed_today, new_tasks_list } = stats;

                // Widget personnel "Mes tâches du jour" (tableau de bord)
                updateMesTachesWidget(stats);

                if (showReminders) {
                    // Résumé + relance des tâches non prises en charge, à chaque retour
                    // sur le tableau de bord (et à la connexion) — pas en continu.
                    showTasksDigestToast(stats);
                    checkEscalations();
                }

                // Badge pour les nouvelles tâches (positionné sur l'icône utilisateur)
                const $newBadge = $('#new-tasks-badge');
                if (new_tasks > 0) {
                    $newBadge.text(new_tasks > 99 ? '99+' : new_tasks).show();
                } else {
                    $newBadge.hide();
                }

                // Badge pour les tâches en cours
                const $inProgressBadge = $('#in-progress-tasks-badge');
                if (in_progress_tasks > 0) {
                    $inProgressBadge.text(in_progress_tasks).show();
                } else {
                    $inProgressBadge.hide();
                }

                // Badge pour les tâches en retard (assignées à l'utilisateur courant)
                const $lateBadge = $('#late-tasks-badge');
                if (late_tasks > 0) {
                    $lateBadge.html(`<i class="fas fa-exclamation-triangle"></i> ${late_tasks > 99 ? '99+' : late_tasks}`).show();
                } else {
                    $lateBadge.hide();
                }

                // Notification pop pour chaque tâche "À faire" pas encore notifiée
                if (Array.isArray(new_tasks_list) && new_tasks_list.length > 0) {
                    const dejaNotifiees = getTachesNotifiees();
                    const nouvelles = new_tasks_list.filter(t => !dejaNotifiees.includes(t.id));

                    if (nouvelles.length > 0) {
                        if (nouvelles.length === 1) {
                            showToast('Nouvelle tâche assignée', nouvelles[0].titre, 'fa-tasks');
                        } else {
                            showToast(
                                'Nouvelles tâches assignées',
                                `Vous avez ${nouvelles.length} nouvelles tâches à traiter.`,
                                'fa-tasks'
                            );
                        }
                        setTachesNotifiees(dejaNotifiees.concat(nouvelles.map(t => t.id)));
                    }
                }
            },
            error: function (xhr, status, error) {
                console.error("Erreur lors du chargement des notifications de tâches:", error);
            }
        });
    }

    // Met à jour le widget "Mes tâches du jour" du tableau de bord : barre de progression
    // (tâches terminées aujourd'hui vs. encore actives) + compteurs par catégorie.
    function updateMesTachesWidget(stats) {
        const $widget = $('#mes-taches-progress-bar');
        if ($widget.length === 0) return; // widget absent de la page courante

        const { new_tasks, in_progress_tasks, late_tasks, echeance_proche_tasks, completed_today } = stats;
        const totalActif = new_tasks + in_progress_tasks;
        const totalEnJeu = totalActif + completed_today;
        const pct = totalEnJeu > 0 ? Math.round((completed_today / totalEnJeu) * 100) : 0;

        $('#mes-taches-progress-bar').css('width', pct + '%');

        let label;
        if (totalActif > 0) {
            label = `${completed_today} terminée(s) aujourd'hui — ${totalActif} encore active(s)`;
        } else if (completed_today > 0) {
            label = `${completed_today} terminée(s) aujourd'hui — bravo, tout est à jour !`;
        } else {
            label = 'Aucune tâche active pour le moment.';
        }
        $('#mes-taches-progress-label').text(label);

        $('#mes-taches-a-faire').text(new_tasks);
        $('#mes-taches-en-cours').text(in_progress_tasks);
        $('#mes-taches-retard').text(late_tasks);
        $('#mes-taches-echeance').text(echeance_proche_tasks);

        updateEfficaciteGauge(stats.efficacite_pct, stats.efficacite_zone);
    }

    // Couleur associée à chaque zone d'efficacité (mêmes seuils que le backend, calculés
    // côté serveur — voir backend/config/parametres.php::efficacite_zone).
    const EFFICACITE_COULEURS = {
        bon: 'var(--color-success)',
        moyen: 'var(--color-warning)',
        mauvais: 'var(--color-danger)'
    };

    // Met à jour l'anneau d'efficacité (widget "Mes tâches du jour"). `pct`/`zone` peuvent
    // être null (pas assez de données sur la fenêtre configurée).
    function updateEfficaciteGauge(pct, zone) {
        const $gauge = $('#efficacite-gauge');
        if ($gauge.length === 0) return;

        const fenetre = appParams.efficacite_fenetre_jours;

        if (pct === null || pct === undefined) {
            $gauge.css('background', 'conic-gradient(var(--color-border) 0deg 360deg)');
            $('#efficacite-gauge-value').text('–');
            $('#efficacite-gauge-hint').text(`Pas encore assez de tâches résolues sur les ${fenetre} derniers jours pour calculer un taux.`);
            return;
        }

        const couleur = EFFICACITE_COULEURS[zone] || 'var(--color-border)';
        const angle = Math.max(0, Math.min(360, Math.round(pct * 3.6)));
        $gauge.css('background', `conic-gradient(${couleur} 0deg ${angle}deg, var(--color-border) ${angle}deg 360deg)`);
        $('#efficacite-gauge-value').text(pct + '%');
        $('#efficacite-gauge-hint').text(`Efficacité sur les ${fenetre} derniers jours : tâches terminées / (terminées + en retard).`);
    }

    // Affiche un résumé des tâches en retard/à échéance proche — appelé à chaque retour
    // sur le tableau de bord (voir `showReminders` dans loadTaskNotifications), pas juste
    // à la connexion, pour relancer même sans nouvelle tâche assignée. Cliquer dessus
    // amène directement à la liste concernée.
    function showTasksDigestToast(stats) {
        const { late_tasks, echeance_proche_tasks, in_progress_tasks } = stats;
        if (late_tasks > 0 || echeance_proche_tasks > 0) {
            const parts = [];
            if (late_tasks > 0) parts.push(`${late_tasks} en retard`);
            if (echeance_proche_tasks > 0) parts.push(`${echeance_proche_tasks} à échéance proche`);
            const enCoursSuffix = in_progress_tasks > 0 ? ` (${in_progress_tasks} en cours)` : '';
            showToast(
                'Résumé de vos tâches',
                `Vous avez ${parts.join(' et ')}.${enCoursSuffix} Cliquez pour voir la liste.`,
                'fa-clipboard-list',
                () => goToTachesFiltered(late_tasks > 0 ? 'en_retard' : 'echeance_proche')
            );
        }
    }

    // Relance les administrateurs, à chaque retour sur le tableau de bord, sur les tâches
    // "À faire" jamais prises en charge depuis plusieurs jours — pas de notion de manager
    // dans le schéma, donc on notifie tous les comptes Administrateur. Cliquer sur le toast
    // amène directement à la liste (voir aussi la carte "Non prises en charge" du dashboard,
    // visible à tout moment sans attendre cette alerte).
    function checkEscalations() {
        if (localStorage.getItem('user_role') !== 'Administrateur') return;

        $.ajax({
            url: '../backend/api/dashboard.php?action=escalations',
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                const taches = Array.isArray(data.taches) ? data.taches : [];
                if (taches.length === 0) return;
                showToast(
                    'Tâches non prises en charge',
                    `${taches.length} tâche(s) "À faire" depuis plus de ${data.seuil_jours} jours sans prise en charge. Cliquez pour voir la liste.`,
                    'fa-triangle-exclamation',
                    () => goToTachesFiltered('non_prises_en_charge')
                );
            },
            error: function (xhr, status, error) {
                console.error("Erreur lors de la vérification des escalades:", error);
            }
        });
    }

    // Ajout des gestionnaires d'événements pour les badges
    $(document).on('click', '#new-tasks-badge', function(e) {
        e.stopPropagation(); // Évite de déclencher aussi l'ouverture du menu utilisateur
        const page = $(this).data('page');

        if (page) {
            // Simuler un clic sur le menu pour changer de page
            $(`.sidebar-menu a[href="#${page}"]`).click();
            $('#btn-vue-liste').trigger('click'); // Les filtres ne s'appliquent qu'en vue liste
            $('#filter-statut').val('À faire').change(); // On met le filtre sur 'À faire'
        }

    });

    $(document).on('click', '#in-progress-tasks-badge', function() {
        const page = $(this).data('page');
        const filter = $(this).data('filter');

        if (page) {
            // Simuler un clic sur le menu pour changer de page
            $(`.sidebar-menu a[href="#${page}"]`).click();
            $('#btn-vue-liste').trigger('click');
            $('#filter-statut').val('En cours').change(); // On met le filtre sur 'En cours'
        }


    });

    $(document).on('click', '#late-tasks-badge', function(e) {
        e.stopPropagation();
        const page = $(this).data('page');

        if (page) {
            goToTachesFiltered('en_retard');
        }
    });


    // Fonction pour nettoyer la session
    function clearSession() {
        localStorage.removeItem('user_id');
        localStorage.removeItem('user_name');
        localStorage.removeItem('user_role');
        localStorage.removeItem('user_login');
        localStorage.removeItem('session_token');
    }

    // Gestion de la déconnexion
    $('#logout-btn').on('click', function() {
        const sessionToken = localStorage.getItem('session_token');
        
        $.ajax({
            url: '../backend/api/auth.php?action=logout',
            type: 'POST',
            data: {
                session_token: sessionToken
            },
            dataType: 'json',
            success: function(response) {
                clearSession();
                window.location.href = 'login.html';
            },
            error: function() {
                // Même en cas d'erreur, nettoyer la session locale
                clearSession();
                window.location.href = 'login.html';
            }
        });
    });

    // Gestion du menu utilisateur
    $('#user-menu-toggle').on('click', function() {
        $('.user-menu').toggleClass('open');
    });

    // "Mon profil" : tout utilisateur modifie SON PROPRE compte par ce biais, plutôt que
    // par la page "Gestion des Utilisateurs" (réservée aux administrateurs — voir
    // applyAdminOnlyUI/loadUtilisateurs). Réutilise le même formulaire/modale que
    // l'édition admin, avec le champ Rôle masqué pour un non-administrateur : il ne peut
    // de toute façon pas se l'auto-attribuer (verrouillé côté serveur), autant ne pas
    // laisser un contrôle qui n'a aucun effet.
    $('#mon-profil-btn').on('click', function () {
        $('.user-menu').removeClass('open');

        const userId = localStorage.getItem('user_id');
        const userRole = localStorage.getItem('user_role');

        $.ajax({
            url: `../backend/api/utilisateurs.php?action=read&id=${userId}`,
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                const utilisateur = Array.isArray(data) ? data[0] : null;
                if (!utilisateur) {
                    alert('Erreur: impossible de récupérer votre profil.');
                    return;
                }

                openModal('utilisateurs', 'edit');
                $('#modal-title').text('Mon profil');

                $('#utilisateur-form').append('<input type="hidden" name="id" value="' + utilisateur.id + '">');
                $('#nom').val(utilisateur.nom);
                $('#login').val(utilisateur.login);
                $('#email').val(utilisateur.email);
                $('#role').val(utilisateur.role);
                $('#mot_de_passe').val('');

                if (userRole !== 'Administrateur') {
                    $('#role-form-group').hide();
                }
            },
            error: function () {
                alert('Erreur: impossible de récupérer votre profil.');
            }
        });
    });

    // Fermer le menu utilisateur en cliquant ailleurs
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.user-menu').length) {
            $('.user-menu').removeClass('open');
        }
    });

    // --- Écran Paramètres (Administrateur) : notifications email, SMTP, seuils/fréquences ---
    // Les champs texte/nombre/select sont mappés 1:1 sur leur id = clé de paramètre côté
    // API, ce qui permet un remplissage/collecte générique (pas de liste à maintenir).
    const PARAMETRES_CHAMPS_TEXTE = [
        'seuil_echeance_proche_jours', 'seuil_non_prise_en_charge_jours', 'seuil_tache_dormante_jours',
        'intervalle_rafraichissement_secondes',
        'efficacite_fenetre_jours', 'efficacite_seuil_bon', 'efficacite_seuil_moyen',
        'escalade_email_destinataires', 'frequence_digest_heures', 'frequence_escalade_heures',
        'smtp_provider', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_email', 'smtp_from_name'
    ];

    // Préréglages SMTP : pré-remplissent hôte/port/chiffrement pour un fournisseur connu et
    // affichent une aide contextuelle (où récupérer les identifiants) — l'admin choisit
    // ensuite librement (voir la question utilisateur "on donne le choix à l'admin").
    const SMTP_PRESETS = {
        gmail: {
            host: 'smtp.gmail.com', port: '587', encryption: 'tls',
            hint: 'Nécessite un compte Gmail avec la validation en 2 étapes activée, puis un mot de passe '
                + "d'application (myaccount.google.com/apppasswords). Nom d'utilisateur = adresse email complète."
        },
        brevo: {
            host: 'smtp-relay.brevo.com', port: '587', encryption: 'tls',
            hint: 'Compte gratuit sur brevo.com, puis identifiants SMTP dans Paramètres > SMTP & API de votre '
                + 'compte Brevo. Le mot de passe est la clé SMTP générée, pas le mot de passe du compte.'
        },
        custom: {
            hint: 'Renseignez les informations de connexion fournies par votre serveur ou fournisseur SMTP.'
        }
    };

    // Affiche l'aide du fournisseur actuellement sélectionné, sans toucher aux champs
    // (utilisé au chargement, pour ne pas écraser une config déjà enregistrée).
    function updateSmtpProviderHint() {
        const preset = SMTP_PRESETS[$('#smtp_provider').val()] || SMTP_PRESETS.custom;
        $('#smtp_provider_hint').text(preset.hint);
    }

    // Changement explicite de fournisseur par l'admin : applique le préréglage
    // (hôte/port/chiffrement), qui reste ensuite librement modifiable à la main.
    $('#smtp_provider').on('change', function () {
        const preset = SMTP_PRESETS[$(this).val()];
        if (preset && preset.host) {
            $('#smtp_host').val(preset.host);
            $('#smtp_port').val(preset.port);
            $('#smtp_encryption').val(preset.encryption);
        }
        updateSmtpProviderHint();
    });
    const PARAMETRES_CASES_A_COCHER = [
        'email_notifications_actives', 'email_on_nouvelle_tache_assignee',
        'email_on_digest_quotidien', 'email_on_escalade'
    ];

    function loadParametres() {
        if (localStorage.getItem('user_role') !== 'Administrateur') {
            $('#parametres-form').hide();
            $('#parametres-acces-refuse').show();
            return;
        }
        $('#parametres-acces-refuse').hide();
        $('#parametres-form').show();

        $.ajax({
            url: '../backend/api/parametres.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                PARAMETRES_CHAMPS_TEXTE.forEach(cle => $('#' + cle).val(data[cle] ?? ''));
                PARAMETRES_CASES_A_COCHER.forEach(cle => $('#' + cle).prop('checked', data[cle] === '1'));
                updateSmtpProviderHint(); // affiche l'aide sans écraser les champs déjà chargés

                $('#smtp_password_hint').text(
                    data.smtp_password_configure
                        ? 'Un mot de passe est déjà enregistré ; laissez ce champ vide pour le conserver.'
                        : 'Aucun mot de passe enregistré pour le moment.'
                );
                $('#parametres-save-status').text('');
                $('#parametres-test-email-resultat').text('');
            },
            error: function (xhr) {
                if (xhr.status === 403) {
                    $('#parametres-form').hide();
                    $('#parametres-acces-refuse').show();
                } else {
                    console.error('Erreur lors du chargement des paramètres.');
                }
            }
        });
    }

    $('#parametres-form').on('submit', function (e) {
        e.preventDefault();

        const donnees = {};
        PARAMETRES_CHAMPS_TEXTE.forEach(cle => { donnees[cle] = $('#' + cle).val(); });
        PARAMETRES_CASES_A_COCHER.forEach(cle => { donnees[cle] = $('#' + cle).is(':checked') ? '1' : '0'; });
        donnees['smtp_password'] = $('#smtp_password').val(); // vide = conservé côté API

        $('#parametres-save-status').text('Enregistrement…');

        $.ajax({
            url: '../backend/api/parametres.php?action=update',
            type: 'POST',
            data: donnees,
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    $('#parametres-save-status').text('Enregistré.');
                    $('#smtp_password').val('');
                    showToast('Paramètres enregistrés', 'Les modifications ont été prises en compte.', 'fa-check-circle');
                    loadParametres(); // recharge (met à jour l'indication mot de passe, etc.)
                } else {
                    const message = response.error || 'Une erreur est survenue.';
                    $('#parametres-save-status').text('Erreur : ' + message);
                    showToast('Échec de l\'enregistrement', message, 'fa-triangle-exclamation');
                }
            },
            error: function (xhr) {
                let message = 'Erreur lors de l\'enregistrement.';
                try { message = JSON.parse(xhr.responseText).error || message; } catch (e) {}
                $('#parametres-save-status').text('Erreur : ' + message);
                showToast('Échec de l\'enregistrement', message, 'fa-triangle-exclamation');
            }
        });
    });

    $('#btn-test-email').on('click', function () {
        const destinataire = $('#parametres-test-email').val().trim();
        if (!destinataire) {
            $('#parametres-test-email-resultat').text('Indiquez une adresse email de test.');
            return;
        }

        const $btn = $(this);
        $btn.prop('disabled', true);
        $('#parametres-test-email-resultat').text('Envoi en cours…');

        // Teste avec les valeurs actuellement saisies dans le formulaire (pas besoin
        // d'enregistrer d'abord pour vérifier que la config SMTP fonctionne).
        const donnees = { destinataire };
        ['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_email', 'smtp_from_name'].forEach(cle => {
            donnees[cle] = $('#' + cle).val();
        });
        const motDePasseSaisi = $('#smtp_password').val();
        if (motDePasseSaisi) {
            donnees['smtp_password'] = motDePasseSaisi;
        }

        $.ajax({
            url: '../backend/api/parametres.php?action=test_email',
            type: 'POST',
            data: donnees,
            dataType: 'json',
            success: function (response) {
                $('#parametres-test-email-resultat').text(response.success ? '✅ ' + response.message : '❌ ' + response.error);
            },
            error: function (xhr) {
                let message = 'Échec du test.';
                try { message = JSON.parse(xhr.responseText).error || message; } catch (e) {}
                $('#parametres-test-email-resultat').text('❌ ' + message);
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    });

    // --- Gestion du Menu Latéral (Sidebar) ---
    $('#menu-toggle').on('click', function (event) {
        event.stopPropagation();
        $('body').toggleClass('sidebar-open');
    });

    $('#main-content').on('click', function () {
        if ($('body').hasClass('sidebar-open') && $(window).width() <= 768) {
            $('body').removeClass('sidebar-open');
        }
    });

    // --- Navigation entre les pages ---
    $('.sidebar-menu a').on('click', function (e) {
        e.preventDefault();

        $('.sidebar-menu li').removeClass('active');
        $(this).parent('li').addClass('active');

        const newTitle = $(this).data('title');
        $('#main-title').text(newTitle);        

        const targetPage = $(this).attr('href');
        $('.page-content').removeClass('active');
        $(targetPage + '-page').addClass('active');

        if ($(window).width() <= 768) {
            $('body').removeClass('sidebar-open');
        }
        loadMateriel();
        loadTaches();
        loadServices();
        loadUtilisateurs();
        if (targetPage === '#dashboard') {
            // Chaque retour sur le tableau de bord rafraîchit ses chiffres et relance le
            // résumé/l'alerte d'escalade — pas seulement à la connexion.
            updateDashboardStats();
            loadTaskNotifications(true);
        }
        if (targetPage === '#parametres') {
            loadParametres();
        }
        if (newTitle === 'Gestion du Matériel') {
            updateTotalCount('#materiel-table', '#main-title', newTitle);
        }else if(newTitle === 'Gestion des Tâches') {
            updateTotalCount('#taches-table', '#main-title', newTitle);
        }else if(newTitle === 'Gestion des Services') {
            updateTotalCount('#services-table', '#main-title', newTitle);
        }else if(newTitle === 'Gestion des Utilisateurs') {
            updateTotalCount('#utilisateurs-table', '#main-title', newTitle);
        }
    });


    // =================================================================
    // NOUVELLE LOGIQUE POUR LE MATERIEL
    // =================================================================

    // Fonction pour charger et afficher le matériel depuis l'API
    function loadMateriel() {
         const userRole = localStorage.getItem('user_role');
        const userId = localStorage.getItem('user_id');

        $.ajax({
            url: '../backend/api/materiel.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                const tableBody = $('#materiel-table tbody');
                tableBody.empty();

                const types = new Set();
                const statuts = new Set();

                $.each(data, function (index, item) {
                    // Création des badges de statut avec les bonnes couleurs
                    let statusBadge = '';
                    if (item.statut === 'En service') {
                        statusBadge = '<span class="status-badge status-success">En service</span>';
                    } else if (item.statut === 'En panne') {
                        statusBadge = '<span class="status-badge status-danger">En panne</span>';
                    } else if (item.statut === 'En attente') {
                        statusBadge = '<span class="status-badge status-warning">En attente</span>';
                    } else {
                        statusBadge = '<span class="status-badge status-info">' + item.statut + '</span>';
                    }

                    const dateDerniereAffect = item.date_derniere_affect
                        ? new Date(item.date_derniere_affect).toLocaleDateString('fr-FR')
                        : '-';

                    const row = `
                        <tr>
                            <td>${item.type}</td>
                            <td>${item.marque} ${item.modele}</td>
                            <td>${item.num_serie}</td>
                            <td>${item.utilisateur_assigne || '-'}</td>
                            <td>${item.service_affecte || '-'}</td>
                            <td>${dateDerniereAffect}</td>
                            <td>${statusBadge}</td>
                            <td class="actions">
                                <button class="btn-action btn-qrcode" data-id="${item.id}" data-label="${item.type} ${item.marque} ${item.modele}" data-serie="${item.num_serie || ''}" data-service="${item.service_affecte || ''}" title="Étiquette d'inventaire (QR code)"><i class="fas fa-qrcode"></i></button>
                                <button class="btn-action btn-historique" data-id="${item.id}" data-label="${item.type} ${item.marque} ${item.modele} (${item.num_serie})" title="Historique d'affectation"><i class="fas fa-history"></i></button>
                                <button class="btn-action btn-edit" data-id="${item.id}"><i class="fas fa-pencil-alt"></i></button>
                                <button class="btn-action btn-delete" data-id="${item.id}" style="display: ${userRole === 'Administrateur' ? '' : 'none'};"><i class="fas fa-trash-alt"></i></button>
                            </td>
                        </tr>
                    `;
                    tableBody.append(row);
                });



            },
            error: function (xhr, status, error) {
                console.error("Erreur lors du chargement du matériel:", error);
                $('#materiel-table tbody').html('<tr><td colspan="8" class="table-error"><i class="fas fa-triangle-exclamation"></i>Erreur de chargement des données.</td></tr>');
            }
        });
    }

    // Affiche l'historique d'affectation (utilisateur/service) d'un matériel dans la modale
    function showMaterielHistorique(materielId, label) {
        $.ajax({
            url: '../backend/api/materiel.php?action=historique_affectations&id=' + materielId,
            type: 'GET',
            dataType: 'json',
            success: function (historique) {
                let rows = '';
                if (!historique || historique.length === 0) {
                    rows = '<tr><td colspan="4" style="text-align:center;">Aucun historique d\'affectation.</td></tr>';
                } else {
                    historique.forEach(function (h) {
                        const debut = new Date(h.date_debut).toLocaleDateString('fr-FR');
                        const fin = h.date_fin ? new Date(h.date_fin).toLocaleDateString('fr-FR') : '<em>En cours</em>';
                        rows += `
                            <tr>
                                <td>${h.utilisateur_assigne || '-'}</td>
                                <td>${h.service_affecte || '-'}</td>
                                <td>${debut}</td>
                                <td>${fin}</td>
                            </tr>
                        `;
                    });
                }

                modalTitle.text(`Historique d'affectation — ${label}`);
                modalBody.html(`
                    <table class="simple-table">
                        <thead>
                            <tr><th>Utilisateur</th><th>Service</th><th>Depuis</th><th>Jusqu'à</th></tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                `);
                modalFooter.html('<button type="button" class="btn btn-secondary" id="modal-cancel">Fermer</button>');
                setModalSize('md');
                modal.css('display', 'flex');
            },
            error: function () {
                alert('Erreur lors du chargement de l\'historique d\'affectation.');
            }
        });
    }

    // Affiche une étiquette d'inventaire (QR code + infos) pour un matériel, imprimable
    function showMaterielQRCode(materielId, label, numSerie, service) {
        let contenuBrut = `${label} | SN:${numSerie || 'N/A'} | ID:${materielId}`;
        if (service) {
            contenuBrut += ` | Service:${service}`;
        }
        // QRCode.js (bibliothèque ancienne) calcule mal la taille nécessaire dès que le
        // texte contient des caractères accentués (UTF-8 multi-octets) et plante avec
        // "code length overflow" même sur un texte court. On neutralise les accents
        // (é → e, à → a, etc.) pour rester en ASCII simple, sans risque.
        const contenu = contenuBrut.normalize('NFD').replace(/[\u0300-\u036f]/g, '');

        modalTitle.text(`Étiquette d'inventaire — ${label}`);
        modalBody.html(`
            <div id="qrcode-etiquette" style="text-align:center; padding: 10px;">
                <div id="qrcode-render" style="display:inline-block;"></div>
                <div style="margin-top:10px; font-weight:600;">${label}</div>
                <div style="color:#666;">N° série : ${numSerie || 'N/A'}</div>
                ${service ? `<div style="color:#666;">Service : ${service}</div>` : ''}
            </div>
        `);
        modalFooter.html(`
            <button type="button" class="btn btn-secondary" id="modal-cancel">Fermer</button>
            <button type="button" id="btn-imprimer-etiquette" class="btn btn-primary">
                <i class="fas fa-print"></i> Imprimer l'étiquette
            </button>
        `);
        setModalSize('sm');
        modal.css('display', 'flex');

        // QRCode.js a besoin que le conteneur soit déjà dans le DOM.
        // typeNumber: 0 laisse la bibliothèque choisir automatiquement la taille de
        // QR code adaptée à la longueur du texte (sans ça, elle utilise une petite
        // taille fixe et plante avec "code length overflow" dès que le texte est un
        // peu long : type + marque + modèle + n° série dépasse vite cette limite).
        new QRCode(document.getElementById('qrcode-render'), {
            text: contenu,
            width: 180,
            height: 180,
            typeNumber: 0,
            correctLevel: QRCode.CorrectLevel.M
        });
    }

    // Impression ciblée sur la seule étiquette (masque le reste de la page via CSS d'impression)
    $(document).on('click', '#btn-imprimer-etiquette', function () {
        window.print();
    });

    function populateFilter(selector, items, defaultOptionText) {
        const filter = $(selector);
        filter.empty().append(`<option value="">${defaultOptionText}</option>`);
        items.forEach(item => {
            if (item) filter.append(`<option value="${item}">${item}</option>`);
        });
    }

    // Fonction pour filtrer le tableau de matériel
    function filterMateriel() {
        const searchText = $('#materiel-search').val().toLowerCase();
        const selectedType = $('#filter-materiel-type').val();
        const selectedStatut = $('#filter-materiel-statut').val();
        const selectedService = $('#filter-materiel-service').val();

        $('#materiel-table tbody tr').each(function () {
            const row = $(this);
            const rowText = row.text().toLowerCase();
            const rowType = row.find('td:nth-child(1)').text();
            const rowStatut = row.find('td:nth-child(7) span').text().trim();
            const rowService = row.find('td:nth-child(5)').text();

            const textMatch = rowText.includes(searchText);
            const typeMatch = !selectedType || rowType === selectedType;
            const statutMatch = !selectedStatut || rowStatut === selectedStatut;
            const serviceMatch = !selectedService || rowService === selectedService;

            if (textMatch && typeMatch && statutMatch && serviceMatch) {
                row.show();
            } else {
                row.hide();
            }
        });
        //updateTotalCount('#materiel-table', '#materiel-total');
    }

    // Appliquer les filtres en temps réel pour le matériel
    $('#materiel-search, #filter-materiel-type, #filter-materiel-statut, #filter-materiel-service').on('change keyup', filterMateriel);

    // Fonction pour charger et afficher les services depuis l'API
    function loadServices() {
         const userRole = localStorage.getItem('user_role');
        const userId = localStorage.getItem('user_id');

        $.ajax({
            url: '../backend/api/services.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                const tableBody = $('#services-table tbody');
                tableBody.empty(); // On vide la table avant de la remplir

                if (data.length === 0) {
                    tableBody.append('<tr><td colspan="3" style="text-align:center;">Aucun service trouvé.</td></tr>');
                } else {
                    $.each(data, function (index, item) {
                        const row = `
                            <tr>
                                <td>${item.nom}</td>
                                <td>${item.description || '-'}</td>
                                <td class="actions">
                                    <button class="btn-action btn-edit" data-id="${item.id}"><i class="fas fa-pencil-alt"></i></button>
                                    <button class="btn-action btn-delete" data-id="${item.id}" style="display: ${userRole === 'Administrateur' ? '' : 'none'};"><i class="fas fa-trash-alt"></i></button>
                                </td>
                            </tr>
                        `;
                        tableBody.append(row);
                    });
                }
                //updateTotalCount('#services-table', '#services-total');
            },
            error: function (xhr, status, error) {
                console.error("Erreur lors du chargement des services:", error);
                $('#services-table tbody').html('<tr><td colspan="3" class="table-error"><i class="fas fa-triangle-exclamation"></i>Erreur de chargement des données.</td></tr>');
            }
        });
    }

    // Fonction pour charger et afficher les utilisateurs depuis l'API
    function loadUtilisateurs() {
        const userRole = localStorage.getItem('user_role');

        // La liste des utilisateurs (même filtrée à soi-même) est réservée aux
        // administrateurs — un utilisateur gère son propre compte via "Mon profil"
        // (menu utilisateur), pas cette page. Voir aussi la vérification serveur dans
        // utilisateurs.php?action=read (403 si non-admin sans ?id=).
        if (userRole !== 'Administrateur') {
            $('#utilisateurs-contenu').hide();
            $('#utilisateurs-acces-refuse').show();
            return;
        }
        $('#utilisateurs-acces-refuse').hide();
        $('#utilisateurs-contenu').show();

        const apiUrl = '../backend/api/utilisateurs.php?action=read';

        //console.log("URL de l'API des utilisateurs: " + apiUrl);


        $.ajax({
            url: apiUrl,
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                const tableBody = $('#utilisateurs-table tbody');
                tableBody.empty(); // On vide la table avant de la remplir

                if (data.length === 0) {
                    tableBody.append('<tr><td colspan="6" style="text-align:center;">Aucun utilisateur trouvé.</td></tr>');
                } else {
                    $.each(data, function (index, item) {
                        //console.log("User data from server: " + item);
                        // efficacite_pct n'est présent que pour un admin (voir utilisateurs.php) :
                        // absent pour un technicien consultant sa propre fiche — affiche '-'.
                        let effBadge = '<span class="no-data-inline">–</span>';
                        if (item.efficacite_pct !== undefined && item.efficacite_pct !== null) {
                            const statusClass = item.efficacite_zone === 'bon' ? 'status-success'
                                : item.efficacite_zone === 'moyen' ? 'status-warning' : 'status-danger';
                            effBadge = `<span class="status-badge ${statusClass}">${item.efficacite_pct}%</span>`;
                        }
                        const row = `
                            <tr>
                                <td>${item.nom}</td>
                                <td>${item.login}</td>
                                <td>${item.email || '-'}</td>
                                <td>${item.role}</td>
                                <td>${effBadge}</td>
                                <td class="actions">
                                    <button class="btn-action btn-edit" data-id="${item.id}"><i class="fas fa-pencil-alt"></i></button>
                                    <button class="btn-action btn-delete" data-id="${item.id}" style="display: ${userRole === 'Administrateur' ? '' : 'none'};"><i class="fas fa-trash-alt"></i></button>
                                </td>
                            </tr>
                        `;
                        tableBody.append(row);
                    });
                }
                //updateTotalCount('#utilisateurs-table', '#utilisateurs-total');
            },
            error: function (xhr, status, error) {
                console.error("Erreur lors du chargement des utilisateurs:", error);
                $('#utilisateurs-table tbody').html('<tr><td colspan="6" class="table-error"><i class="fas fa-triangle-exclamation"></i>Erreur de chargement des données.</td></tr>');
            }
        });
    }

    // Formate l'ancienneté d'une tâche ("aujourd'hui", "il y a 3 j", "il y a 2 mois"...)
    // pour rendre visibles, en un coup d'œil, les tâches anciennes qu'on oublie de traiter.
    function formatAge(dateStr) {
        if (!dateStr) return '';
        const diffJours = Math.floor((new Date() - new Date(dateStr)) / 86400000);
        if (diffJours <= 0) return "aujourd'hui";
        if (diffJours === 1) return 'hier';
        if (diffJours < 30) return `il y a ${diffJours} j`;
        const diffMois = Math.floor(diffJours / 30);
        if (diffMois < 12) return `il y a ${diffMois} mois`;
        const diffAns = Math.floor(diffMois / 12);
        return `il y a ${diffAns} an${diffAns > 1 ? 's' : ''}`;
    }

    // Fonction pour charger et afficher les tâches depuis l'API
    function loadTaches(filterDate = null, filterStatut = null) {
        const userRole = localStorage.getItem('user_role');
        const userName = localStorage.getItem('user_name');
        let apiUrl = '../backend/api/taches.php?action=read';

        // Si l'utilisateur n'est pas admin et qu'aucun filtre n'est appliqué, afficher ses tâches
        //if (userRole !== 'Administrateur' && !filterDate && !filterStatut) {

        //console.log("Role de l'utilisateur: " + userRole);

        // Si l'utilisateur n'est pas admin, afficher ses tâches
        /*if (userRole !== 'Administrateur' && !filterDate && !filterStatut) {
            apiUrl += `&assigne_a=${userName}`;
        }*/
        //console.log("URL de l'API de la liste des taches: " + apiUrl);

        $.ajax({
            url: apiUrl,
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                const tableBody = $('#taches-table tbody');
                tableBody.empty();

                let taches = data;

                // Filtrage des tâches si un filtre est appliqué
                if (filterDate) {
                    const today = new Date().toISOString().slice(0, 10);
                    if (filterDate === 'crees_aujourdhui') {
                        taches = taches.filter(t => t.date_creation.slice(0, 10) === today);
                    } else if (filterDate === 'terminees_aujourdhui') {
                        taches = taches.filter(t => t.statut === 'Terminé' && t.date_modification.slice(0, 10) === today);
                    } else if (filterDate === 'en_retard') {
                        taches = taches.filter(t => t.date_limite && t.date_limite.slice(0, 10) < today
                            && t.statut !== 'Terminé' && t.statut !== 'Annulé');
                    } else if (filterDate === 'echeance_proche') {
                        const dansNJours = new Date();
                        dansNJours.setDate(dansNJours.getDate() + appParams.seuil_echeance_proche_jours);
                        const dansNJoursStr = dansNJours.toISOString().slice(0, 10);
                        taches = taches.filter(t => t.date_limite && t.date_limite.slice(0, 10) >= today
                            && t.date_limite.slice(0, 10) <= dansNJoursStr
                            && t.statut !== 'Terminé' && t.statut !== 'Annulé');
                    } else if (filterDate === 'non_prises_en_charge') {
                        // Tâches "À faire" jamais prises en charge depuis le seuil configuré
                        // (Paramètres > Seuils métier ; même seuil que l'action backend 'escalations').
                        taches = taches.filter(t => {
                            if (t.statut !== 'À faire' || !t.date_creation) return false;
                            const joursAttente = Math.floor((new Date() - new Date(t.date_creation)) / 86400000);
                            return joursAttente >= appParams.seuil_non_prise_en_charge_jours;
                        });
                    }
                }

                

                // Filtrage des tâches par statut en provenance des badges
                if (filterStatut) {
                    if (filterStatut === 'À faire') {
                        taches = taches.filter(t => t.statut === 'À faire');
                    } else if (filterStatut === 'En cours') {
                        taches = taches.filter(t => t.statut === 'En cours');
                    } else if (filterStatut === 'Terminé') {
                        taches = taches.filter(t => t.statut === 'Terminé');
                    } else if (filterStatut === 'Annulé') {
                        taches = taches.filter(t => t.statut === 'Annulé');
                    } 
                }

                /*// Filtrage des tâches par priorité en provenance des badges
                if (filterPriorite) {
                    if (filterPriorite === 'Haute') {
                        taches = taches.filter(t => t.priorite === 'Haute');
                    } else if (filterPriorite === 'Moyenne') {
                        taches = taches.filter(t => t.priorite === 'Moyenne');
                    } else if (filterPriorite === 'Basse') {
                        taches = taches.filter(t => t.priorite === 'Basse');
                    } 
                }*/

                //console.log("Taches filtrées: " + JSON.stringify(taches));

                taches.forEach(item => {
                    // Création des badges de priorité
                    let priorityBadge = '';
                    if (item.priorite === 'Haute') {
                        priorityBadge = '<span class="priority-badge priority-high">Haute</span>';
                    } else if (item.priorite === 'Moyenne') {
                        priorityBadge = '<span class="priority-badge priority-medium">Moyenne</span>';
                    } else {
                        priorityBadge = '<span class="priority-badge priority-low">Basse</span>';
                    }

                    // Création des badges de statut
                    let statusBadge = '';
                    if (item.statut === 'Terminé') {
                        statusBadge = '<span class="status-badge status-success">Terminé</span>';
                    } else if (item.statut === 'En cours') {
                        statusBadge = '<span class="status-badge status-warning">En cours</span>';
                    } else if (item.statut === 'Annulé') {
                        statusBadge = '<span class="status-badge status-danger">Annulé</span>';
                    } else {
                        statusBadge = '<span class="status-badge status-info">À faire</span>';
                    }

                    // Formatage de la date limite
                    const dateLimite = item.date_limite ? new Date(item.date_limite).toLocaleDateString('fr-FR') : '-';

                    // Badge de retard / échéance proche (tâches non terminées/annulées uniquement)
                    let echeanceBadge = '';
                    if (item.date_limite && item.statut !== 'Terminé' && item.statut !== 'Annulé') {
                        const todayStr = new Date().toISOString().slice(0, 10);
                        const dansNJours = new Date();
                        dansNJours.setDate(dansNJours.getDate() + appParams.seuil_echeance_proche_jours);
                        const dansNJoursStr = dansNJours.toISOString().slice(0, 10);
                        const dateLimiteStr = item.date_limite.slice(0, 10);

                        if (dateLimiteStr < todayStr) {
                            echeanceBadge = ' <span class="status-badge status-danger" title="Date limite dépassée">En retard</span>';
                        } else if (dateLimiteStr <= dansNJoursStr) {
                            echeanceBadge = ` <span class="status-badge status-warning" title="Échéance dans moins de ${appParams.seuil_echeance_proche_jours} jours">Bientôt</span>`;
                        }
                    }

                    // Badge "tâche dormante" : À faire/En cours sans mise à jour depuis le seuil
                    // configuré (Paramètres > Seuils métier), pour rendre visibles les tâches
                    // qui traînent même sans date limite.
                    let dormantBadge = '';
                    let joursInactivite = 0;
                    const estActive = (item.statut === 'À faire' || item.statut === 'En cours');
                    if (estActive && item.date_modification) {
                        joursInactivite = Math.floor((new Date() - new Date(item.date_modification)) / 86400000);
                        if (joursInactivite >= appParams.seuil_tache_dormante_jours) {
                            dormantBadge = ` <span class="status-badge status-warning" title="Aucune mise à jour depuis ${joursInactivite} jours">Inactive ${joursInactivite}j</span>`;
                        }
                    }

                    // Dates brutes (YYYY-MM-DD) stockées en attributs data-* pour un filtrage fiable
                    const dateAjoutRaw = item.date_creation ? item.date_creation.slice(0, 10) : '';
                    const dateLimiteRaw = item.date_limite ? item.date_limite.slice(0, 10) : '';
                    const dateModifRaw = item.date_modification ? item.date_modification.slice(0, 10) : '';

                    // Bouton "Prendre en charge" : visible sur les tâches encore "À faire".
                    const peutPrendreEnCharge = item.statut === 'À faire';
                    // Bouton "Terminer" : visible pour l'admin ou l'utilisateur assigné, tant que
                    // la tâche n'est pas déjà terminée/annulée — évite de rouvrir le formulaire
                    // complet juste pour clore une tâche.
                    const peutTerminer = item.statut !== 'Terminé' && item.statut !== 'Annulé'
                        && (userRole === 'Administrateur' || (item.assigne_a && item.assigne_a === userName));

                    const row = `
                        <tr data-date-ajout="${dateAjoutRaw}" data-date-limite="${dateLimiteRaw}" data-date-modification="${dateModifRaw}" data-statut-raw="${item.statut}">
                            <td>${statusBadge}${dormantBadge}</td>
                            <td>${item.titre}<small class="tache-age" title="Créée le ${item.date_creation ? new Date(item.date_creation).toLocaleDateString('fr-FR') : '?'}">${formatAge(item.date_creation)}</small></td>
                            <td>${item.motif_cause || '-'}</td>
                            <td>${item.service_concerne || '-'}</td>
                            <td>${item.materiel_concerne || '-'}</td>
                            <td>${item.assigne_a || '-'}</td>
                            <td>${dateLimite}${echeanceBadge}</td>
                            <td>${priorityBadge}</td>
                            <td class="actions">
                                <button class="btn-action btn-quick-take" data-id="${item.id}" title="Prendre en charge" style="display: ${peutPrendreEnCharge ? '' : 'none'};"><i class="fas fa-hand-paper"></i></button>
                                <button class="btn-action btn-quick-done" data-id="${item.id}" title="Marquer comme terminée" style="display: ${peutTerminer ? '' : 'none'};"><i class="fas fa-check"></i></button>
                                <button class="btn-action btn-edit" data-id="${item.id}"><i class="fas fa-pencil-alt"></i></button>
                                <button class="btn-action btn-delete" data-id="${item.id}" style="display: ${userRole === 'Administrateur' ? '' : 'none'};"><i class="fas fa-trash-alt"></i></button>
                            </td>
                        </tr>
                    `;
                    // .data('tache', item) plutôt qu'un attribut HTML : évite tout souci
                    // d'échappement et permet aux actions rapides de réutiliser l'objet complet
                    // sans le rappeler depuis l'API.
                    tableBody.append($(row).data('tache', item));
                });

                // Alimente les filtres "Assigné à", "Matériel concerné" et "Service" avec
                // les valeurs distinctes réellement présentes dans les tâches chargées.
                populerFiltreDepuisValeurs('#filter-taches-assigne', taches.map(t => t.assigne_a));
                populerFiltreDepuisValeurs('#filter-taches-materiel', taches.map(t => t.materiel_concerne));
                populerFiltreDepuisValeurs('#filter-taches-service', taches.map(t => t.service_concerne));

                //updateTotalCount('#taches-table', '#taches-total');
            },
            error: function (xhr, status, error) {
                console.error("Erreur lors du chargement des tâches:", error);
                $('#taches-table tbody').html('<tr><td colspan="7" class="table-error"><i class="fas fa-triangle-exclamation"></i>Erreur de chargement des données.</td></tr>');
            }
        });
    }

    // Remplit un <select> de filtre avec les valeurs distinctes (non vides) d'une liste,
    // en conservant la sélection courante si elle existe toujours parmi les options.
    function populerFiltreDepuisValeurs(selector, valeurs) {
        const select = $(selector);
        if (select.length === 0) return;

        const valeurActuelle = select.val();
        const distinctes = [...new Set(valeurs.filter(v => v))].sort((a, b) => a.localeCompare(b));

        select.find('option:not(:first)').remove();
        distinctes.forEach(function (v) {
            select.append(`<option value="${v}">${v}</option>`);
        });

        if (valeurActuelle && distinctes.includes(valeurActuelle)) {
            select.val(valeurActuelle);
        }
    }

    // Fonction pour mettre à jour les statistiques du tableau de bord
    function updateDashboardStats() {
        $.ajax({
            url: '../backend/api/dashboard.php?action=stats' + periodeQueryString(),
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                // Mettre à jour les cartes de statistiques
                $('.card-value').eq(0).text(data.materiel.total);
                $('.card-value').eq(1).text(data.materiel.en_panne);
                $('.card-value').eq(2).text(data.taches.en_cours);
                $('#taches-crees-aujourdhui').text(data.taches.crees_aujourdhui);
                $('#taches-terminees-aujourdhui').text(data.taches.terminees_aujourdhui);
                $('#taches-total').text(data.taches.total);
                $('#taches-en-retard').text(data.taches.en_retard);
                $('#taches-echeance-proche').text(data.taches.echeance_proche);
                $('#taches-non-prises-en-charge').text(data.taches.non_prises_en_charge);
                $('#materiel-pannes-sans-tache').text(data.materiel.pannes_sans_tache);

               // $('.card-value').eq(5).text(data.services.total); // Ajustez l'index si nécessaire
            },
            error: function() {
                console.error('Erreur lors du chargement des statistiques du tableau de bord');
            }
        });
    }

    // Fonction pour charger les utilisateurs dans les selects
    function loadUsersInSelects() {
        $.ajax({
            url: '../backend/api/utilisateurs.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function(users) {
                // Mettre à jour le select des tâches
                const tacheSelect = $('#tache-assigne');
                tacheSelect.find('option:not(:first)').remove();
                users.forEach(function(user) {
                    tacheSelect.append(`<option value="${user.nom}">${user.nom} (${user.role})</option>`);
                });

                // Mettre à jour le select du matériel
                const materielSelect = $('#utilisateur');
                materielSelect.find('option:not(:first)').remove();
                users.forEach(function(user) {
                    materielSelect.append(`<option value="${user.nom}">${user.nom} (${user.role})</option>`);
                });
            },
            error: function() {
                console.error('Erreur lors du chargement des utilisateurs pour les selects');
            }
        });
    }

    // Fonction pour charger les types de matériel dans les selects concernés (table
    // t_type_materiel) : celui du formulaire Matériel (#type) et celui du mini-formulaire
    // "Ajouter un matériel" intégré à la fiche Tâche (#nm-type).
    function loadTypeMaterielInSelects() {
        $.ajax({
            url: '../backend/api/type_materiel.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function(types) {
                ['#type', '#nm-type'].forEach(function(selector) {
                    const typeSelect = $(selector);
                    if (typeSelect.length === 0) return;

                    const currentValue = typeSelect.val();
                    // On garde le placeholder et l'option "+ Ajouter un type", on remplace le reste
                    typeSelect.find('option').not('[value=""]').not('[value="__new__"]').remove();
                    const newOption = typeSelect.find('option[value="__new__"]');
                    types.forEach(function(t) {
                        $(`<option value="${t.type}">${t.type}</option>`).insertBefore(newOption);
                    });

                    if (currentValue && currentValue !== '__new__') {
                        typeSelect.val(currentValue);
                    }
                });
            },
            error: function() {
                console.error('Erreur lors du chargement des types de matériel');
            }
        });
    }

    // Fonction pour charger les services dans les selects
    function loadServicesInSelects() {
        $.ajax({
            url: '../backend/api/services.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function(services) {
                // Mettre à jour le select du matériel
                const serviceSelect = $('#service');
                serviceSelect.find('option:not(:first)').remove();
                services.forEach(function(service) {
                    serviceSelect.append(`<option value="${service.nom}">${service.nom}</option>`);
                });

                // Mettre à jour le select des tâches
                const tacheServiceSelect = $('#tache-service');
                const materielServiceSelect = $('#filter-materiel-service');
                if (tacheServiceSelect.length > 0) {
                    // Store current selected value before updating
                    const currentValue = tacheServiceSelect.val();
                    console.log("Combo Service Tache currentValue:", currentValue);
                    // Update options
                    tacheServiceSelect.find('option:not(:first)').remove();
                    services.forEach(function(service) {
                        tacheServiceSelect.append(`<option value="${service.nom}">${service.nom}</option>`);

                    });
                    
                    // Restore previously selected value if it still exists
                    if (currentValue) {
                        tacheServiceSelect.val(currentValue);
                    }
                }
                if (materielServiceSelect.length > 0) {
                    // Store current selected value before updating
                    const currentValue = materielServiceSelect.val();
                    //console.log("Combo Service Materiel currentValue:", currentValue);
                    // Update options
                    materielServiceSelect.find('option:not(:first)').remove();
                    services.forEach(function(service) {
                        materielServiceSelect.append(`<option value="${service.nom}">${service.nom}</option>`);
                    });
                }

                // Filtre par service du sélecteur de matériel (fiche Tâche) et service affecté
                // du mini-formulaire "Ajouter un matériel" intégré à cette même fiche.
                [$('#tache-materiel-filtre-service'), $('#nm-service')].forEach(function($sel) {
                    if ($sel.length === 0) return;
                    const currentValue = $sel.val();
                    $sel.find('option:not(:first)').remove();
                    services.forEach(function(service) {
                        $sel.append(`<option value="${service.nom}">${service.nom}</option>`);
                    });
                    if (currentValue) {
                        $sel.val(currentValue);
                    }
                });
            },
            error: function() {
                console.error('Erreur lors du chargement des services pour les selects');
            }
        });
    }

    // Cache du matériel chargé pour le sélecteur de la fiche Tâche : évite un aller-retour
    // réseau à chaque changement de filtre/recherche (voir renderTacheMaterielOptions).
    let materielCache = [];

    // Fonction pour charger le matériel dans le select de la fiche Tâche
    function loadMaterielInSelects() {
        $.ajax({
            url: '../backend/api/materiel.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function(materiel) {
                materielCache = materiel;
                renderTacheMaterielOptions();
            },
            error: function() {
                console.error('Erreur lors du chargement du matériel pour les selects');
            }
        });
    }

    // Reconstruit les <option> de #tache-materiel à partir du cache, filtrées par le
    // service sélectionné (#tache-materiel-filtre-service) et le texte de recherche
    // (#tache-materiel-recherche, sur le nom affiché ET le numéro de série).
    function renderTacheMaterielOptions() {
        const $select = $('#tache-materiel');
        if ($select.length === 0) return;

        const currentValue = $select.val();
        const serviceFilter = $('#tache-materiel-filtre-service').val();
        const recherche = ($('#tache-materiel-recherche').val() || '').trim().toLowerCase();

        $select.empty();
        $select.append('<option value="">Aucun matériel spécifique</option>');

        materielCache.forEach(function(item) {
            if (serviceFilter && item.service_affecte !== serviceFilter) return;

            const displayText = `${item.type} ${item.marque} ${item.modele} (${item.num_serie})`;
            if (recherche) {
                const matchNom = displayText.toLowerCase().includes(recherche);
                const matchSerie = (item.num_serie || '').toLowerCase().includes(recherche);
                if (!matchNom && !matchSerie) return;
            }

            $select.append(`<option value="${displayText}">${displayText}</option>`);
        });

        $select.append('<option value="__new__">+ Ajouter un nouveau matériel...</option>');

        // Restaure la sélection précédente si elle existe toujours dans la liste filtrée
        const existeEncore = $select.find('option').filter(function() {
            return $(this).val() === currentValue;
        }).length > 0;
        if (currentValue && currentValue !== '__new__' && existeEncore) {
            $select.val(currentValue);
        }
    }

    // Fonction pour charger les tâches récentes
    function loadRecentActivities() {
        $.ajax({
            url: '../backend/api/dashboard.php?action=activites' + periodeQueryString(),
            type: 'GET',
            dataType: 'json',
            success: function(activites) {
                const tableBody = $('.recent-activity tbody');
                tableBody.empty();

                if (activites.length === 0) {
                    tableBody.append('<tr><td colspan="4" style="text-align:center;">Aucune tâche récente.</td></tr>');
                    return;
                }

                activites.forEach(function(activite) {
                    // Formatage de la date
                    const date = new Date(activite.date).toLocaleDateString('fr-FR');
                    
                    // Création du badge de statut
                    const statusBadge = `<span class="status-badge status-${activite.status_class}">${activite.statut}</span>`;
                    
                    // Ajouter le service si disponible
                    const serviceInfo = activite.service_concerne ? ` - ${activite.service_concerne}` : '';
                    
                    const row = `
                        <tr>
                            <td>${date}</td>
                            <td>${activite.type}</td>
                            <td>${activite.description}${serviceInfo}</td>
                            <td>${statusBadge}</td>
                        </tr>
                    `;
                    tableBody.append(row);
                });
                //updateTotalCount('.recent-activity', '.recent-activity-total');
            },
            error: function() {
                console.error('Erreur lors du chargement des tâches récentes');
                $('.recent-activity tbody').html('<tr><td colspan="4" class="table-error"><i class="fas fa-triangle-exclamation"></i>Erreur de chargement des tâches.</td></tr>');
            }
        });
    }

    // Fonction pour charger les pannes récurrentes
    function loadPannesRecurrentes() {
        $.ajax({
            url: '../backend/api/dashboard.php?action=pannes_recurrentes' + periodeQueryString(),
            type: 'GET',
            dataType: 'json',
            success: function(pannes) {
                const container = $('#pannes-recurrentes-list');
                container.empty();

                if (pannes.length === 0) {
                    container.html('<div class="no-data">Aucune tâche liée à du matériel pour le moment</div>');
                    return;
                }

                pannes.forEach(function(panne) {
                    const panneItem = `
                        <div class="panne-item">
                            <div class="panne-info">
                                <div class="panne-title">${panne.type} ${panne.marque} ${panne.modele}</div>
                                <div class="panne-details">${panne.types_pannes}</div>
                            </div>
                            <div class="panne-count">${panne.nb_pannes}</div>
                        </div>
                    `;
                    container.append(panneItem);
                });
                //updateTotalCount('#pannes-recurrentes-list', '#pannes-recurrentes-total');
            },
            error: function() {
                console.error('Erreur lors du chargement des pannes récurrentes');
                $('#pannes-recurrentes-list').html('<div class="no-data">Erreur de chargement</div>');
            }
        });
    }

    // Fonction pour charger la vue "cycle de vie du parc" (âge moyen, répartition par
    // type, matériel à renouveler)
    function loadParcLifecycle() {
        $.ajax({
            url: '../backend/api/dashboard.php?action=parc_lifecycle',
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                // Âge moyen
                const ageContainer = $('#parc-age-moyen');
                if (data.age_moyen_annees !== null && data.age_moyen_annees !== undefined) {
                    ageContainer.removeClass('no-data').html(`<strong>Âge moyen du parc : ${data.age_moyen_annees} an(s)</strong>`);
                } else {
                    ageContainer.addClass('no-data').text('Âge moyen indisponible (aucune date d\'achat renseignée)');
                }

                // Répartition par type
                const repartitionContainer = $('#parc-repartition-type-list');
                repartitionContainer.empty();
                if (!data.repartition_par_type || data.repartition_par_type.length === 0) {
                    repartitionContainer.html('<div class="no-data">Aucun matériel enregistré</div>');
                } else {
                    data.repartition_par_type.forEach(function (item) {
                        repartitionContainer.append(`
                            <div class="panne-item">
                                <div class="panne-info">
                                    <div class="panne-title">${item.type}</div>
                                </div>
                                <div class="panne-count">${item.total}</div>
                            </div>
                        `);
                    });
                }

                // Matériel à renouveler
                const renouvelerContainer = $('#parc-a-renouveler-list');
                renouvelerContainer.empty();
                if (!data.materiel_a_renouveler || data.materiel_a_renouveler.length === 0) {
                    renouvelerContainer.html(`<div class="no-data">Aucun matériel de plus de ${data.seuil_renouvellement_annees} ans</div>`);
                } else {
                    data.materiel_a_renouveler.forEach(function (item) {
                        const dateAchat = item.date_achat ? new Date(item.date_achat).toLocaleDateString('fr-FR') : '-';
                        renouvelerContainer.append(`
                            <div class="panne-item">
                                <div class="panne-info">
                                    <div class="panne-title">${item.type} ${item.marque} ${item.modele}</div>
                                    <div class="panne-details">${item.num_serie} — acheté le ${dateAchat}</div>
                                </div>
                                <div class="panne-count">${item.age_annees} ans</div>
                            </div>
                        `);
                    });
                }
            },
            error: function () {
                console.error('Erreur lors du chargement du cycle de vie du parc');
                $('#parc-repartition-type-list, #parc-a-renouveler-list').html('<div class="no-data">Erreur de chargement</div>');
            }
        });
    }

    // Fonction pour charger les services demandeurs
    function loadServicesDemandeurs() {
        $.ajax({
            url: '../backend/api/dashboard.php?action=services_demandeurs' + periodeQueryString(),
            type: 'GET',
            dataType: 'json',
            success: function(services) {
                const container = $('#services-demandeurs-list');
                container.empty();

                if (services.length === 0) {
                    container.html('<div class="no-data">Aucun service demandeur</div>');
                    return;
                }

                services.forEach(function(service) {
                    const serviceItem = `
                        <div class="service-item">
                            <div class="service-info">
                                <div class="service-name">${service.service}</div>
                                <div class="service-stats">${service.taches_terminees}/${service.nb_taches} terminées</div>
                            </div>
                            <div class="service-count">${service.nb_taches}</div>
                        </div>
                    `;
                    container.append(serviceItem);
                });
                //updateTotalCount('#services-demandeurs-list', '#services-demandeurs-total');
            },
            error: function() {
                console.error('Erreur lors du chargement des services demandeurs');
                $('#services-demandeurs-list').html('<div class="no-data">Erreur de chargement</div>');
            }
        });
    }

    // Fonction pour charger le classement des agents ayant terminé le plus de tâches
    // (reconnaissance positive, plutôt qu'un classement des retards)
    function loadTopResolveurs() {
        $.ajax({
            url: '../backend/api/dashboard.php?action=top_resolveurs' + periodeQueryString(),
            type: 'GET',
            dataType: 'json',
            success: function(resolveurs) {
                const container = $('#top-resolveurs-list');
                container.empty();

                if (resolveurs.length === 0) {
                    container.html('<div class="no-data">Aucune tâche terminée sur la période sélectionnée</div>');
                    return;
                }

                const medailles = ['🥇', '🥈', '🥉'];
                resolveurs.forEach(function(r, index) {
                    const medaille = medailles[index] || `${index + 1}.`;
                    const item = `
                        <div class="service-item">
                            <div class="service-info">
                                <div class="service-name">${medaille} ${r.assigne_a}</div>
                                <div class="service-stats">Tâches terminées sur la période sélectionnée</div>
                            </div>
                            <div class="service-count">${r.nb_terminees}</div>
                        </div>
                    `;
                    container.append(item);
                });
            },
            error: function() {
                console.error('Erreur lors du chargement du top résolveurs');
                $('#top-resolveurs-list').html('<div class="no-data">Erreur de chargement</div>');
            }
        });
    }

    // Variable globale pour le graphique
    let pannesChart = null;

    // Fonction pour charger et afficher le graphique des pannes par mois
    function loadPannesParMois() {
        $.ajax({
            url: '../backend/api/dashboard.php?action=pannes_par_mois' + periodeQueryString(),
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                const ctx = document.getElementById('pannes-chart').getContext('2d');
                
                // Détruire le graphique existant s'il existe
                if (pannesChart) {
                    pannesChart.destroy();
                }

                if (data.length === 0) {
                    $('#pannes-chart-container').html('<div class="no-data">Aucune tâche déclarée sur la période sélectionnée</div>');
                    return;
                }

                // Préparer les données pour Chart.js
                const labels = data.map(item => `${item.nom_mois} ${item.annee}`);
                const values = data.map(item => item.nb_pannes);

                pannesChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'Nombre de tâches déclarées',
                            data: values,
                            backgroundColor: 'rgba(52, 152, 219, 0.8)',
                            borderColor: 'rgba(52, 152, 219, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            title: {
                                display: false
                            }
                        }
                    }
                });
            },
            error: function() {
                console.error('Erreur lors du chargement des données de pannes par mois');
                $('#pannes-chart-container').html('<div class="no-data">Erreur de chargement du graphique</div>');
            }
        });
    }

    // --- Filtre période du tableau de bord ---
    // Recharge tout ce qui dépend de la période sélectionnée (voir la liste des fonctions
    // dans le commentaire au-dessus de `dashboardPeriode`) — pas l'état du parc matériel
    // (loadParcLifecycle) ni "Mes tâches du jour" (loadTaskNotifications), volontairement
    // non bornés par période.
    function refreshDashboardPeriode() {
        updateDashboardStats();
        loadRecentActivities();
        loadPannesRecurrentes();
        loadServicesDemandeurs();
        loadPannesParMois();
        loadTopResolveurs();
    }

    $('#dashboard-periode-debut').val(dashboardPeriode.debut);
    $('#dashboard-periode-fin').val(dashboardPeriode.fin);

    $('#btn-dashboard-periode-appliquer').on('click', function () {
        const debut = $('#dashboard-periode-debut').val();
        const fin = $('#dashboard-periode-fin').val();
        if (!debut || !fin) {
            alert('Indiquez une date de début et une date de fin.');
            return;
        }
        if (debut > fin) {
            alert('La date de début doit précéder la date de fin.');
            return;
        }
        dashboardPeriode = { debut: debut, fin: fin };
        refreshDashboardPeriode();
    });

    $('#btn-dashboard-periode-annee').on('click', function () {
        dashboardPeriode = { debut: premierJourAnnee(), fin: dernierJourAnnee() };
        $('#dashboard-periode-debut').val(dashboardPeriode.debut);
        $('#dashboard-periode-fin').val(dashboardPeriode.fin);
        refreshDashboardPeriode();
    });

    // --- Gestion des clics sur les cartes du tableau de bord ---
    $('.dashboard-cards').on('click', '.card', function() {
        const page = $(this).data('page');
        const filter = $(this).data('filter');

        if (page) {
            // Simuler un clic sur le menu pour changer de page
            $(`.sidebar-menu a[href="#${page}"]`).click();

            // Appliquer le filtre après un court délai pour laisser le temps à la page de se charger
            setTimeout(() => {
                if (page === 'taches') {
                    // Les filtres appliqués ci-dessous ne concernent que la vue liste :
                    // on y revient systématiquement (le Kanban n'a pas de notion de filtre).
                    $('#btn-vue-liste').trigger('click');

                    // Les filtres "date" (crees_aujourdhui, terminees_aujourdhui, en_retard,
                    // echeance_proche) ne correspondent à aucun select visible : on les
                    // applique directement. Les autres (ex: "En cours") sont des valeurs de
                    // statut : on passe par le select #filter-statut existant pour que l'UI
                    // de filtre reste synchronisée avec ce qui est affiché.
                    const filtresDate = ['crees_aujourdhui', 'terminees_aujourdhui', 'en_retard', 'echeance_proche', 'non_prises_en_charge'];
                    $('#filter-priorite').val('');
                    if (filter && filtresDate.includes(filter)) {
                        $('#filter-statut').val('');
                        loadTaches(filter);
                    } else if (filter) {
                        $('#filter-statut').val(filter).change();
                    } else {
                        $('#filter-statut').val('');
                        loadTaches();
                    }
                } else if (page === 'materiel' && filter) {
                    // Pour le matériel, on peut simuler une recherche
                    $('#materiel-search').val(filter).keyup();
                }
                // Pour les cartes sans filtre (total), ne rien faire de plus
            }, 100);
        }
    });

    // --- Gestion de la Fenêtre Modale (Finale) ---
    const modal = $('#form-modal');
    const modalContent = modal.find('.modal-content');
    const modalBody = modal.find('.modal-body');
    const modalTitle = modal.find('#modal-title');
    const modalFooter = modal.find('.modal-footer');
    // Mémorise le footer d'origine (Annuler/Enregistrer) pour pouvoir le restaurer
    // après une vue en lecture seule (historique, étiquette QR) qui le remplace.
    const modalFooterDefaultHtml = modalFooter.html();

    // Ajuste la taille de la modale (sm = compact, md = défaut, lg = formulaire
    // complexe / contenu volumineux) selon ce qu'elle affiche.
    function setModalSize(taille) {
        modalContent.removeClass('modal-content--sm modal-content--lg');
        if (taille === 'sm' || taille === 'lg') {
            modalContent.addClass('modal-content--' + taille);
        }
    }

    // Restaure le footer standard (Annuler/Enregistrer) de la modale de formulaire.
    function resetModalFooter() {
        modalFooter.html(modalFooterDefaultHtml).show();
    }

    // Définir le HTML pour les formulaires
    const materielFormHtml = `
        <form id="materiel-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="type">Type</label>
                    <select id="type" name="type">
                        <option value="">Sélectionner un type</option>
                        <!-- Types chargés dynamiquement depuis t_type_materiel -->
                        <option value="__new__">+ Ajouter un nouveau type...</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="statut">Statut</label>
                    <select id="statut" name="statut">
                        <option value="En service">En service</option>
                        <option value="En panne">En panne</option>
                        <option value="En attente">En attente</option>
                        <option value="Retiré">Retiré</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="marque">Marque</label>
                <input type="text" id="marque" name="marque" placeholder="Ex: Dell">
            </div>
            <div class="form-group">
                <label for="modele">Modèle</label>
                <input type="text" id="modele" name="modele" placeholder="Ex: Latitude 7420">
            </div>
            <div class="form-group">
                <label for="num_serie">Numéro de série</label>
                <input type="text" id="num_serie" name="num_serie" placeholder="Code unique de l'équipement">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="date_achat">Date d'achat</label>
                    <input type="date" id="date_achat" name="date_achat">
                </div>
                <div class="form-group">
                    <label for="service">Service affecté</label>
                    <select id="service" name="service">
                        <option value="">Sélectionner un service</option>
                        <!-- Les services seront chargés dynamiquement -->
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="utilisateur">Utilisateur assigné</label>
                <input
                    type="text"
                    id="utilisateur"
                    name="utilisateur"
                    placeholder="Nom de l'utilisateur qui utilise l'équipement"
                >
            </div>
            <div class="form-group" id="date-derniere-affect-group">
                <label for="date_derniere_affect">Date de dernière affectation</label>
                <input type="date" id="date_derniere_affect" name="date_derniere_affect">
                <small style="color: var(--color-text-muted); display:block; margin-top:4px;">
                    Requise dès qu'un service affecté est sélectionné.
                </small>
            </div>
        </form>`;
    const servicesFormHtml = `<form id="service-form"><div class="form-group"><label for="service-nom">Nom du Service</label><input type="text" id="service-nom" name="nom" placeholder="Ex: Ressources Humaines"></div><div class="form-group"><label for="service-desc">Description</label><textarea id="service-desc" name="description" rows="4" placeholder="Courte description..."></textarea></div></form>`;
    const tachesFormHtml = `
        <form id="tache-form">
            <div class="form-group">
                <label for="tache-titre">Titre (Problème/Demande)</label>
                <input type="text" id="tache-titre" name="tache-titre" placeholder="Ex: Remplacer un disque dur">
            </div>
            <div class="form-group">
                <label for="tache-motif_cause">Motif/Cause</label>
                <textarea id="tache-motif_cause" name="tache-motif_cause" rows="3" placeholder="Motif/Cause de la tâche..."></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="tache-priorite">Priorité</label>
                    <select id="tache-priorite" name="tache-priorite">
                        <option value="Basse" selected>Basse</option>
                        <option value="Moyenne">Moyenne</option>
                        <option value="Haute">Haute</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tache-statut">Statut</label>
                    <select id="tache-statut" name="tache-statut">
                        <option value="À faire" selected>À faire</option>
                        <option value="En cours">En cours</option>
                        <option value="Terminé">Terminé</option>
                        <option value="Annulé">Annulé</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="tache-assigne">Assigné à</label>
                    <select id="tache-assigne" name="tache-assigne">
                        <option value="">Sélectionner un technicien</option>
                        <!-- Les utilisateurs seront chargés dynamiquement -->
                    </select>
                </div>
                <div class="form-group">
                    <label for="tache-date-limite">Date limite</label>
                    <input type="date" id="tache-date-limite" name="tache-date-limite">
                </div>
            </div>
            <div class="form-group">
                <label for="tache-service">Service concerné</label>
                <select id="tache-service" name="tache-service">
                    <option value="">Sélectionner un service</option>
                    <!-- Les services seront chargés dynamiquement -->
                </select>
            </div>
            <div class="form-group" id="tache-materiel-groupe">
                <label for="tache-materiel">Matériel/Application concerné</label>
                <div class="materiel-picker-filters">
                    <select id="tache-materiel-filtre-service" title="Filtrer la liste par service">
                        <option value="">Tous les services</option>
                        <!-- Les services seront chargés dynamiquement -->
                    </select>
                    <input type="text" id="tache-materiel-recherche" placeholder="Rechercher par nom ou n° de série...">
                </div>
                <select id="tache-materiel" name="tache-materiel">
                    <option value="">Aucun matériel spécifique</option>
                    <option value="__new__">+ Ajouter un nouveau matériel...</option>
                    <!-- Le matériel sera chargé dynamiquement -->
                </select>
                <div class="inline-subform" id="tache-materiel-nouveau" style="display:none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nm-type">Type</label>
                            <select id="nm-type">
                                <option value="">Sélectionner un type</option>
                                <option value="__new__">+ Ajouter un nouveau type...</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="nm-statut">Statut</label>
                            <select id="nm-statut">
                                <option value="En service">En service</option>
                                <option value="En panne">En panne</option>
                                <option value="En attente">En attente</option>
                                <option value="Retiré">Retiré</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nm-marque">Marque</label>
                            <input type="text" id="nm-marque" placeholder="Ex: Dell">
                        </div>
                        <div class="form-group">
                            <label for="nm-modele">Modèle</label>
                            <input type="text" id="nm-modele" placeholder="Ex: Latitude 7420">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="nm-num-serie">Numéro de série</label>
                            <input type="text" id="nm-num-serie" placeholder="Code unique de l'équipement">
                        </div>
                        <div class="form-group">
                            <label for="nm-service">Service affecté</label>
                            <select id="nm-service">
                                <option value="">Sélectionner un service</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" id="nm-date-affect-group" style="display:none;">
                        <label for="nm-date-affect">Date de dernière affectation</label>
                        <input type="date" id="nm-date-affect">
                    </div>
                    <div class="inline-subform-actions">
                        <button type="button" id="btn-nm-annuler" class="btn btn-secondary">Annuler</button>
                        <button type="button" id="btn-nm-creer" class="btn btn-primary">Créer et sélectionner</button>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="tache-desc">Description (Diagnostic technique)</label>
                <textarea id="tache-desc" name="tache-desc" rows="3" placeholder="Faire la description/diagnostic technique de la tâche..."></textarea>
            </div>
            <div class="form-group">
                <label for="tache-actions">Actions</label>
                <textarea id="tache-actions" name="tache-actions" rows="3" placeholder="Description des actions effectuées pour résoudre le problème..."></textarea>
            </div>
        </form>`;
    const utilisateursFormHtml = `
        <form id="utilisateur-form">
            <div class="form-group">
                <label for="nom">Nom complet</label>
                <input type="text" id="nom" name="nom" placeholder="Ex: Jean Dupont">
            </div>
            <div class="form-group">
                <label for="login">Login</label>
                <input type="text" id="login" name="login" placeholder="Ex: jean.dupont">
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" placeholder="Ex: jean.dupont@parcinfo.local">
                <div class="field-hint">Utilisée pour les notifications par email (nouvelle tâche, résumé, escalade).</div>
            </div>
            <div class="form-group">
                <label for="mot_de_passe">Mot de passe</label>
                <input type="password" id="mot_de_passe" name="mot_de_passe" placeholder="Entrez votre mot de passe">
            </div>
            <div class="form-group" id="role-form-group">
                <label for="role">Rôle</label>
                <select id="role" name="role">
                    <option>Technicien</option>
                    <option>Administrateur</option>
                </select>
            </div>
        </form>`;

    function openModal(context, mode = 'add') {
        let title = '';
        let formHtml = '';

        // Taille de la modale adaptée à la complexité du formulaire :
        // sm = peu de champs, (par défaut) = standard, lg = formulaire long.
        let taille = null;

        if (context === 'materiel') {
            title = (mode === 'add') ? 'Ajouter un équipement' : 'Modifier l\'équipement';
            formHtml = materielFormHtml;
        } else if (context === 'services') {
            title = (mode === 'add') ? 'Ajouter un service' : 'Modifier le service';
            formHtml = servicesFormHtml;
            taille = 'sm';
        } else if (context === 'taches') {
            title = (mode === 'add') ? 'Créer une nouvelle tâche' : 'Modifier la tâche';
            formHtml = tachesFormHtml;
            taille = 'lg';
        } else if (context === 'utilisateurs') {
            title = (mode === 'add') ? 'Ajouter un utilisateur' : 'Modifier l\'utilisateur';
            formHtml = utilisateursFormHtml;
            taille = 'sm';
        }

        modalTitle.text(title);
        modalBody.html(formHtml);
        modalBody.find('form')[0].reset();
        resetModalFooter();
        setModalSize(taille);
        modal.css('display', 'flex');

        // Recharger les selects selon le contexte
        setTimeout(function() {
            if (context === 'materiel') {
                loadServicesInSelects();
                loadUsersInSelects();
                loadTypeMaterielInSelects();

                // Permet de créer un nouveau type de matériel à la volée depuis le select
                $('#type').off('change').on('change', function() {
                    if ($(this).val() === '__new__') {
                        const nouveauType = prompt('Nom du nouveau type de matériel :');
                        if (nouveauType && nouveauType.trim()) {
                            const valeur = nouveauType.trim();
                            $.ajax({
                                url: '../backend/api/type_materiel.php?action=create',
                                type: 'POST',
                                data: { type: valeur },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        loadTypeMaterielInSelects();
                                        setTimeout(function() { $('#type').val(valeur); }, 150);
                                    } else {
                                        alert('Erreur: ' + (response.error || 'Impossible de créer ce type.'));
                                        $('#type').val('');
                                    }
                                },
                                error: function() {
                                    alert('Erreur de communication avec le serveur.');
                                    $('#type').val('');
                                }
                            });
                        } else {
                            $('#type').val('');
                        }
                    }
                });

                // Demande confirmation à chaque changement de service affecté, et impose
                // une date d'affectation avant de valider ce changement. La valeur de
                // référence (prevValue) est initialisée ici pour le mode "ajout" ; en mode
                // "édition", elle est réécrite après le pré-remplissage du formulaire (voir
                // le gestionnaire "Modifier" du matériel) pour ne pas déclencher la
                // confirmation sur le simple affichage des données existantes.
                $('#service').data('prevValue', $('#service').val() || '');
                $('#service').off('change').on('change', function() {
                    const nouveauService = $(this).val();
                    const ancienService = $(this).data('prevValue') || '';

                    if (nouveauService === ancienService) {
                        return; // Pas un vrai changement (ex: pré-remplissage)
                    }

                    const message = nouveauService
                        ? `Confirmer l'affectation de ce matériel au service "${nouveauService}" ?`
                        : 'Confirmer le retrait de l\'affectation de service pour ce matériel ?';

                    if (confirm(message)) {
                        $(this).data('prevValue', nouveauService);
                        if (nouveauService && !$('#date_derniere_affect').val()) {
                            // Pré-remplie à aujourd'hui, modifiable si la date réelle diffère.
                            $('#date_derniere_affect').val(new Date().toISOString().slice(0, 10));
                        }
                    } else {
                        // Annulé : on revient à la valeur précédente
                        $(this).val(ancienService);
                    }
                });
            } else if (context === 'taches') {
                loadUsersInSelects();
                loadServicesInSelects();
                loadTypeMaterielInSelects();
                loadMaterielInSelects();

                // "Service concerné" pré-remplit le filtre du sélecteur de matériel, par
                // commodité — l'utilisateur peut ensuite changer ce filtre indépendamment
                // (le matériel d'un service donné n'est pas forcément celui de la tâche).
                $('#tache-service').off('change').on('change', function() {
                    $('#tache-materiel-filtre-service').val($(this).val());
                    renderTacheMaterielOptions();
                });

                // Filtre par service et recherche par nom/n° de série du sélecteur de matériel
                $('#tache-materiel-filtre-service').off('change').on('change', renderTacheMaterielOptions);
                $('#tache-materiel-recherche').off('keyup').on('keyup', renderTacheMaterielOptions);

                // "+ Ajouter un nouveau matériel..." : révèle un mini-formulaire intégré à la
                // fiche (inspiré du select "type" du formulaire Matériel), pour ne pas avoir à
                // quitter la création/édition de la tâche en cours.
                $('#tache-materiel').off('change').on('change', function() {
                    if ($(this).val() === '__new__') {
                        $('#tache-materiel-nouveau').show();
                    } else {
                        $('#tache-materiel-nouveau').hide();
                    }
                });

                // Même mécanisme de création à la volée que #type sur le formulaire Matériel
                $('#nm-type').off('change').on('change', function() {
                    if ($(this).val() === '__new__') {
                        const nouveauType = prompt('Nom du nouveau type de matériel :');
                        if (nouveauType && nouveauType.trim()) {
                            const valeur = nouveauType.trim();
                            $.ajax({
                                url: '../backend/api/type_materiel.php?action=create',
                                type: 'POST',
                                data: { type: valeur },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        loadTypeMaterielInSelects();
                                        setTimeout(function() { $('#nm-type').val(valeur); }, 150);
                                    } else {
                                        alert('Erreur: ' + (response.error || 'Impossible de créer ce type.'));
                                        $('#nm-type').val('');
                                    }
                                },
                                error: function() {
                                    alert('Erreur de communication avec le serveur.');
                                    $('#nm-type').val('');
                                }
                            });
                        } else {
                            $('#nm-type').val('');
                        }
                    }
                });

                // Un service affecté impose une date d'affectation (même règle que le
                // formulaire Matériel complet, appliquée ici côté client + revalidée serveur).
                $('#nm-service').off('change').on('change', function() {
                    $('#nm-date-affect-group').toggle(!!$(this).val());
                });

                // Empêche la touche Entrée (recherche matériel ou mini-formulaire) de
                // soumettre par erreur toute la fiche Tâche en cours d'édition.
                $('#tache-materiel-groupe').off('keydown').on('keydown', 'input', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                    }
                });

                $('#btn-nm-annuler').off('click').on('click', function() {
                    $('#tache-materiel-nouveau').hide();
                    $('#tache-materiel-nouveau input, #tache-materiel-nouveau select').val('');
                    $('#nm-statut').val('En service');
                    $('#nm-date-affect-group').hide();
                    $('#tache-materiel').val('');
                });

                $('#btn-nm-creer').off('click').on('click', function() {
                    const type = $('#nm-type').val();
                    const marque = $('#nm-marque').val().trim();
                    const modele = $('#nm-modele').val().trim();
                    const numSerie = $('#nm-num-serie').val().trim();
                    const statut = $('#nm-statut').val();
                    const service = $('#nm-service').val();
                    const dateAffect = $('#nm-date-affect').val();

                    if (!type || !marque || !statut) {
                        alert('Le type, la marque et le statut sont requis pour créer un matériel.');
                        return;
                    }
                    if (service && !dateAffect) {
                        alert('La date d\'affectation est requise lorsqu\'un service affecté est sélectionné.');
                        return;
                    }

                    const $btn = $(this);
                    $btn.prop('disabled', true);

                    $.ajax({
                        url: '../backend/api/materiel.php?action=create',
                        type: 'POST',
                        data: {
                            type: type,
                            marque: marque,
                            modele: modele,
                            num_serie: numSerie,
                            statut: statut,
                            service: service,
                            date_derniere_affect: dateAffect,
                            created_by_login: localStorage.getItem('user_login') || ''
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                const displayText = `${type} ${marque} ${modele} (${numSerie})`;
                                $('#tache-materiel-nouveau').hide();
                                $('#tache-materiel-nouveau input, #tache-materiel-nouveau select').val('');
                                $('#nm-statut').val('En service');
                                $('#nm-date-affect-group').hide();
                                // Recharge le cache matériel puis sélectionne le nouvel élément ;
                                // le filtre service est levé pour garantir sa visibilité immédiate.
                                $('#tache-materiel-filtre-service').val('');
                                $('#tache-materiel-recherche').val('');
                                $.ajax({
                                    url: '../backend/api/materiel.php?action=read',
                                    type: 'GET',
                                    dataType: 'json',
                                    success: function(materiel) {
                                        materielCache = materiel;
                                        renderTacheMaterielOptions();
                                        $('#tache-materiel').val(displayText);
                                    }
                                });
                            } else {
                                alert('Erreur: ' + (response.error || 'Impossible de créer ce matériel.'));
                            }
                        },
                        error: function(xhr) {
                            let message = 'Erreur de communication avec le serveur.';
                            try { message = JSON.parse(xhr.responseText).error || message; } catch (e) {}
                            alert('Erreur: ' + message);
                        },
                        complete: function() {
                            $btn.prop('disabled', false);
                        }
                    });
                });
            }
        }, 100);
    }

    function closeModal() {
        modal.css('display', 'none');
    }

    // Logique d'ouverture de la modale (intelligente)
    $('#fab').on('click', function (e) {
        e.preventDefault();
        let context = $('.page-content.active').attr('id').replace('-page', '');
        // Le tableau de bord n'a pas de formulaire propre : le bouton "+" y ouvre
        // la création de tâche (action la plus courante depuis cette page).
        if (context === 'dashboard') {
            context = 'taches';
        }
        openModal(context, 'add');
    });

    // --- Modale des filtres avancés des tâches (priorité, matériel, périodes) ---
    const filtresTachesModal = $('#filtres-taches-modal');

    $('#fab-filtres-taches').on('click', function (e) {
        e.preventDefault();
        filtresTachesModal.css('display', 'flex');
    });

    $('#close-filtres-taches, #btn-appliquer-filtres-taches').on('click', function () {
        filtresTachesModal.css('display', 'none');
    });

    // Gestionnaire de clic "Étiquette d'inventaire (QR code)" pour le matériel
    $('#materiel-page').on('click', '.btn-qrcode', function () {
        const id = $(this).data('id');
        const label = $(this).data('label');
        const serie = $(this).data('serie');
        const service = $(this).data('service');
        showMaterielQRCode(id, label, serie, service);
    });

    // Gestionnaire de clic "Historique d'affectation" pour le matériel
    $('#materiel-page').on('click', '.btn-historique', function () {
        const id = $(this).data('id');
        const label = $(this).data('label');
        showMaterielHistorique(id, label);
    });

    // Gestionnaires de clics "Modifier" pour chaque page
    $('#materiel-page').on('click', '.btn-edit', function () {
        const id = $(this).data('id');
        // Récupérer les données du matériel à modifier
        $.ajax({
            url: '../backend/api/materiel.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                // Trouver le matériel avec l'ID correspondant
                const materiel = data.find(item => item.id == id);
                if (materiel) {
                    // Ouvrir la modale
                    openModal('materiel', 'edit');
                    
                    // Attendre que les selects soient chargés avant de remplir les valeurs
                    setTimeout(function() {
                        // Remplir le formulaire avec les données existantes
                        $('#materiel-form').append('<input type="hidden" name="id" value="' + materiel.id + '">');
                        $('#type').val(materiel.type);
                        $('#marque').val(materiel.marque);
                        $('#modele').val(materiel.modele);
                        $('#num_serie').val(materiel.num_serie);
                        $('#date_achat').val(materiel.date_achat);
                        $('#statut').val(materiel.statut);
                        // On mémorise le service actuel comme référence : la confirmation
                        // d'affectation ne doit se déclencher que sur un vrai changement,
                        // pas sur ce pré-remplissage.
                        $('#service').val(materiel.service_affecte).data('prevValue', materiel.service_affecte || '');
                        $('#utilisateur').val(materiel.utilisateur_assigne);
                        $('#date_derniere_affect').val(materiel.date_derniere_affect);
                    }, 200);

                    

                } else {
                    alert('Erreur: Impossible de trouver les informations du matériel.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                alert('Erreur: Impossible de récupérer les informations du matériel.');
            }
        });
    });
    
    // Gestionnaire de clics "Supprimer" pour le matériel
    $('#materiel-page').on('click', '.btn-delete', function () {
        const id = $(this).data('id');
        const row = $(this).closest('tr');
        const typeAndModel = row.find('td:nth-child(1)').text() + ' ' + row.find('td:nth-child(2)').text();
        
        // Demander confirmation avant de supprimer
        if (confirm('Êtes-vous sûr de vouloir supprimer ce matériel : ' + typeAndModel + ' ?')) {
            // Envoyer la requête de suppression
            $.ajax({
                url: '../backend/api/materiel.php?action=delete',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Supprimer la ligne du tableau
                        row.fadeOut(300, function() {
                            $(this).remove();
                        });
                        // Mettre à jour les statistiques
                        updateDashboardStats();
                        loadRecentActivities();
                        // Recharger les selects
                        loadMaterielInSelects();
                        // Afficher un message de succès
                        alert('Matériel supprimé avec succès.');
                    } else {
                        alert('Erreur: ' + (response.error || 'Une erreur est survenue.'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    try {
                        var response = JSON.parse(xhr.responseText);
                        alert('Erreur: ' + (response.error || 'Une erreur est survenue.'));
                    } catch(e) {
                        alert('Erreur: Une erreur est survenue lors de la communication avec le serveur.');
                    }
                }
            });
        }
    });
    // Gestionnaire de clics "Modifier" pour les services
    $('#services-page').on('click', '.btn-edit', function () {
        const id = $(this).data('id');
        // Récupérer les données du service à modifier
        $.ajax({
            url: '../backend/api/services.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                // Trouver le service avec l'ID correspondant
                const service = data.find(item => item.id == id);
                if (service) {
                    // Ouvrir la modale
                    openModal('services', 'edit');
                    
                    // Remplir le formulaire avec les données existantes
                    $('#service-form').append('<input type="hidden" name="id" value="' + service.id + '">');
                    $('#service-nom').val(service.nom);
                    $('#service-desc').val(service.description);
                } else {
                    alert('Erreur: Impossible de trouver les informations du service.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                alert('Erreur: Impossible de récupérer les informations du service.');
            }
        });
    });
    
    // Gestionnaire de clics "Supprimer" pour les services
    $('#services-page').on('click', '.btn-delete', function () {
        const id = $(this).data('id');
        const row = $(this).closest('tr');
        const serviceName = row.find('td:first-child').text();
        
        // Demander confirmation avant de supprimer
        if (confirm('Êtes-vous sûr de vouloir supprimer le service : ' + serviceName + ' ?')) {
            // Envoyer la requête de suppression
            $.ajax({
                url: '../backend/api/services.php?action=delete',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Supprimer la ligne du tableau
                        row.fadeOut(300, function() {
                            $(this).remove();
                        });
                        // Mettre à jour les statistiques
                        updateDashboardStats();
                        loadRecentActivities();
                        // Recharger les selects
                        loadServicesInSelects();
                        // Afficher un message de succès
                        alert('Service supprimé avec succès.');
                    } else {
                        alert('Erreur: ' + (response.error || 'Une erreur est survenue.'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    try {
                        const response = JSON.parse(xhr.responseText);
                        alert('Erreur: ' + (response.error || 'Une erreur est survenue.'));
                    } catch (e) {
                        alert('Erreur: Une erreur est survenue lors de la communication avec le serveur.');
                    }
                }
            });
        }
    });
    // Cliquer n'importe où sur une ligne (tâches, matériel, services, utilisateurs) ouvre
    // directement sa modification — évite de devoir viser le petit crayon, surtout au doigt
    // sur mobile. Les clics sur les boutons d'action (crayon inclus) sont ignorés ici : ils
    // ont déjà leur propre gestionnaire, plus bas, pas besoin de le déclencher deux fois.
    ['#taches-table', '#materiel-table', '#services-table', '#utilisateurs-table'].forEach(function (tableSelector) {
        $(tableSelector + ' tbody').on('click', 'tr', function (e) {
            if ($(e.target).closest('.btn-action').length) return;
            $(this).find('.btn-edit').trigger('click');
        });
    });

    // Gestionnaire de clics "Modifier" pour les tâches
    $('#taches-page').on('click', '.btn-edit', function () {

        const userRole = localStorage.getItem('user_role');
        const userId = localStorage.getItem('user_id');
        const userName = localStorage.getItem('user_name');

        const id = $(this).data('id');
        // Récupérer les données de la tâche à modifier
        $.ajax({
            url: '../backend/api/taches.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                // Trouver la tâche avec l'ID correspondant
                const tache = data.find(item => item.id == id);
                if (tache) {
                    // Ouvrir la modale
                    openModal('taches', 'edit');
                    
                    // Attendre que les selects soient chargés avant de remplir les valeurs
                    setTimeout(function() {
                        // Remplir le formulaire avec les données existantes
                        $('#tache-form').append('<input type="hidden" name="id" value="' + tache.id + '">');
                        $('#tache-titre').val(tache.titre);
                        $('#tache-motif_cause').val(tache.motif_cause);
                        $('#tache-desc').val(tache.description);
                        $('#tache-priorite').val(tache.priorite);
                        $('#tache-statut').val(tache.statut);
                        $('#tache-assigne').val(tache.assigne_a);
                        $('#tache-service').val(tache.service_concerne);
                        $('#tache-date-limite').val(tache.date_limite);
                        $('#tache-actions').val(tache.actions);

                        // Filtre du sélecteur de matériel levé ("Tous les services") pour
                        // garantir que le matériel déjà associé à la tâche reste visible même
                        // s'il appartient à un autre service que "Service concerné".
                        $('#tache-materiel-filtre-service').val('');
                        $('#tache-materiel-recherche').val('');
                        renderTacheMaterielOptions();
                        $('#tache-materiel').val(tache.materiel_concerne);

                        // Cacher/désactiver le bouton enregistrer si le user n'est pas admin et la tâche ne lui est pas assignée
                        if (userRole !== 'Administrateur' && userName !== tache.assigne_a) {
                            $('#modal-save').prop('disabled', true).css('opacity', '0.5').css('cursor', 'not-allowed');
                        }

                        
                        console.log("Taches:", JSON.stringify(tache, null, 2));
                    }, 200);
                } else {
                    alert('Erreur: Impossible de trouver les informations de la tâche.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                alert('Erreur: Impossible de récupérer les informations de la tâche.');
            }
        });
    });
    
    // Gestionnaire de clics "Supprimer" pour les tâches
    $('#taches-page').on('click', '.btn-delete', function () {
        const id = $(this).data('id');
        const row = $(this).closest('tr');
        const taskTitle = row.find('td:nth-child(2)').text();
        
        // Demander confirmation avant de supprimer
        if (confirm('Êtes-vous sûr de vouloir supprimer la tâche : ' + taskTitle + ' ?')) {
            // Envoyer la requête de suppression
            $.ajax({
                url: '../backend/api/taches.php?action=delete',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Supprimer la ligne du tableau
                        row.fadeOut(300, function() {
                            $(this).remove();
                        });
                        // Mettre à jour les statistiques
                        updateDashboardStats();
                        loadRecentActivities();
                        // Recharger les selects
                        loadMaterielInSelects();
                        // Afficher un message de succès
                        alert('Tâche supprimée avec succès.');
                    } else {
                        alert('Erreur: ' + (response.error || 'Une erreur est survenue.'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    try {
                        const response = JSON.parse(xhr.responseText);
                        alert('Erreur: ' + (response.error || 'Une erreur est survenue.'));
                    } catch (e) {
                        alert('Erreur: Une erreur est survenue lors de la communication avec le serveur.');
                    }
                }
            });
        }
    });
    
    // Vue active de la page Tâches : 'liste' (tableau filtrable) ou 'kanban'
    // (glisser-déposer). Détermine quelle vue rafraîchir après une mise à jour.
    let tachesViewMode = 'liste';

    function refreshTachesData() {
        if (tachesViewMode === 'kanban') {
            loadTachesKanban();
        } else {
            loadTaches();
        }
    }

    // Envoie une tâche mise à jour (objet complet + champs modifiés) à l'API, sans
    // passer par le formulaire modal — utilisé par les actions rapides "Prendre en
    // charge" / "Terminer" et le glisser-déposer Kanban pour réduire la friction du
    // traitement d'une tâche.
    function quickUpdateTache(tache, changes) {
        const updated = Object.assign({}, tache, changes);
        const updatedByLogin = localStorage.getItem('user_login') || '';

        $.ajax({
            url: '../backend/api/taches.php?action=update',
            type: 'POST',
            data: {
                id: updated.id,
                titre: updated.titre,
                motif_cause: updated.motif_cause,
                description: updated.description,
                priorite: updated.priorite,
                statut: updated.statut,
                assigne_a: updated.assigne_a,
                materiel_concerne: updated.materiel_concerne,
                service_concerne: updated.service_concerne,
                date_limite: updated.date_limite ? updated.date_limite.slice(0, 10) : '',
                actions: updated.actions,
                updated_by_login: updatedByLogin
            },
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    refreshTachesData();
                    updateDashboardStats();
                    loadRecentActivities();
                    loadTaskNotifications();
                } else {
                    alert('Erreur: ' + (response.error || 'Une erreur est survenue.'));
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX Error:', status, error);
                alert('Erreur: Une erreur est survenue lors de la mise à jour rapide de la tâche.');
            }
        });
    }

    // Gestionnaire de clics "Prendre en charge" pour les tâches (action rapide, sans formulaire)
    $('#taches-page').on('click', '.btn-quick-take', function (e) {
        e.stopPropagation();
        const tache = $(this).closest('tr').data('tache');
        if (!tache) return;
        const userName = localStorage.getItem('user_name');
        quickUpdateTache(tache, { statut: 'En cours', assigne_a: tache.assigne_a || userName });
    });

    // Gestionnaire de clics "Terminer" pour les tâches (action rapide, sans formulaire)
    $('#taches-page').on('click', '.btn-quick-done', function (e) {
        e.stopPropagation();
        const tache = $(this).closest('tr').data('tache');
        if (!tache) return;
        quickUpdateTache(tache, { statut: 'Terminé' });
    });

    // --- Vue Kanban des tâches (glisser-déposer entre colonnes de statut) ---
    function loadTachesKanban() {
        $.ajax({
            url: '../backend/api/taches.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function (data) {
                renderKanban(data);
            },
            error: function (xhr, status, error) {
                console.error('Erreur lors du chargement du Kanban des tâches:', error);
            }
        });
    }

    function renderKanban(taches) {
        const colonnes = {
            'À faire': $('#kanban-col-a-faire'),
            'En cours': $('#kanban-col-en-cours'),
            'Terminé': $('#kanban-col-termine'),
            'Annulé': $('#kanban-col-annule')
        };
        Object.values(colonnes).forEach(c => c.empty());
        const compteurs = { 'À faire': 0, 'En cours': 0, 'Terminé': 0, 'Annulé': 0 };

        taches.forEach(function (item) {
            const $colonne = colonnes[item.statut];
            if (!$colonne) return; // statut inconnu : on ne l'affiche pas en Kanban
            compteurs[item.statut]++;

            let priorityBadge = '';
            if (item.priorite === 'Haute') {
                priorityBadge = '<span class="priority-badge priority-high">Haute</span>';
            } else if (item.priorite === 'Moyenne') {
                priorityBadge = '<span class="priority-badge priority-medium">Moyenne</span>';
            } else {
                priorityBadge = '<span class="priority-badge priority-low">Basse</span>';
            }

            let echeanceBadge = '';
            if (item.date_limite && item.statut !== 'Terminé' && item.statut !== 'Annulé') {
                const todayStr = new Date().toISOString().slice(0, 10);
                const dateLimiteStr = item.date_limite.slice(0, 10);
                if (dateLimiteStr < todayStr) {
                    echeanceBadge = '<span class="status-badge status-danger">En retard</span>';
                }
            }

            const $card = $(`
                <div class="kanban-card" draggable="true" data-id="${item.id}">
                    <div class="kanban-card-title">${item.titre}</div>
                    <div class="kanban-card-meta">${priorityBadge}${echeanceBadge}</div>
                    ${item.assigne_a ? `<div class="kanban-card-assignee"><i class="fas fa-user"></i> ${item.assigne_a}</div>` : ''}
                </div>
            `);
            $card.data('tache', item);
            $colonne.append($card);
        });

        $('#kanban-count-a-faire').text(compteurs['À faire']);
        $('#kanban-count-en-cours').text(compteurs['En cours']);
        $('#kanban-count-termine').text(compteurs['Terminé']);
        $('#kanban-count-annule').text(compteurs['Annulé']);
    }

    // Bascule Vue liste / Vue Kanban
    $('#btn-vue-liste').on('click', function () {
        tachesViewMode = 'liste';
        $(this).addClass('active');
        $('#btn-vue-kanban').removeClass('active');
        $('#taches-kanban-view').hide();
        $('#taches-liste-view').show();
    });
    $('#btn-vue-kanban').on('click', function () {
        tachesViewMode = 'kanban';
        $(this).addClass('active');
        $('#btn-vue-liste').removeClass('active');
        $('#taches-liste-view').hide();
        $('#taches-kanban-view').show();
        loadTachesKanban();
    });

    // Glisser-déposer : déplacer une carte change le statut de la tâche correspondante
    $(document).on('dragstart', '.kanban-card', function (e) {
        e.originalEvent.dataTransfer.setData('text/plain', String($(this).data('id')));
        e.originalEvent.dataTransfer.effectAllowed = 'move';
        $(this).addClass('dragging');
    });
    $(document).on('dragend', '.kanban-card', function () {
        $(this).removeClass('dragging');
    });
    $(document).on('dragover', '.kanban-column-body', function (e) {
        e.preventDefault(); // requis pour autoriser le drop
        $(this).addClass('drag-over');
    });
    $(document).on('dragleave', '.kanban-column-body', function () {
        $(this).removeClass('drag-over');
    });
    $(document).on('drop', '.kanban-column-body', function (e) {
        e.preventDefault();
        $(this).removeClass('drag-over');

        const id = e.originalEvent.dataTransfer.getData('text/plain');
        const $card = $(`.kanban-card[data-id="${id}"]`);
        const tache = $card.data('tache');
        const nouveauStatut = $(this).closest('.kanban-column').data('statut');

        if (!tache || !nouveauStatut || tache.statut === nouveauStatut) return;
        quickUpdateTache(tache, { statut: nouveauStatut });
    });

    // Gestionnaire de clics "Modifier" pour les utilisateurs
    $('#utilisateurs-page').on('click', '.btn-edit', function () {
        const id = $(this).data('id');
        // Récupérer les données de l'utilisateur à modifier
        $.ajax({
            url: '../backend/api/utilisateurs.php?action=read',
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                // Trouver l'utilisateur avec l'ID correspondant
                const utilisateur = data.find(item => item.id == id);
                if (utilisateur) {
                    // Ouvrir la modale
                    openModal('utilisateurs', 'edit');
                    
                    // Remplir le formulaire avec les données existantes
                    $('#utilisateur-form').append('<input type="hidden" name="id" value="' + utilisateur.id + '">');
                    $('#nom').val(utilisateur.nom);
                    $('#login').val(utilisateur.login);
                    $('#email').val(utilisateur.email);
                    $('#role').val(utilisateur.role);
                    $('#mot_de_passe').val(''); // Ne pas afficher le mot de passe

                    //Desactiver le champs login
                    if (localStorage.getItem('user_role') != 'Superuser') {
                        $('#login').prop('disabled', true);
                    }
                } else {
                    alert('Erreur: Impossible de trouver les informations de l\'utilisateur.');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                alert('Erreur: Impossible de récupérer les informations de l\'utilisateur.');
            }
        });
    });
    
    // Gestionnaire de clics "Supprimer" pour les utilisateurs
    $('#utilisateurs-page').on('click', '.btn-delete', function () {
        const id = $(this).data('id');
        const row = $(this).closest('tr');
        const userName = row.find('td:first-child').text();
        
        // Demander confirmation avant de supprimer
        if (confirm('Êtes-vous sûr de vouloir supprimer l\'utilisateur : ' + userName + ' ?')) {
            // Envoyer la requête de suppression
            $.ajax({
                url: '../backend/api/utilisateurs.php?action=delete',
                type: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Supprimer la ligne du tableau
                        row.fadeOut(300, function() {
                            $(this).remove();
                        });
                        // Mettre à jour les statistiques
                        updateDashboardStats();
                        loadRecentActivities();
                        // Recharger les selects
                        loadUsersInSelects();
                        // Afficher un message de succès
                        alert('Utilisateur supprimé avec succès.');
                    } else {
                        alert('Erreur: ' + (response.error || 'Une erreur est survenue.'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    try {
                        const response = JSON.parse(xhr.responseText);
                        alert('Erreur: ' + (response.error || 'Une erreur est survenue.'));
                    } catch (e) {
                        alert('Erreur: Une erreur est survenue lors de la communication avec le serveur.');
                    }
                }
            });
        }
    });

    // Fermeture de la modale
    // Délégué (et non lié directement) car le footer de la modale peut être
    // remplacé dynamiquement (vues Historique / QR code) avec un nouveau bouton.
    $(document).on('click', '.close-button, #modal-cancel', closeModal);
    // Suppression de la fermeture au clic à l'extérieur pour éviter les fermetures accidentelles

    // --- Recherche en direct (Finale) ---
    function liveSearch(inputId, tableId) {
        $('#' + inputId).on('keyup', function () {
            const searchTerm = $(this).val().toLowerCase();
            $('#' + tableId + ' tbody tr').each(function () {
                const rowText = $(this).text().toLowerCase();
                $(this).toggle(rowText.indexOf(searchTerm) > -1);
            });
            updateTotalCount('#' + tableId, '#' + tableId + '-total');
        });
    }

    liveSearch('materiel-search', 'materiel-table');
    liveSearch('services-search', 'services-table');
    // taches-search est géré par filterTaches() (recherche titre + motif, combinée
    // aux autres filtres) — le brancher aussi ici causerait un conflit d'affichage.
    liveSearch('utilisateurs-search', 'utilisateurs-table');

    // --- Filtrage avancé pour les tâches ---
    // Colonnes du tableau : 1=Statut 2=Titre 3=Motif/Cause 4=Service 5=Matériel
    // 6=Assigné à 7=Date limite 8=Priorité 9=Actions
    function filterTaches() {
        const searchTerm = $('#taches-search').val().toLowerCase();
        const prioriteFilter = $('#filter-priorite').val();
        const statutFilter = $('#filter-statut').val();
        const assigneFilter = $('#filter-taches-assigne').val();
        const materielFilter = $('#filter-taches-materiel').val();
        const serviceFilter = $('#filter-taches-service').val();
        const dateAjoutDebut = $('#filter-date-ajout-debut').val();
        const dateAjoutFin = $('#filter-date-ajout-fin').val();
        const dateLimiteDebut = $('#filter-date-limite-debut').val();
        const dateLimiteFin = $('#filter-date-limite-fin').val();
        const dormanceFilter = $('#filter-dormance').val(); // '' | '7' | '14' | '30'

        $('#taches-table tbody tr').each(function () {
            const row = $(this);
            const titre = row.find('td:nth-child(2)').text().toLowerCase();
            const motif = row.find('td:nth-child(3)').text().toLowerCase();
            const service = row.find('td:nth-child(4)').text().trim();
            const materiel = row.find('td:nth-child(5)').text().trim();
            const assigne = row.find('td:nth-child(6)').text().trim();
            const priorite = row.find('td:nth-child(8) .priority-badge').text().trim();
            // .first() : la cellule Statut peut désormais contenir un 2e badge ("Inactive Xj")
            const statut = row.find('td:nth-child(1) .status-badge').first().text().trim();
            const dateAjout = row.data('date-ajout') || '';
            const dateLimite = row.data('date-limite') || '';
            const dateModif = row.data('date-modification') || '';
            const statutRaw = row.data('statut-raw') || '';

            // La recherche libre porte sur le titre ET le motif/cause
            const matchesSearch = !searchTerm || titre.indexOf(searchTerm) > -1 || motif.indexOf(searchTerm) > -1;
            const matchesPriorite = !prioriteFilter || priorite === prioriteFilter;
            const matchesStatut = !statutFilter || statut === statutFilter;
            const matchesAssigne = !assigneFilter || assigne === assigneFilter;
            const matchesMateriel = !materielFilter || materiel === materielFilter;
            const matchesService = !serviceFilter || service === serviceFilter;
            const matchesDateAjout = (!dateAjoutDebut || (dateAjout && dateAjout >= dateAjoutDebut))
                && (!dateAjoutFin || (dateAjout && dateAjout <= dateAjoutFin));
            const matchesDateLimite = (!dateLimiteDebut || (dateLimite && dateLimite >= dateLimiteDebut))
                && (!dateLimiteFin || (dateLimite && dateLimite <= dateLimiteFin));

            // "Dormante" : tâche encore active (À faire/En cours) et sans mise à jour
            // depuis au moins N jours — met en évidence les tâches oubliées, avec ou
            // sans date limite renseignée.
            let matchesDormance = true;
            if (dormanceFilter) {
                const estActive = (statutRaw === 'À faire' || statutRaw === 'En cours');
                if (!estActive || !dateModif) {
                    matchesDormance = false;
                } else {
                    const seuil = parseInt(dormanceFilter, 10);
                    const joursInactivite = Math.floor((new Date() - new Date(dateModif)) / 86400000);
                    matchesDormance = joursInactivite >= seuil;
                }
            }

            row.toggle(matchesSearch && matchesPriorite && matchesStatut && matchesAssigne
                && matchesMateriel && matchesService && matchesDateAjout && matchesDateLimite
                && matchesDormance);
        });

        // Indique visuellement (hors modale) qu'un filtre avancé est actif : priorité,
        // matériel, une période ou la dormance, puisque ces contrôles sont désormais
        // cachés dans la modale.
        let nbFiltresAvances = 0;
        if (prioriteFilter) nbFiltresAvances++;
        if (materielFilter) nbFiltresAvances++;
        if (dateAjoutDebut || dateAjoutFin) nbFiltresAvances++;
        if (dateLimiteDebut || dateLimiteFin) nbFiltresAvances++;
        if (dormanceFilter) nbFiltresAvances++;

        const badge = $('#filtres-avances-badge');
        if (nbFiltresAvances > 0) {
            badge.show().html(`<i class="fas fa-filter"></i> ${nbFiltresAvances} filtre(s) avancé(s) actif(s)`);
        } else {
            badge.hide().empty();
        }

        //updateTotalCount('#taches-table', '#taches-total');
    }

    // Appliquer les filtres en temps réel
    $('#taches-search, #filter-priorite, #filter-statut, #filter-taches-assigne, ' +
      '#filter-taches-materiel, #filter-taches-service, #filter-date-ajout-debut, ' +
      '#filter-date-ajout-fin, #filter-date-limite-debut, #filter-date-limite-fin, #filter-dormance')
        .on('change keyup', filterTaches);

    // Réinitialiser tous les filtres de la liste des tâches
    $('#btn-reset-filtres-taches').on('click', function () {
        $('#taches-search').val('');
        $('#filter-priorite, #filter-statut, #filter-taches-assigne, #filter-taches-materiel, #filter-taches-service, #filter-dormance').val('');
        $('#filter-date-ajout-debut, #filter-date-ajout-fin, #filter-date-limite-debut, #filter-date-limite-fin').val('');
        filterTaches();
    });

    $('#filter-statut').on('change', function() {
        const statut = $(this).val();
        xstatut = statut;
        loadTaches(null, statut);
    });
    


    // On charge le matériel, les services, les utilisateurs et les tâches au démarrage de l'application
    loadMateriel();
    loadServices();
    loadUtilisateurs();
    loadTaches();
    updateDashboardStats();
    loadRecentActivities(); // Charge les tâches récentes
    loadPannesRecurrentes();
    loadParcLifecycle();
    loadServicesDemandeurs();
    loadPannesParMois();
    loadTopResolveurs();

    // Charger les données pour les selects des formulaires
    loadUsersInSelects();
    loadServicesInSelects();
    loadMaterielInSelects();
    loadTypeMaterielInSelects();

    // Rafraîchir automatiquement les données, à l'intervalle configuré (Paramètres >
    // Fréquences ; par défaut 30 secondes).
    const intervalleRafraichissementMs = appParams.intervalle_rafraichissement_secondes * 1000;

    setInterval(function() {
        if ($('#dashboard-page').hasClass('active')) {
            updateDashboardStats();
            loadRecentActivities(); // Charge les tâches récentes
            loadPannesRecurrentes();
            loadServicesDemandeurs();
            loadPannesParMois();
            loadTopResolveurs();
        }
    }, intervalleRafraichissementMs);

    // Rafraîchir les notifications de tâches assignées (badge + pop) au même intervalle,
    // quelle que soit la page active.
    setInterval(function () {
        loadTaskNotifications();
    }, intervalleRafraichissementMs);

    // --- GESTION DE LA SOUMISSION DES FORMULAIRES ---
    // On utilise des gestionnaires d'événements délégués attachés à la modale
    $('#form-modal').on('submit', '#materiel-form', function(e) {
        e.preventDefault(); // Empêche le rechargement de la page

        // Un service affecté impose une date d'affectation (filet de sécurité : la
        // confirmation au changement de service la pré-remplit déjà normalement).
        if ($('#service').val() && !$('#date_derniere_affect').val()) {
            alert('Merci de renseigner la date de dernière affectation : un service est affecté à ce matériel.');
            $('#date_derniere_affect').focus();
            return;
        }

        // On récupère les données du formulaire
        const formData = {
            type: $('#type').val(),
            marque: $('#marque').val(),
            modele: $('#modele').val(),
            num_serie: $('#num_serie').val(),
            date_achat: $('#date_achat').val(),
            statut: $('#statut').val(),
            service: $('#service').val(),
            utilisateur: $('#utilisateur').val(),
            date_derniere_affect: $('#date_derniere_affect').val(),
            created_by_login: localStorage.getItem('user_login') || '',
            updated_by_login: localStorage.getItem('user_login') || ''
        };

        // Déterminer si c'est une création ou une mise à jour
        const hasId = $(this).find('input[name="id"]').length > 0;
        const action = hasId ? 'update' : 'create';

        if (hasId) {
            formData.id = $(this).find('input[name="id"]').val();
        }

        $.ajax({
            url: '../backend/api/materiel.php?action=' + action,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Si succès, on ferme la modale et on rafraîchit la table
                    closeModal(); // Utilisation de la fonction closeModal existante
                    loadMateriel();
                    updateDashboardStats();
                    loadRecentActivities();
                    // Recharger les selects
                    loadMaterielInSelects();
                    // Supprimer le champ id caché s'il existe
                    $('#materiel-form input[name="id"]').remove();
                    // Réinitialiser le formulaire
                    $('#materiel-form')[0].reset();
                } else {
                    // Sinon, on affiche l'erreur
                    alert('Erreur: ' + (response.error || 'Une erreur inconnue est survenue'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                console.error('Response:', xhr.responseText);
                try {
                    var response = JSON.parse(xhr.responseText);
                    alert('Erreur: ' + (response.error || 'Une erreur de communication avec le serveur est survenue.'));
                } catch(e) {
                    alert('Une erreur de communication avec le serveur est survenue. Veuillez vérifier la console pour plus de détails.');
                }
            }
        });
    });
    
    // --- GESTION DE LA SOUMISSION DU FORMULAIRE SERVICE ---
    // On utilise un gestionnaire d'événement délégué attaché à la modale
    $('#form-modal').on('submit', '#service-form', function(e) {
        e.preventDefault(); // Empêche le rechargement de la page

        // On récupère les données du formulaire
        const formData = {
            nom: $('#service-nom').val(),
            description: $('#service-desc').val(),
            created_by_login: localStorage.getItem('user_login') || '',
            updated_by_login: localStorage.getItem('user_login') || '',
        };
        
        // Déterminer si c'est une création ou une mise à jour
        const hasId = $(this).find('input[name="id"]').length > 0;
        const action = hasId ? 'update' : 'create';
        
        if (hasId) {
            formData.id = $(this).find('input[name="id"]').val();
        }

        $.ajax({
            url: '../backend/api/services.php?action=' + action,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Si succès, on ferme la modale et on rafraîchit la table
                    closeModal();
                    loadServices();
                    updateDashboardStats();
                    loadRecentActivities();
                    // Recharger les selects
                    loadServicesInSelects();
                    // Supprimer le champ id caché s'il existe
                    $('#service-form input[name="id"]').remove();
                    // Réinitialiser le formulaire
                    $('#service-form')[0].reset();
                } else {
                    // Sinon, on affiche l'erreur
                    alert('Erreur: ' + (response.error || 'Une erreur inconnue est survenue'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                console.error('Response:', xhr.responseText);
                try {
                    var response = JSON.parse(xhr.responseText);
                    alert('Erreur: ' + (response.error || 'Une erreur de communication avec le serveur est survenue.'));
                } catch(e) {
                    alert('Une erreur de communication avec le serveur est survenue. Veuillez vérifier la console pour plus de détails.');
                }
            }
        });
    });
    
    // --- GESTION DE LA SOUMISSION DU FORMULAIRE UTILISATEUR ---
    // On utilise un gestionnaire d'événement délégué attaché à la modale
    $('#form-modal').on('submit', '#utilisateur-form', function(e) {
        e.preventDefault(); // Empêche le rechargement de la page

        // On récupère les données du formulaire
        const formData = {
            nom: $('#nom').val(),
            login: $('#login').val(),
            email: $('#email').val(),
            role: $('#role').val(),
            mot_de_passe: $('#mot_de_passe').val(),
            created_by_login: localStorage.getItem('user_login') || '',
            updated_by_login: localStorage.getItem('user_login') || '',
        };
        
        // Déterminer si c'est une création ou une mise à jour
        const hasId = $(this).find('input[name="id"]').length > 0;
        const action = hasId ? 'update' : 'create';
        
        if (hasId) {
            formData.id = $(this).find('input[name="id"]').val();
        }

        $.ajax({
            url: '../backend/api/utilisateurs.php?action=' + action,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Si succès, on ferme la modale et on rafraîchit la table
                    closeModal();
                    loadUtilisateurs();
                    updateDashboardStats();
                    loadRecentActivities();
                    // Recharger les selects
                    loadUsersInSelects();
                    // Supprimer le champ id caché s'il existe
                    $('#utilisateur-form input[name="id"]').remove();
                    // Réinitialiser le formulaire
                    $('#utilisateur-form')[0].reset();
                } else {
                    // Sinon, on affiche l'erreur
                    alert('Erreur: ' + (response.error || 'Une erreur inconnue est survenue'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                console.error('Response:', xhr.responseText);
                try {
                    var response = JSON.parse(xhr.responseText);
                    alert('Erreur: ' + (response.error || 'Une erreur de communication avec le serveur est survenue.'));
                } catch(e) {
                    alert('Une erreur de communication avec le serveur est survenue. Veuillez vérifier la console pour plus de détails.');
                }
            }
        });
    });

    // --- GESTION DE LA SOUMISSION DU FORMULAIRE TÂCHES ---
    // On utilise un gestionnaire d'événement délégué attaché à la modale
    $('#form-modal').on('submit', '#tache-form', function(e) {
        e.preventDefault(); // Empêche le rechargement de la page

        // On récupère les données du formulaire
                    const formData = {
                titre: $('#tache-titre').val(),
                motif_cause: $('#tache-motif_cause').val(),
                description: $('#tache-desc').val(),
                priorite: $('#tache-priorite').val(),
                statut: $('#tache-statut').val(),
                assigne_a: $('#tache-assigne').val(),
                service_concerne: $('#tache-service').val(),
                materiel_concerne: $('#tache-materiel').val(),
                date_limite: $('#tache-date-limite').val(),
                actions: $('#tache-actions').val(),
                created_by_login: localStorage.getItem('user_login') || '',
                updated_by_login: localStorage.getItem('user_login') || ''
            };
        
        // Déterminer si c'est une création ou une mise à jour
        const hasId = $(this).find('input[name="id"]').length > 0;
        const action = hasId ? 'update' : 'create';
        
        if (hasId) {
            formData.id = $(this).find('input[name="id"]').val();
        }

        $.ajax({
            url: '../backend/api/taches.php?action=' + action,
            type: 'POST',
            data: formData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Si succès, on ferme la modale et on rafraîchit la table
                    closeModal();
                    loadTaches();
                    updateDashboardStats();
                    loadRecentActivities();
                    // Recharger les selects
                    loadMaterielInSelects();
                    // Supprimer le champ id caché s'il existe
                    $('#tache-form input[name="id"]').remove();
                    // Réinitialiser le formulaire
                    $('#tache-form')[0].reset();
                } else {
                    // Sinon, on affiche l'erreur
                    alert('Erreur: ' + (response.error || 'Une erreur inconnue est survenue'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error);
                console.error('Response:', xhr.responseText);
                try {
                    var response = JSON.parse(xhr.responseText);
                    alert('Erreur: ' + (response.error || 'Une erreur de communication avec le serveur est survenue.'));
                } catch(e) {
                    alert('Une erreur de communication avec le serveur est survenue. Veuillez vérifier la console pour plus de détails.');
                }
            }
        });
    });

    // Assurez-vous que le bouton "Enregistrer" déclenche la soumission du formulaire actif
    $('#form-modal').on('click', '#modal-save', function() {
        // Déterminer quel formulaire est actuellement affiché dans la modale
        if ($('#materiel-form').length > 0) {
            $('#materiel-form').trigger('submit');
        } else if ($('#service-form').length > 0) {
            $('#service-form').trigger('submit');
        } else if ($('#tache-form').length > 0) {
            $('#tache-form').trigger('submit');
        } else if ($('#utilisateur-form').length > 0) {
            $('#utilisateur-form').trigger('submit');
        }
    });

    // Fonction pour récupérer les données visibles du tableau sous forme de JSON
    function getVisibleTableDataAsJson(tableId) {
        const data = [];
        const headers = [];
        // Obtenir les en-têtes, en ignorant la colonne "Actions"
        $(`${tableId} thead th`).each(function() {
            const headerText = $(this).text().trim();
            if (headerText !== 'Actions') {
                headers.push(headerText);
            }
        });

        // Obtenir les lignes visibles
        $(`${tableId} tbody tr:visible`).each(function() {
            const rowObject = {};
            $(this).find('td').each(function(index) {
                // Mapper le td à l'en-tête, en ignorant la colonne "Actions"
                if (index < headers.length) {
                    const headerKey = headers[index];
                    rowObject[headerKey] = $(this).text().trim();
                }
            });
            data.push(rowObject);
        });
        return data;
    }

    // Fonction pour exporter les données du tableau vers un fichier Excel (.xlsx)
    function exportToExcel() {
        const data = getVisibleTableDataAsJson('#materiel-table');
        if (data.length === 0) {
            alert('Aucune donnée à exporter.');
            return;
        }
        const worksheet = XLSX.utils.json_to_sheet(data);
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, 'Matériel');
        XLSX.writeFile(workbook, 'export_materiel.xlsx');
    }

    // Fonction pour exporter les données du tableau vers un fichier Word (.docx)
    function exportToWord() {
        const data = getVisibleTableDataAsJson('#materiel-table');
        if (data.length === 0) {
            alert('Aucune donnée à exporter.');
            return;
        }

        const { Document, Packer, Paragraph, Table, TableCell, TableRow, WidthType } = docx;

        const tableRows = [
            new TableRow({
                children: Object.keys(data[0]).map(header => new TableCell({ 
                    children: [new Paragraph({ text: header, bold: true })], 
                    shading: { fill: "D3D3D3" } 
                })),
            }),
            ...data.map(row => new TableRow({
                children: Object.values(row).map(cell => new TableCell({ children: [new Paragraph(cell)] })),
            }))
        ];

        const table = new Table({
            rows: tableRows,
            width: { size: 100, type: WidthType.PERCENTAGE },
        });

        const doc = new Document({
            sections: [{
                children: [
                    new Paragraph({ text: 'Liste du Matériel', heading: 'Heading1' }),
                    table
                ],
            }],
        });

        Packer.toBlob(doc).then(blob => {
            saveAs(blob, 'export_materiel.docx');
        });
    }

    // Attacher les événements d'exportation
    $('#export-excel-btn').on('click', exportToExcel);
    $('#export-word-btn').on('click', exportToWord);
});

// Fonction pour mettre à jour le nombre total d'éléments visibles dans un tableau
function updateTotalCount(tableId, totalId, titrePage = '') {
    const total = $(`${tableId} tbody tr:visible`).length;
    $(`${totalId}`).text(`${titrePage}` + `(${total})`);
}
