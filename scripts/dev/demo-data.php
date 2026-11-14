<?php

declare(strict_types=1);

// Loads the demo data (scripts/dev/demo-data.json) with the media made by
// scripts/dev/demo_media.py. Development only; run through scripts/dev/demo-data.sh.
// --reset first removes the demo clients (only those named in demo-data.json).

use Belprise\Config;
use Belprise\Database\Database;
use Belprise\DataDir;
use Belprise\LogoStore;
use Belprise\Media\VersionImporter;
use Belprise\Repository\Clients;
use Belprise\Repository\Links;
use Belprise\Repository\Organizations;
use Belprise\Repository\Projects;
use Belprise\Repository\Tags;
use Belprise\Repository\Versions;
use Belprise\Repository\Videos;
use Belprise\Storage;
use Belprise\Support\Dates;
use Belprise\Timecode;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/app/bootstrap.php';

$args = array_slice($argv, 1);
$reset = in_array('--reset', $args, true);
$baseUrl = rtrim(array_values(array_diff($args, ['--reset']))[0] ?? 'http://localhost:8080', '/');
$config = Config::load($root . '/app/config.php');
DataDir::prepare($config->dataDir);
$db = Database::open($config->dataDir . '/belprise.sqlite', $root . '/app/migrations');
$storage = new Storage($config->dataDir);
$cache = $config->dataDir . '/demo-cache';

$data = json_decode((string) file_get_contents(__DIR__ . '/demo-data.json'), true, 64, JSON_THROW_ON_ERROR);
$manifest = json_decode((string) file_get_contents($cache . '/manifest.json'), true, 16, JSON_THROW_ON_ERROR);

// Check everything before writing anything.
foreach ($data['clients'] as $ci => $c) {
    foreach ($c['projects'] as $pi => $p) {
        foreach ($p['videos'] as $vi => $v) {
            foreach ($v['versions'] as $ri => $r) {
                $media = $manifest["c{$ci}p{$pi}v{$vi}r{$ri}"] ?? null;
                $m = $media['meta'] ?? null;
                if ($media === null || !is_file($cache . '/' . $media['file'])) {
                    exit("Média manquant pour « {$v['title']} » V" . ($ri + 1) . " : relancez scripts/dev/demo-data.sh.\n");
                }
                if (Timecode::toFrames($m['startTimecode'], $m['fpsNum'], $m['fpsDen'], $m['dropFrame']) === null) {
                    exit("Timecode {$m['startTimecode']} invalide pour « {$v['title']} » : corrigez demo-data.json.\n");
                }
            }
        }
    }
}

$findClients = $db->prepare('SELECT id, logo FROM clients WHERE name = ?');
if ($reset) {
    // Same clean-up as deleting a client in the admin, for the demo clients only.
    foreach ($data['clients'] as $c) {
        $findClients->execute([$c['name']]);
        foreach ($findClients->fetchAll() as $row) {
            $id = (int) $row['id'];
            Database::transaction($db, function () use ($db, $id): void {
                $videoIds = $db->prepare('SELECT v.id FROM videos v JOIN projects p ON p.id = v.project_id WHERE p.client_id = ?');
                $videoIds->execute([$id]);
                $ids = array_map('intval', $videoIds->fetchAll(PDO::FETCH_COLUMN));
                $links = new Links($db);
                $links->deleteFor('space', [$id]);
                $links->deleteFor('client', $ids);
                $links->deleteFor('view', $ids);
                (new Clients($db))->delete($id);
                (new Tags($db))->pruneUnused();
            });
            $storage->deleteFile($row['logo']);
            $storage->deleteTree(Storage::clientMediaDir($id));
            echo "Supprimé : {$c['name']}\n";
        }
    }
}

foreach ($data['clients'] as $client) {
    $findClients->execute([$client['name']]);
    if ($findClients->fetchColumn() !== false) {
        fwrite(STDERR, "Le client « {$client['name']} » existe déjà : relancez avec --reset pour recharger les données de démo.\n");
        exit(1);
    }
}

