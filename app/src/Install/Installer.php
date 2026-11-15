<?php

declare(strict_types=1);

namespace Belprise\Install;

use Belprise\Config;
use Belprise\ConfigException;
use Belprise\Database\Database;
use Belprise\DataDir;
use Belprise\Http\HttpException;
use Belprise\Http\Request;
use Belprise\Http\Response;
use Belprise\Mail\Mailer;
use Belprise\Mail\Message;
use Belprise\Mail\NativeMailer;
use Belprise\Media\ServerEncoder;
use Belprise\Quota;
use Belprise\Repository\AuditLog;
use Belprise\Repository\Invitations;
use Belprise\Repository\Organizations;
use Belprise\Repository\PlatformLog;
use Belprise\Repository\Settings;
use Belprise\Repository\Superadmins;
use Belprise\Security\AdminAuth;
use Belprise\Security\SecretBox;
use Belprise\Security\SecurityHeaders;
use Belprise\Security\Totp;

/**
 * Installation assistant in the browser (CAHIER_DES_CHARGES §7.2), for a new
 * server: it only exists while the configuration is missing. Every step
 * needs the unlock code the server writes in data/installation-code.txt
 * (outside the web root): reading it proves one controls the server.
 *
 * Steps: 1. environment and unlock code; 2. addresses, with a test e-mail;
 * 3. operator account; 4. its two-factor authentication; 5. first
 * organization (optional); 6. encoding of the playback versions (browser,
 * or server when FFmpeg is there); 7. summary, with the scheduled task.
 * Nothing is written before the last step, except the state of the steps in
 * data/ (outside the web root, removed at the end).
 */
final class Installer
{
    public const PATH = '/installation';
    private const CODE_FILE = 'installation-code.txt';
    private const STATE_FILE = 'installation-state.json';
    private const MAX_ATTEMPTS = 10;
    private const LOCK_SECONDS = 900;
    private const STEPS = ['config' => 2, 'account' => 3, 'totp' => 4, 'organization' => 5, 'encoding' => 6, 'summary' => 7];
    public const TOTAL_STEPS = 7;

    private readonly string $dataDir;
    private readonly string $configFile;

    public function __construct(
        private readonly string $rootDir,
        /** Tests: where e-mails go (the test e-mail, the invitation). */
        private readonly ?Mailer $mailer = null,
    ) {
        $this->configFile = Config::file($rootDir);
        // With BELPRISE_CONFIG (Docker), the configuration sits in the data folder itself.
        $this->dataDir = $this->configFile === $rootDir . '/app/config.php' ? $rootDir . '/data' : dirname($this->configFile);
    }

    public static function needed(string $rootDir): bool
    {
        return !is_file(Config::file($rootDir));
    }

    public function handle(Request $request): Response
    {
        $nonce = base64_encode(random_bytes(16));
        if ($request->path !== self::PATH) {
            $response = Response::redirect(self::PATH);
        } else {
            try {
                $response = $request->method === 'POST' ? $this->post($request) : $this->start();
            } catch (HttpException $e) {
                $response = $this->page('Installation', '<p class="error" role="alert">' . self::e($e->getMessage()) . '</p><p><a href="' . self::PATH . '">Revenir au début</a></p>', $e->status);
            }
        }
        SecurityHeaders::apply($response, $nonce);
        $response->setHeader('Cache-Control', 'no-store');
        $response->body = str_replace('{{nonce}}', $nonce, $response->body);

        return $response;
    }

    // ---- 1. Environment and unlock code ---------------------------------------------------

