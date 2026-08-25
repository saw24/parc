<?php
// Client SMTP minimal, sans dépendance externe (pas de composer/vendor dans ce projet).
// Suffisant pour l'envoi de notifications texte simples (nouvelle tâche assignée,
// résumé quotidien, alerte d'escalade) — pas de pièces jointes, pas de HTML multipart.

class SmtpException extends Exception
{
}

class SmtpMailer
{
    private string $host;
    private int $port;
    private string $encryption; // 'none' | 'tls' | 'ssl'
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private int $timeout;

    /**
     * @param array $config Sous-ensemble de backend/config/parametres.php::get_parametres()
     *                       (smtp_host, smtp_port, smtp_encryption, smtp_username,
     *                       smtp_password, smtp_from_email, smtp_from_name).
     */
    public function __construct(array $config, int $timeout = 10)
    {
        $this->host       = trim($config['smtp_host'] ?? '');
        $this->port       = (int) ($config['smtp_port'] ?? 587);
        $this->encryption = $config['smtp_encryption'] ?? 'tls';
        $this->username   = trim($config['smtp_username'] ?? '');
        // Les espaces sont retirés : un mot de passe d'application Gmail est affiché par
        // Google en 4 blocs séparés d'espaces ("abcd efgh ijkl mnop") pour la lisibilité —
        // ces espaces ne font pas partie du mot de passe réel et, collés tels quels,
        // provoquent un "535 Username and Password not accepted".
        $this->password   = str_replace(' ', '', (string) ($config['smtp_password'] ?? ''));
        $this->fromEmail  = trim($config['smtp_from_email'] ?? '');
        $this->fromName   = trim($config['smtp_from_name'] ?? '');
        $this->timeout    = $timeout;
    }

    /**
     * Envoie un email texte simple. Retourne true en cas de succès ; lève une
     * SmtpException (message détaillé, utile pour l'écran "Tester l'envoi") sinon.
     * @throws SmtpException
     */
    public function send(string $to, string $subject, string $body): bool
    {
        if ($this->host === '' || $this->fromEmail === '') {
            throw new SmtpException("Configuration SMTP incomplète (hôte ou adresse d'expédition manquant).");
        }
        if ($to === '') {
            throw new SmtpException("Adresse destinataire manquante.");
        }

        $transport = ($this->encryption === 'ssl') ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout
        );
        if (!$socket) {
            throw new SmtpException("Connexion SMTP impossible ($this->host:$this->port) : $errstr ($errno)");
        }
        stream_set_timeout($socket, $this->timeout);

        try {
            $this->readAndCheck($socket, [220]); // bannière du serveur

            $hostname = $_SERVER['SERVER_NAME'] ?? 'localhost';
            $this->command($socket, "EHLO $hostname", [250]);

            if ($this->encryption === 'tls') {
                $this->command($socket, "STARTTLS", [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new SmtpException("Échec de la négociation TLS avec le serveur SMTP.");
                }
                // Nouvelle poignée de main EHLO requise après STARTTLS
                $this->command($socket, "EHLO $hostname", [250]);
            }

            if ($this->username !== '') {
                $this->command($socket, "AUTH LOGIN", [334]);
                $this->command($socket, base64_encode($this->username), [334]);
                $this->command($socket, base64_encode($this->password), [235]);
            }

            $this->command($socket, "MAIL FROM:<{$this->fromEmail}>", [250]);
            $this->command($socket, "RCPT TO:<{$to}>", [250, 251]);
            $this->command($socket, "DATA", [354]);

            $fromHeader = $this->fromName !== '' ? "{$this->fromName} <{$this->fromEmail}>" : $this->fromEmail;
            $headers = [
                "From: {$fromHeader}",
                "To: {$to}",
                "Subject: " . $this->encodeSubject($subject),
                "MIME-Version: 1.0",
                "Content-Type: text/plain; charset=UTF-8",
                "Date: " . date('r'),
            ];
            // Transparence SMTP : une ligne de contenu commençant par un point doit être doublée
            $escapedBody = preg_replace('/^\./m', '..', $body);
            $payload = implode("\r\n", $headers) . "\r\n\r\n" . $escapedBody . "\r\n.\r\n";
            fwrite($socket, $payload);
            $this->readAndCheck($socket, [250]);

            $this->command($socket, "QUIT", [221]);
        } finally {
            fclose($socket);
        }

        return true;
    }

    private function encodeSubject(string $subject): string
    {
        return "=?UTF-8?B?" . base64_encode($subject) . "?=";
    }

    private function command($socket, string $cmd, array $expectedCodes): string
    {
        fwrite($socket, $cmd . "\r\n");
        return $this->readAndCheck($socket, $expectedCodes);
    }

    private function readAndCheck($socket, array $expectedCodes): string
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            // Une réponse multi-lignes a un '-' en 4e position sauf sur sa dernière ligne
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if ($response === '') {
            throw new SmtpException("Pas de réponse du serveur SMTP (délai dépassé ?).");
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new SmtpException("Réponse SMTP inattendue : " . trim($response));
        }
        return $response;
    }
}