// Demo data goes to the first organization (created if there is none yet).
$organization = (int) ($db->query('SELECT MIN(id) FROM organizations')->fetchColumn() ?: (new Organizations($db))->create('belprod'));
$clients = new Clients($db, $organization);
$projects = new Projects($db);
$videos = new Videos($db);
$tags = new Tags($db);
$links = new Links($db);
$versions = new Versions($db);
$importer = new VersionImporter($db, $versions, $storage);
$logos = new LogoStore($storage);

$ago = static fn (float $days): string => gmdate('Y-m-d H:i:s', (int) (time() - $days * 86400 - random_int(0, 6 * 3600)));
$setDate = static function (string $table, int $id, string $date) use ($db): void {
    $db->prepare("UPDATE {$table} SET created_at = ?, updated_at = ? WHERE id = ?")->execute([$date, $date, $id]);
};
$linkOptions = static function (array $link, array $options) use ($links): void {
    $fields = [];
    if (isset($options['password'])) {
        $fields['password_hash'] = password_hash($options['password'], PASSWORD_ARGON2ID);
    }
    if (isset($options['expiresOn'])) {
        $fields['expires_at'] = Dates::localDateToExpiry($options['expiresOn']);
    }
    if (isset($options['active'])) {
        $fields['is_active'] = $options['active'] ? 1 : 0;
    }
    $links->update((int) $link['id'], $fields);
};
$insertComment = $db->prepare(
    'INSERT INTO comments (version_id, parent_id, author_name, is_admin, frame_in, frame_out, body, status,
        is_read_by_admin, created_at, updated_at)
     VALUES (:version, :parent, :author, :admin, :in, :out, :body, :status, :read, :date, :date)'
);

$summary = [];
$counts = ['clients' => 0, 'projects' => 0, 'videos' => 0, 'versions' => 0, 'comments' => 0];