    private function start(): Response
    {
        if (is_dir($this->dataDir) || @mkdir($this->dataDir, 0700, true)) {
            $this->code(); // written now, read by the person in the file manager
        }
        $checks = Environment::server($this->dataDir, $this->rootDir . '/public');
        $list = '';
        foreach ($checks as $c) {
            $list .= '<li class="' . ($c['ok'] ? 'ok' : ($c['blocking'] ? 'ko' : 'warn')) . '">' . self::e($c['label']) . ($c['detail'] === '' ? '' : ' — ' . self::e($c['detail'])) . '</li>';
        }
        if (Environment::blocking($checks) > 0) {
            return $this->page('Vérification du serveur', '<p>Le serveur ne peut pas encore accueillir belprise :</p><ul class="checks">' . $list . '</ul><p>Corrigez les points en rouge, puis rechargez cette page.</p>', 200, 1);
        }

        return $this->page('Bienvenue', <<<HTML
<p>Cet assistant installe belprise sur ce serveur en sept étapes. Rien n’est enregistré avant la dernière.</p>
<details><summary>Le serveur convient</summary><ul class="checks">{$list}</ul></details>
<form method="post" action="/installation">
  <input type="hidden" name="step" value="unlock">
  <h2>Code de déverrouillage</h2>
  <p>Ouvrez <code>data/installation-code.txt</code> avec le gestionnaire de fichiers de votre hébergement (ou <code>docker exec … cat</code>), et recopiez le code. Il prouve que vous avez la main sur le serveur.</p>
  <label>Code<input name="code" required autocomplete="off" spellcheck="false" autofocus></label>
  <button type="submit">Commencer</button>
</form>
HTML, 200, 1);
    }

    private function post(Request $request): Response
    {
        $data = $request->form();
        $code = (string) ($data['code'] ?? '');
        $this->checkCode($code, Request::network($request->clientIp([])));
        $step = (string) ($data['step'] ?? '');
        $state = $this->state() ?? [];

        if ($step === 'unlock') {
            $this->saveState(['stage' => 'config']);

            return $this->configPage($code, [], '');
        }
        $stage = (string) ($state['stage'] ?? '');
        if (!isset(self::STEPS[$step]) || !isset(self::STEPS[$stage]) || self::STEPS[$step] > self::STEPS[$stage]) {
            throw new HttpException(409, 'Étape manquante : reprenez l’installation depuis le début.');
        }

        return match ($step) {
            'config' => $this->postConfig($data, $code, $state),
            'account' => $this->postAccount($data, $code, $state),
            'totp' => $this->postTotp($data, $code, $state),
            'organization' => $this->postOrganization($data, $code, $state),
            'encoding' => $this->postEncoding($data, $code, $state),
            'summary' => $this->finish($state),
        };
    }

    // ---- 2. Addresses, with a test e-mail ------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $state
     */
    private function postConfig(array $data, string $code, array $state): Response
    {
        $values = [
            'app_url' => rtrim(trim((string) ($data['app_url'] ?? '')), '/'),
            'mail_from' => trim((string) ($data['mail_from'] ?? '')),
        ];
        if ($values['app_url'] === '') {
            return $this->configPage($code, $values, 'Saisissez l’adresse de l’application : elle sert aux liens des e-mails.');
        }
        try {
            Config::fromArray($values + ['data_dir' => $this->dataDir, 'secret' => str_repeat('x', 64)]); // same checks as at every start
        } catch (ConfigException $e) {
            return $this->configPage($code, $values, 'Configuration : ' . $e->getMessage());
        }
        if (($data['action'] ?? '') === 'test') {
            $to = trim((string) ($data['test_to'] ?? ''));
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                return $this->configPage($code, $values + ['test_to' => $to], 'Saisissez l’adresse qui doit recevoir l’e-mail de test.');
            }
            $sent = $this->mailer($values['mail_from'])->send(new Message(
                $to,
                'belprise : e-mail de test de l’installation',
                "Bonjour,\n\nCet e-mail confirme que le serveur de belprise sait envoyer des messages (invitations, mots de passe oubliés…).\n\n"
                . 'Expéditeur utilisé : ' . $values['mail_from'] . "\n\nVous pouvez revenir à l’assistant d’installation.\n",
            ));

            return $this->configPage($code, $values + ['test_to' => $to], '', $sent
                ? 'E-mail de test envoyé à ' . $to . ' : vérifiez sa réception (et les indésirables) avant de continuer.'
                : 'L’e-mail n’a pas pu partir. Hébergement : la fonction mail() de PHP est refusée, voyez avec l’hébergeur. Docker : renseignez les variables BELPRISE_SMTP_HOST, BELPRISE_SMTP_USER et BELPRISE_SMTP_PASSWORD. Vous pouvez continuer, mais les invitations ne partiront pas.');
        }
        $this->saveState(['stage' => 'account', 'config' => $values] + $state);

