<?php

declare(strict_types=1);

// Demo data: client "Maison Aurore", 2 projects, 5 videos (no files yet).
// Usage: php app/bin/seed.php [base URL, default http://localhost:8080]

use Belprise\Config;
use Belprise\Database\Database;
use Belprise\DataDir;
use Belprise\Repository\Clients;
use Belprise\Repository\Links;
use Belprise\Repository\Organizations;
use Belprise\Repository\Projects;
use Belprise\Repository\Tags;
use Belprise\Repository\Videos;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

$baseUrl = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$config = Config::load(Config::file(dirname(__DIR__, 2)));
DataDir::prepare($config->dataDir);
$db = Database::open($config->dataDir . '/belprise.sqlite', dirname(__DIR__) . '/migrations');

$exists = $db->prepare('SELECT 1 FROM clients WHERE name = ?');
$exists->execute(['Maison Aurore']);
if ($exists->fetchColumn() !== false) {
    fwrite(STDERR, "Le client « Maison Aurore » existe déjà : rien n'a été ajouté.\n");
    exit(1);
}

// Demo data goes to the first organization (created if there is none yet).
$organization = (int) ($db->query('SELECT MIN(id) FROM organizations')->fetchColumn() ?: (new Organizations($db))->create('belprod'));
$clients = new Clients($db, $organization);
$projects = new Projects($db);
$videos = new Videos($db);
$tags = new Tags($db);
$links = new Links($db);

$demo = [
    'Collection printemps 2027' => [
        ['Film manifeste 60 s', ['pub', 'manifeste']],
        ['Déclinaison 15 s', ['pub']],
        ['Déclinaison 6 s réseaux sociaux', ['pub', 'réseaux sociaux']],
    ],
    'Portrait de la maison' => [
        ['Interview de la fondatrice', ['corporate', 'interview']],
        ['Making-of à l’atelier', ['corporate', 'making-of']],
    ],
];

$output = Database::transaction($db, function () use ($clients, $projects, $videos, $tags, $links, $demo, $baseUrl): array {
    $clientId = $clients->create([
        'name' => 'Maison Aurore',
        'bg_color' => '#f3ede4',
        'text_color' => '#1c1915',
        'accent_color' => '#a8481f',
        'welcome_text' => "Bienvenue dans votre espace belprise.\nRetrouvez ici les films de la collection printemps et du portrait de la maison.",
    ]);
    $lines = ['Espace client  ' . $baseUrl . '/' . $links->create('space', $clientId)['key']];

    foreach ($demo as $projectName => $projectVideos) {
        $projectId = $projects->create($clientId, $projectName);
        foreach ($projectVideos as [$title, $videoTags]) {
            $videoId = $videos->create($projectId, $title, true);
            $tags->sync($videoId, $videoTags);
            $number = (int) $videos->find($videoId)['number'];
            $client = $links->create('client', $videoId);
            $view = $links->create('view', $videoId);
            $lines[] = sprintf(
                "N° %d  %s\n        Client          %s/v/%d%s\n        Visualisation   %s/v/%d%s",
                $number,
                $title,
                $baseUrl,
                $number,
                $client['key'],
                $baseUrl,
                $number,
                $view['key'],
            );
        }
    }

    return $lines;
});

echo "Données de démo créées : Maison Aurore, 2 projets, 5 vidéos.\n\n" . implode("\n", $output) . "\n";