foreach ($data['clients'] as $ci => $c) {
    [$bg, $text, $accent] = $c['colors'];
    $clientId = $clients->create(['name' => $c['name'], 'bg_color' => $bg, 'text_color' => $text, 'accent_color' => $accent, 'welcome_text' => $c['welcome']]);
    $setDate('clients', $clientId, $ago($c['daysAgo']));
    $space = $links->create('space', $clientId);
    if ($c['logo'] !== null) {
        $tmp = tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($tmp, $c['logo']);
        $clients->setLogo($clientId, $logos->store($clientId, ['name' => 'logo.svg', 'tmp_name' => $tmp, 'size' => strlen($c['logo']), 'error' => UPLOAD_ERR_OK]));
        unlink($tmp);
    }
    if ($c['archived'] ?? false) {
        $clients->setArchived($clientId, true);
    }
    $counts['clients']++;
    $summary[] = $c['name'] . str_repeat(' ', max(1, 30 - mb_strlen($c['name']))) . ' espace client  ' . $baseUrl . '/' . $space['key']
        . (($c['archived'] ?? false) ? '  (archivé)' : '');

    foreach ($c['projects'] as $pi => $p) {
        $projectId = $projects->create($clientId, $p['name']);
        $setDate('projects', $projectId, $ago($p['daysAgo']));
        if ($p['archived'] ?? false) {
            $projects->setArchived($projectId, true);
        }
        $counts['projects']++;

        foreach ($p['videos'] as $vi => $v) {
            $videoId = Database::transaction($db, function () use ($videos, $tags, $links, $projectId, $v): int {
                $id = $videos->create($projectId, $v['title'], $v['download'] ?? true);
                $tags->sync($id, $v['tags']);
                $links->create('client', $id);
                $links->create('view', $id);

                return $id;
            });
            $setDate('videos', $videoId, $ago($v['daysAgo']));
            foreach (['client', 'view'] as $type) {
                if (isset($v['links'][$type])) {
                    $linkOptions($links->forTarget($type, $videoId), $v['links'][$type]);
                }
            }
            $video = $videos->find($videoId);
            $counts['videos']++;

            foreach ($v['versions'] as $ri => $r) {
                $media = $manifest["c{$ci}p{$pi}v{$vi}r{$ri}"];
                $m = $media['meta'];
                $meta = [
                    'codec' => $m['codec'], 'width' => $m['width'], 'height' => $m['height'],
                    'fps_num' => $m['fpsNum'], 'fps_den' => $m['fpsDen'], 'drop_frame' => $m['dropFrame'] ? 1 : 0,
                    'start_frame' => Timecode::toFrames($m['startTimecode'], $m['fpsNum'], $m['fpsDen'], $m['dropFrame']),
                    'duration_frames' => $m['durationFrames'], 'bit_rate' => $m['bitRate'], 'timecode_source' => 'tmcd',
                ];
                $slug = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $v['title'])) ?: 'Video';
                // The importer moves the file: give it a copy, the cache stays.
                $copy = $cache . '/import-' . bin2hex(random_bytes(6)) . '.mp4';
                copy($cache . '/' . $media['file'], $copy);
                $versionId = $importer->import($video, $copy, trim($slug, '_') . '_V' . ($ri + 1) . '_master.mp4', $meta);
                $row = $versions->find($versionId);
                $dir = Storage::versionDir((int) $row['client_id'], (int) $row['video_number'], (int) $row['number']);
                copy($cache . '/' . $media['poster'], $storage->absolute($dir . '/poster.jpg'));
                copy($cache . '/' . $media['sprite'], $storage->absolute($dir . '/sprite.jpg'));
                if (isset($media['thumb'])) {
                    copy($cache . '/' . $media['thumb'], $storage->absolute($dir . '/thumb.jpg'));
                }

                $created = $ago($r['daysAgo']);
                $published = $r['status'] !== 'in_progress';
                $versions->update($versionId, [
                    'status' => $r['status'],
                    'is_published' => $published ? 1 : 0,
                    'published_at' => $published ? $created : null,
                    'approved_by' => $r['approvedBy'] ?? null,
                    'approved_at' => isset($r['approvedDaysAgo']) ? $ago($r['approvedDaysAgo']) : null,
                    'poster_path' => $dir . '/poster.jpg',
                    'poster_frame' => $media['posterFrame'],
                    'thumb_path' => isset($media['thumb']) ? $dir . '/thumb.jpg' : null,
                    'sprite_path' => $dir . '/sprite.jpg',
                    'sprite_meta' => json_encode($media['spriteMeta'], JSON_THROW_ON_ERROR),
                ]);
                $setDate('versions', $versionId, $created);
                $counts['versions']++;

                $frame = static fn (float $seconds): int => (int) round($seconds * $m['fpsNum'] / $m['fpsDen']);
                foreach ($r['comments'] ?? [] as $comment) {
                    $date = $ago($comment['daysAgo']);
                    $insertComment->execute([
                        'version' => $versionId, 'parent' => null, 'author' => $comment['author'], 'admin' => 0,
                        'in' => $frame($comment['at']), 'out' => isset($comment['out']) ? $frame($comment['out']) : null,
                        'body' => $comment['text'], 'status' => $comment['status'],
                        'read' => ($comment['unread'] ?? false) ? 0 : 1, 'date' => $date,
                    ]);
                    $parent = (int) $db->lastInsertId();
                    $counts['comments']++;
                    foreach ($comment['replies'] ?? [] as $reply) {
                        $insertComment->execute([
                            'version' => $versionId, 'parent' => $parent,
                            'author' => ($reply['admin'] ?? false) ? 'belprod.' : $reply['author'],
                            'admin' => ($reply['admin'] ?? false) ? 1 : 0,
                            'in' => $frame($comment['at']), 'out' => null, 'body' => $reply['text'], 'status' => 'todo',
                            'read' => 1, 'date' => $date,
                        ]);
                        $counts['comments']++;
                    }
                }
            }
        }
    }
}

printf(
    "Données de démo chargées : %d clients, %d projets, %d vidéos, %d versions, %d commentaires.\n\n%s\n",
    $counts['clients'], $counts['projects'], $counts['videos'], $counts['versions'], $counts['comments'],
    implode("\n", $summary),
);