        return $this->accountPage($code, $state, '');
    }

    /** @param array<string, string> $values */
    private function configPage(string $code, array $values, string $error, string $notice = ''): Response
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'app.example.fr');
        // Suggestions: "app.belprise.fr" → belprise.fr (no port in e-mail addresses).
        $domain = preg_replace(['/:\d+$/', '/^app\./'], '', $host) ?? '';
        $appUrl = self::e($values['app_url'] ?? ((($_SERVER['HTTPS'] ?? '') === 'on' || str_contains($host, '.') ? 'https://' : 'http://') . $host));
        $mailFrom = self::e($values['mail_from'] ?? 'belprise <no-reply@' . $domain . '>');
        $testTo = self::e($values['test_to'] ?? '');
        $err = $error === '' ? '' : '<p class="error" role="alert">' . self::e($error) . '</p>';
        $note = $notice === '' ? '' : '<p class="notice" role="status">' . self::e($notice) . '</p>';
        $c = self::e($code);

        return $this->page('Adresses', <<<HTML
{$err}{$note}
<form method="post" action="/installation">
  <input type="hidden" name="step" value="config"><input type="hidden" name="code" value="{$c}">
  <label>Adresse de l’application<input name="app_url" required value="{$appUrl}"></label>
  <p class="small">Celle que vous tapez pour ouvrir belprise, en https. Elle sert aux liens des e-mails.</p>
  <label>Expéditeur des e-mails<input name="mail_from" required value="{$mailFrom}"></label>
  <p class="small">Une adresse de votre domaine, pour que les e-mails ne finissent pas en indésirables (SPF, DKIM).</p>
  <label>Envoyer un e-mail de test à<input name="test_to" type="email" value="{$testTo}"></label>
  <div class="actions">
    <button type="submit" name="action" value="next">Continuer</button>
    <button type="submit" name="action" value="test" class="secondary">Envoyer le test</button>
  </div>
</form>
HTML, 200, 2);
    }

    // ---- 3-4. Operator account and its two-factor authentication -------------------------

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $state
     */
    private function postAccount(array $data, string $code, array $state): Response
    {
        try {
            $email = AdminAuth::validateEmail((string) ($data['email'] ?? ''));
            $name = AdminAuth::validateName((string) ($data['name'] ?? ''));
            AdminAuth::validatePassword((string) ($data['password'] ?? ''), (string) ($data['confirmation'] ?? ''));
        } catch (HttpException $e) {
            return $this->accountPage($code, ['email' => $data['email'] ?? '', 'name' => $data['name'] ?? ''], $e->getMessage(), 422);
        }
        $totp = Totp::generateSecret();
        $this->saveState(['stage' => 'totp', 'email' => $email, 'name' => $name, 'hash' => password_hash((string) $data['password'], PASSWORD_ARGON2ID), 'totp' => $totp] + $state);

        return $this->totpPage($email, $totp, $code);
    }

    /** @param array<string, mixed> $values */
    private function accountPage(string $code, array $values, string $error, int $status = 200): Response
    {
        $err = $error === '' ? '' : '<p class="error" role="alert">' . self::e($error) . '</p>';
        $c = self::e($code);
        $email = self::e((string) ($values['email'] ?? ''));
        $name = self::e((string) ($values['name'] ?? ''));

        return $this->page('Compte d’exploitation', <<<HTML
<p>Le super-administrateur de ce serveur : il crée les organisations, fixe leur quota et règle l’installation. Il ne voit jamais leurs contenus.</p>
{$err}
<form method="post" action="/installation">
  <input type="hidden" name="step" value="account"><input type="hidden" name="code" value="{$c}">
  <label>E-mail<input name="email" type="email" required autocomplete="username" value="{$email}"></label>
  <label>Prénom et nom<input name="name" required autocomplete="name" value="{$name}"></label>
  <label>Mot de passe (12 caractères au moins)<input name="password" type="password" required autocomplete="new-password"></label>
  <label>Confirmer le mot de passe<input name="confirmation" type="password" required autocomplete="new-password"></label>
  <p class="small">En cas d’oubli, seule une commande sur le serveur le réinitialise : <code>php app/bin/reset-password.php</code>. Notez-le dans votre gestionnaire de mots de passe.</p>
  <button type="submit">Continuer</button>
</form>
HTML, $status, 3);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $state
     */
    private function postTotp(array $data, string $code, array $state): Response
    {
        if (!isset($state['totp'])) {
            throw new HttpException(409, 'Étape manquante : reprenez l’installation depuis le début.');
        }
        $step = Totp::verify((string) $state['totp'], (string) ($data['totp'] ?? ''), time());
        if ($step === null) {
            return $this->totpPage((string) $state['email'], (string) $state['totp'], $code, 'Code incorrect : vérifiez l’heure du téléphone et réessayez.');
        }
        $this->saveState(['stage' => 'organization', 'totp_step' => $step] + $state);

        return $this->organizationPage($code, [], '');
    }

    private function totpPage(string $email, string $secret, string $code, string $error = ''): Response
    {
        $key = self::e(implode(' ', str_split($secret, 4)));
        $uri = self::e(Totp::uri($secret, $email));
        $err = $error === '' ? '' : '<p class="error" role="alert">' . self::e($error) . '</p>';
        $c = self::e($code);

        return $this->page('Double authentification', <<<HTML
<p>Le compte d’exploitation est toujours protégé par un code à usage unique. Dans votre application d’authentification (Google Authenticator, 1Password, Authy…), ajoutez un compte avec cette clé :</p>
<p class="key">{$key}</p>
<p class="small">ou cette adresse : <code>{$uri}</code></p>
{$err}
<form method="post" action="/installation">
  <input type="hidden" name="step" value="totp"><input type="hidden" name="code" value="{$c}">
  <label>Code à 6 chiffres affiché par l’application<input name="totp" inputmode="numeric" autocomplete="one-time-code" required maxlength="7" autofocus></label>
  <button type="submit">Continuer</button>
</form>
HTML, 200, 4);
    }

    // ---- 5. First organization (optional) ------------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $state
     */
    private function postOrganization(array $data, string $code, array $state): Response
    {
        $organization = null;
        if (($data['action'] ?? '') !== 'skip') {
            $values = ['name' => trim((string) ($data['name'] ?? '')), 'admin' => trim((string) ($data['admin'] ?? '')), 'quota' => trim((string) ($data['quota'] ?? ''))];
            try {
                $name = AdminAuth::validateOrganizationName($values['name']);
                $admin = AdminAuth::validateEmail($values['admin']);
            } catch (HttpException $e) {
                return $this->organizationPage($code, $values, $e->getMessage());
            }
            if (mb_strtolower($admin) === mb_strtolower((string) $state['email'])) {
                return $this->organizationPage($code, $values, 'Choisissez une autre adresse que celle du compte d’exploitation : l’administrateur de l’organisation a son propre compte.');
            }
            $quota = null;
            if ($values['quota'] !== '') {
                if (preg_match('/^[1-9][0-9]{0,6}$/D', $values['quota']) !== 1) {
                    return $this->organizationPage($code, $values, 'Quota : un nombre entier de Go, ou vide pour « sans limite ».');
                }
                $quota = (int) $values['quota'] * 1_000_000_000;
            }
            $organization = ['name' => $name, 'admin' => $admin, 'quota' => $quota];
        }
        $this->saveState(['stage' => 'encoding', 'organization' => $organization] + $state);

        return $this->encodingPage($code, '');
    }

    // ---- 6. Encoding of the playback versions ----------------------------------------------

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $state
     */
    private function postEncoding(array $data, string $code, array $state): Response
    {
        $mode = (string) ($data['mode'] ?? 'browser');
        if (!in_array($mode, ServerEncoder::MODES, true)) {
            $mode = 'browser';
        }
        if ($mode === 'server' && $this->ffmpeg() === null) {
            return $this->encodingPage($code, 'FFmpeg est introuvable sur ce serveur : l’encodage ne peut se faire que dans le navigateur.');
        }
        $state = ['stage' => 'summary', 'encoding' => $mode] + $state;
        $this->saveState($state);

        return $this->summaryPage($code, $state);
    }

    private function encodingPage(string $code, string $error): Response
    {
        $err = $error === '' ? '' : '<p class="error" role="alert">' . self::e($error) . '</p>';
        $c = self::e($code);
        $ffmpeg = $this->ffmpeg();
        $server = $ffmpeg === null
            ? '<label class="choice disabled"><input type="radio" name="mode" value="server" disabled> <strong>Sur le serveur</strong> — FFmpeg est introuvable sur ce serveur</label>'
            : '<label class="choice"><input type="radio" name="mode" value="server"> <strong>Sur le serveur</strong> — en arrière-plan, l’administrateur peut fermer la page après l’envoi ; ProRes accepté sans fichier joint (' . self::e($ffmpeg['version']) . ')</label>';

        return $this->page('Versions de lecture', <<<HTML
<p>Les clients lisent une version 720p de chaque vidéo, préparée après l’envoi du master. Où la préparer ?</p>
{$err}
<form method="post" action="/installation">
  <input type="hidden" name="step" value="encoding"><input type="hidden" name="code" value="{$c}">
  <label class="choice"><input type="radio" name="mode" value="browser" checked> <strong>Dans le navigateur</strong> — aucun calcul sur le serveur ; l’administrateur garde la page ouverte pendant l’optimisation</label>
  {$server}
  <p class="small">Sur un hébergement mutualisé, préférez le navigateur : le serveur y est souvent limité à un processeur. Le choix se change à tout moment dans l’exploitation.</p>
  <button type="submit">Continuer</button>
</form>
HTML, 200, 6);
    }

    /** @return array{path: string, version: string, probe: string|null}|null */
    private function ffmpeg(): ?array
    {
        return Environment::ffmpeg();
    }

    /** @param array<string, string> $values */
    private function organizationPage(string $code, array $values, string $error): Response
    {
        $err = $error === '' ? '' : '<p class="error" role="alert">' . self::e($error) . '</p>';
        $c = self::e($code);
        $name = self::e($values['name'] ?? '');
        $admin = self::e($values['admin'] ?? '');
        $quota = self::e($values['quota'] ?? '');

        return $this->page('Première organisation', <<<HTML
<p>Une organisation : votre agence, votre service vidéo… Son administrateur reçoit une invitation par e-mail et gère ensuite son équipe. Vous pourrez en créer d’autres depuis l’exploitation.</p>
{$err}
<form method="post" action="/installation">
  <input type="hidden" name="step" value="organization"><input type="hidden" name="code" value="{$c}">
  <label>Nom de l’organisation<input name="name" value="{$name}" autocomplete="organization"></label>
  <label>E-mail de son administrateur<input name="admin" type="email" value="{$admin}"></label>
  <label>Quota en Go (vide : sans limite)<input name="quota" inputmode="numeric" value="{$quota}" placeholder="Sans limite"></label>
  <div class="actions">
    <button type="submit" name="action" value="create">Continuer</button>
    <button type="submit" name="action" value="skip" class="secondary" formnovalidate>Plus tard</button>
  </div>
</form>
HTML, 200, 5);
    }

    // ---- 6. Summary, then everything is written ---------------------------------------------

    /** @param array<string, mixed> $state */
    private function summaryPage(string $code, array $state): Response
    {
        $c = self::e($code);
        $config = $state['config'];
        $org = $state['organization'] ?? null;
        $orgLine = $org === null
            ? 'aucune pour l’instant (à créer depuis l’exploitation)'
            : self::e($org['name']) . ' — administrateur invité : ' . self::e($org['admin']) . ' — quota : ' . self::e(Quota::label($org['quota']));

        return $this->page('Résumé', <<<HTML
<dl class="summary">
  <dt>Adresse</dt><dd>{$this->escaped($config['app_url'])}</dd>
  <dt>Expéditeur</dt><dd>{$this->escaped($config['mail_from'])}</dd>
  <dt>Exploitation</dt><dd>{$this->escaped($state['name'])} — {$this->escaped($state['email'])}, double authentification active</dd>
  <dt>Organisation</dt><dd>{$orgLine}</dd>
  <dt>Versions de lecture</dt><dd>{$this->escaped(($state['encoding'] ?? 'browser') === 'server' ? 'encodées sur le serveur' : 'encodées dans le navigateur')}</dd>
</dl>
<form method="post" action="/installation">
  <input type="hidden" name="step" value="summary"><input type="hidden" name="code" value="{$c}">
  <button type="submit">Installer belprise</button>
</form>
HTML, 200, 7);
    }

    /** @param array<string, mixed> $state */
    private function finish(array $state): Response
    {
        if (($state['stage'] ?? '') !== 'summary') {
            throw new HttpException(409, 'Étape manquante : reprenez l’installation depuis le début.');
        }
        $secret = bin2hex(random_bytes(32));
        $inData = $this->configFile !== $this->rootDir . '/app/config.php';
        $php = "<?php\n\ndeclare(strict_types=1);\n\n// Written by the installer (" . gmdate('Y-m-d') . "). Keep a copy of the secret off the server.\nreturn [\n"
            . "    'data_dir' => " . ($inData ? '__DIR__' : "__DIR__ . '/../data'") . ",\n"
            . "    'secret' => " . var_export($secret, true) . ",\n"
            . "    'xsendfile' => false,\n"
            . "    'hsts' => false,\n"
            . "    'trusted_proxies' => [],\n"
            . "    'app_url' => " . var_export($state['config']['app_url'], true) . ",\n"
            . "    'mail_from' => " . var_export($state['config']['mail_from'], true) . ",\n"
            . "];\n";
        if (@file_put_contents($this->configFile, $php) === false) {
            return $this->page('Dernière étape à faire à la main', '<p>Le serveur ne peut pas écrire <code>' . self::e($this->relative($this->configFile)) . '</code>. Créez ce fichier avec le contenu ci-dessous (droits 600), puis rechargez cette page.</p><pre>' . self::e($php) . '</pre>');
        }
        @chmod($this->configFile, 0600);
        $config = Config::load($this->configFile);
        DataDir::prepare($config->dataDir);
        $db = Database::open($config->dataDir . '/belprise.sqlite', $this->rootDir . '/app/migrations');
        $superadmins = new Superadmins($db);
        if ($superadmins->findByEmail((string) $state['email']) === null) {
            $superadmins->create((string) $state['email'], (string) $state['name'], (string) $state['hash'], (new SecretBox($config->secret, 'totp'))->seal((string) $state['totp']), (int) $state['totp_step']);
        }

        (new Settings($db))->set(ServerEncoder::MODE_KEY, ($state['encoding'] ?? 'browser') === 'server' ? 'server' : 'browser');

        $invitation = '';
        $org = $state['organization'] ?? null;
        if ($org !== null) {
            $id = (new Organizations($db))->create((string) $org['name'], $org['quota'] === null ? null : (int) $org['quota']);
            $token = (new Invitations($db))->create($id, (string) $org['admin'], 'admin', null, time())['token'];
            $details = 'Quota ' . Quota::label($org['quota'] === null ? null : (int) $org['quota']) . ', administrateur invité : ' . $org['admin'];
            (new AuditLog($db))->add($id, 'Installation', 'Organisation créée', $details);
            (new PlatformLog($db))->add($id, (string) $org['name'], 'Installation', 'Organisation créée', $details);
            $link = $config->appUrl . '/invitation/' . $token;
            $sent = $this->mailer($config->mailFrom)->send(new Message(
                (string) $org['admin'],
                'Votre espace belprise « ' . $org['name'] . ' » est prêt',
                "Bonjour,\n\nL’espace « " . $org['name'] . " » vient d’être ouvert pour vous sur belprise.\n\n"
                . "Vous en êtes l’administrateur. Pour créer votre compte, ouvrez ce lien (valable 7 jours, une seule fois) :\n"
                . $link . "\n\n--\nBelprise, propulsé par belprod — https://belprod.fr\n",
            ));
            $invitation = '<p>' . ($sent
                ? 'Invitation envoyée à ' . self::e((string) $org['admin']) . '.'
                : 'L’e-mail d’invitation n’a pas pu partir : transmettez ce lien (7 jours, une seule fois) à ' . self::e((string) $org['admin']) . ' :</p><p class="key small">' . self::e($link)) . '</p>';
        }
        @unlink($this->dataDir . '/' . self::STATE_FILE);
        @unlink($this->dataDir . '/' . self::CODE_FILE);
        @unlink($this->dataDir . '/installation-attempts.json');

        $server = ($state['encoding'] ?? 'browser') === 'server';
        $cron = self::e(self::cronLine($this->rootDir, $server));
        $task = getenv('BELPRISE_DOCKER') === '1'
            ? '<li><strong>La tâche planifiée</strong> (archivage, rappels, purges…) tourne déjà dans le conteneur, toutes les 5 minutes : rien à faire.</li>'
            : '<li><strong>La tâche planifiée</strong> (archivage, rappels, purges' . ($server ? ', encodage des versions de lecture' : '') . '), '
                . ($server ? 'toutes les 5 minutes' : 'une fois par heure') . '. Dans cPanel : « Tâches Cron », puis cette commande :<p class="key small">' . $cron . '</p></li>';

        return $this->page('Installation terminée', <<<HTML
<p>belprise est installé.</p>
{$invitation}
<h2>À faire maintenant</h2>
<ol>
  {$task}
  <li><strong>Une copie de la configuration</strong> (<code>{$this->escaped($this->relative($this->configFile))}</code>) hors du serveur : sa clé secrète protège la double authentification.</li>
  <li>Une fois le HTTPS vérifié, activez <code>'hsts' => true</code> dans la configuration.</li>
</ol>
<p><a class="button" href="/superadmin">Se connecter à l’exploitation</a></p>
HTML);
    }

    /** The line for the host's scheduled tasks: hourly, or every 5 minutes when the server encodes. */
    public static function cronLine(string $rootDir, bool $serverEncoding = false): string
    {
        return ($serverEncoding ? '*/5 * * * *' : '0 * * * *') . ' php ' . (realpath($rootDir) ?: $rootDir) . '/app/bin/cron.php > /dev/null 2>&1';
    }

    private function mailer(string $from): Mailer
    {
        return $this->mailer ?? new NativeMailer($from);
    }

    private function relative(string $file): string
    {
        return str_starts_with($file, $this->rootDir . '/') ? substr($file, strlen($this->rootDir) + 1) : $file;
    }

    private function escaped(mixed $value): string
    {
        return self::e((string) $value);
    }

    // ---- Unlock code and state ---------------------------------------------------------------

    /** The unlock code, created on first visit. */
    private function code(): string
    {
        $file = $this->dataDir . '/' . self::CODE_FILE;
        if (!is_file($file)) {
            $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
            $code = '';
            for ($i = 0; $i < 10; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            file_put_contents($file, substr($code, 0, 5) . '-' . substr($code, 5) . "\n");
            @chmod($file, 0600);
        }

        return trim((string) file_get_contents($file));
    }

    /**
     * Wrong codes are counted per network: after MAX_ATTEMPTS, that network waits
     * LOCK_SECONDS. The code itself never changes, so nobody else can make the
     * owner's copy useless.
     */
    private function checkCode(string $given, string $network): void
    {
        if (!is_dir($this->dataDir) || !is_file($this->dataDir . '/' . self::CODE_FILE)) {
            throw new HttpException(403, 'Code de déverrouillage introuvable : rechargez la page d’installation.');
        }
        $attemptsFile = $this->dataDir . '/installation-attempts.json';
        $attempts = json_decode((string) @file_get_contents($attemptsFile), true);
        $attempts = is_array($attempts) ? $attempts : [];
        $now = time();
        // Old entries are forgotten.
        $attempts = array_filter($attempts, static fn ($a) => is_array($a) && $now - (int) ($a[1] ?? 0) < self::LOCK_SECONDS);
        $key = hash('sha256', $network);
        [$count] = $attempts[$key] ?? [0, 0];
        if ($count >= self::MAX_ATTEMPTS) {
            throw new HttpException(429, 'Trop de codes incorrects depuis ce réseau : réessayez dans 15 minutes.');
        }
        $normalize = static fn (string $c): string => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c) ?? '');
        if (!hash_equals($normalize($this->code()), $normalize($given))) {
            $attempts[$key] = [$count + 1, $now];
            file_put_contents($attemptsFile, json_encode($attempts), LOCK_EX);
            @chmod($attemptsFile, 0600);
            throw new HttpException(403, 'Code de déverrouillage incorrect.');
        }
        unset($attempts[$key]);
        file_put_contents($attemptsFile, json_encode($attempts), LOCK_EX);
    }

    /** @param array<string, mixed> $state */
    private function saveState(array $state): void
    {
        $file = $this->dataDir . '/' . self::STATE_FILE;
        file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));
        @chmod($file, 0600);
    }

    /** @return array<string, mixed>|null */
    private function state(): ?array
    {
        $raw = @file_get_contents($this->dataDir . '/' . self::STATE_FILE);
        $state = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($state) ? $state : null;
    }

    private function page(string $title, string $content, int $status = 200, ?int $step = null): Response
    {
        $title = self::e($title);
        $progress = $step === null ? '' : '<p class="progress">Étape ' . $step . ' sur ' . self::TOTAL_STEPS . '</p>';
        $html = <<<HTML
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$title} — installation de belprise</title>
<style nonce="{{nonce}}">
  body { margin: 0; background: #0a0a0b; color: #fafafa; font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
  main { max-width: 580px; margin: 0 auto; padding: 48px 20px; }
  h1 { font-size: 28px; font-weight: 500; margin: 0 0 24px; }
  h2 { font-size: 16px; margin: 28px 0 8px; }
  .brand { color: #9a9aa0; font-weight: 700; } .brand span { color: #ce3c4a; }
  .progress { margin: -16px 0 24px; color: #9a9aa0; font-size: 13px; font-family: ui-monospace, Menlo, monospace; }
  label { display: block; margin: 14px 0 4px; color: #e4e4e7; font-size: 14px; }
  input { display: block; box-sizing: border-box; width: 100%; margin-top: 6px; padding: 10px 12px; border: 1px solid #303035; border-radius: 10px; background: #141416; color: #fafafa; font-size: 16px; }
  .actions { display: flex; flex-wrap: wrap; gap: 12px; }
  button, .button { display: inline-block; margin-top: 20px; padding: 12px 22px; border: 1px solid transparent; border-radius: 999px; background: #e8515f; color: #0a0a0b; font-weight: 600; font-size: 15px; text-decoration: none; cursor: pointer; }
  button.secondary { border-color: #303035; background: transparent; color: #fafafa; }
  code, .key { font-family: ui-monospace, Menlo, monospace; overflow-wrap: anywhere; }
  .key { font-size: 20px; letter-spacing: 1px; padding: 12px 14px; border: 1px solid #303035; border-radius: 10px; }
  .key.small { font-size: 13px; letter-spacing: 0; }
  .small { margin: 4px 0 0; font-size: 13px; color: #9a9aa0; }
  .error { padding: 10px 14px; border-radius: 10px; background: rgb(232 81 95 / 0.12); color: #ff8a95; }
  .notice { padding: 10px 14px; border-radius: 10px; background: rgb(46 229 157 / 0.1); color: #2ee59d; }
  .choice { display: block; position: relative; padding: 12px 14px 12px 38px; border: 1px solid #303035; border-radius: 10px; } .choice input { position: absolute; left: 14px; top: 15px; width: auto; margin: 0; } .choice.disabled { color: #9a9aa0; }
  .checks { padding-left: 18px; font-size: 14px; } .ok { color: #2ee59d; } .ko { color: #ff6b6b; } .warn { color: #ffd60a; }
  .summary { display: grid; grid-template-columns: max-content 1fr; gap: 8px 16px; } .summary dt { color: #9a9aa0; } .summary dd { margin: 0; overflow-wrap: anywhere; }
  pre { overflow: auto; padding: 12px; border: 1px solid #303035; border-radius: 10px; font-size: 13px; }
  summary { cursor: pointer; color: #2ee59d; }
  ol li { margin-bottom: 12px; }
</style>
</head>
<body><main><p class="brand">belprise<span>.</span> · installation</p><h1>{$title}</h1>{$progress}{$content}</main></body>
</html>
HTML;

        return Response::html($html, $status);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
