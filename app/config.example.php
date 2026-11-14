<?php

declare(strict_types=1);

// Belprise configuration.
// Create app/config.php with a random secret: php app/bin/init-config.php

return [
    // Data directory: SQLite database, media, uploads, inbox.
    // Must be outside the web root and writable by the web server.
    'data_dir' => __DIR__ . '/../data',

    // HMAC secret (signed media URLs, IP fingerprints): 64 hexadecimal characters.
    'secret' => '',

    // Let Apache mod_xsendfile send media files (XSendFilePath must cover data_dir).
    'xsendfile' => false,

    // Send Strict-Transport-Security. Enable only once HTTPS is in place.
    'hsts' => false,

    // IP addresses of reverse proxies allowed to set X-Forwarded-For.
    'trusted_proxies' => [],

    // Public address of the application, used in the links sent by e-mail
    // (invitations). Empty: taken from the request (development).
    'app_url' => '',

    // Sender of the e-mails (invitations, notices). The domain needs SPF / DKIM.
    'mail_from' => 'Belprise <no-reply@belprise.fr>',

    // Address that receives the beta access requests (also listed in /superadmin).
    'operator_email' => '',
];
