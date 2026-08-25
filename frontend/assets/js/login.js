$(document).ready(function() {
    // Vérifier si l'utilisateur est déjà connecté
    checkSession();

    // Gestion de la soumission du formulaire de connexion
    $('#login-form').on('submit', function(e) {
        e.preventDefault();
        
        const login = $('#login').val().trim();
        const password = $('#password').val();
        
        // Validation basique
        if (!login || !password) {
            showError('Veuillez remplir tous les champs.');
            return;
        }
        
        // Afficher le loading
        showLoading(true);
        hideMessages();
        
        // Envoyer la requête de connexion
        $.ajax({
            url: '../backend/api/auth.php?action=login',
            type: 'POST',
            data: {
                login: login,
                password: password
            },
            dataType: 'json',
            success: function(response) {
                showLoading(false);
                
                if (response.success) {
                    showSuccess('Connexion réussie ! Redirection...');
                    
                    // Stocker les informations de session
                    localStorage.setItem('user_id', response.user.id);
                    localStorage.setItem('user_name', response.user.nom);
                    localStorage.setItem('user_role', response.user.role);
                    localStorage.setItem('user_login', response.user.login);
                    localStorage.setItem('session_token', response.session_token);
                    
                    // Rediriger vers l'application principale
                    setTimeout(function() {
                        window.location.href = 'index.html';
                    }, 1500);
                } else {
                    showError(response.error || 'Erreur de connexion.');
                }
            },
            error: function(xhr, status, error) {
                showLoading(false);
                console.error('Erreur AJAX:', status, error);
                
                try {
                    const response = JSON.parse(xhr.responseText);
                    showError(response.error || 'Erreur de communication avec le serveur.');
                } catch(e) {
                    showError('Erreur de communication avec le serveur.');
                }
            }
        });
    });

    // Fonction pour vérifier la session
    function checkSession() {
        const sessionToken = localStorage.getItem('session_token');
        const userId = localStorage.getItem('user_id');
        
        if (sessionToken && userId) {
            // Vérifier la validité de la session côté serveur
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
                        // Session valide, rediriger vers l'application
                        window.location.href = 'index.html';
                    } else {
                        // Session invalide, nettoyer le localStorage
                        clearSession();
                    }
                },
                error: function() {
                    // Erreur de communication, nettoyer le localStorage
                    clearSession();
                }
            });
        }
    }

    // Fonction pour afficher le loading
    function showLoading(show) {
        if (show) {
            $('#btn-text').hide();
            $('#loading').show();
            $('#login-btn').prop('disabled', true);
        } else {
            $('#btn-text').show();
            $('#loading').hide();
            $('#login-btn').prop('disabled', false);
        }
    }

    // Fonction pour afficher un message d'erreur
    function showError(message) {
        $('#error-message').text(message).show();
        $('#success-message').hide();
    }

    // Fonction pour afficher un message de succès
    function showSuccess(message) {
        $('#success-message').text(message).show();
        $('#error-message').hide();
    }

    // Fonction pour masquer tous les messages
    function hideMessages() {
        $('#error-message').hide();
        $('#success-message').hide();
    }

    // Fonction pour nettoyer la session
    function clearSession() {
        localStorage.removeItem('user_id');
        localStorage.removeItem('user_name');
        localStorage.removeItem('user_role');
        localStorage.removeItem('user_login');
        localStorage.removeItem('session_token');
    }

    // Gestion de la touche Entrée
    $('#password').on('keypress', function(e) {
        if (e.which === 13) {
            $('#login-form').submit();
        }
    });

    // Focus automatique sur le champ login
    $('#login').focus();
});
