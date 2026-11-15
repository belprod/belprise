<?php

declare(strict_types=1);

namespace Belprise\Demo;

use Belprise\LogoStore;
use Belprise\Media\MediaProbe;
use Belprise\Media\ServerEncoder;
use Belprise\Media\VersionImporter;
use Belprise\Repository\Clients;
use Belprise\Repository\Collections;
use Belprise\Repository\Comments;
use Belprise\Repository\EncodeJobs;
use Belprise\Repository\Links;
use Belprise\Repository\Organizations;
use Belprise\Repository\Projects;
use Belprise\Repository\Tags;
use Belprise\Repository\Users;
use Belprise\Repository\Versions;
use Belprise\Repository\Videos;
use Belprise\Review\CommentService;
use Belprise\Storage;
use Belprise\Support\Input;
use PDO;

/**
 * The demo set (§5.16): a fictitious production company, its team (the shared
 * accounts), four fictitious clients, projects with several versions,
 * revisions, statuses, a collection and active links. The videos are the
 * extracts written by scripts/demo-media.sh; their playback versions and
 * images are made here by FFmpeg, as the server encoding does.
 */
final class DemoBuilder
{
    public const ORGANIZATION = 'Studio Lucarne';

    /**
     * Revisions: [seconds in, seconds out or null, author, text, status, reply or null, hours ago].
     * A reply: [author, text].
     */
    private const CLIENTS = [
        [
            'name' => 'Maison Aurore',
            'colors' => ['#f3ede4', '#1c1915', '#a8481f'],
            'welcome' => "Bienvenue dans votre espace de validation.\nRetrouvez ici les films de la campagne de printemps.",
            'logo' => ['Aurore', '#a8481f', 'Georgia, serif'],
            'projects' => [
                'Campagne printemps' => [
                    [
                        'title' => 'Film manifeste 30 s', 'tags' => ['pub', 'manifeste'], 'collection' => true,
                        'versions' => [
                            ['file' => 'manifeste-v1.mp4', 'status' => 'changes_requested', 'ago' => 190, 'revisions' => [
                                [2.0, null, 'Claire Vasseur', 'L’ouverture est trop terne : on veut sentir le printemps dès la première image.', 'done', ['Alex Martin', 'Étalonnage repris sur toute la séquence, c’est dans la V2.'], 170],
                                [8.5, 12.0, 'Claire Vasseur', 'Ce passage est un peu long, peut-on gagner une seconde ?', 'done', null, 169],
                                [21.0, null, 'Claire Vasseur', 'Superbe plan, on le garde absolument.', 'done', ['Sam Leroy', 'Noté, il ne bouge pas.'], 168],
                            ]],
                            ['file' => 'manifeste-v2.mp4', 'status' => 'to_review', 'ago' => 30, 'revisions' => [
                                [4.0, null, 'Claire Vasseur', 'Les couleurs sont parfaites cette fois-ci, merci !', 'todo', null, 6],
                                [26.0, 30.0, 'Claire Vasseur', 'Prévoir 2 secondes de plus sur la fin pour le logo et la signature.', 'todo', null, 5],
                            ]],
                        ],
                    ],
                    [
                        'title' => 'Déclinaison 15 s', 'tags' => ['pub'], 'collection' => true,
                        'versions' => [
                            ['file' => 'declinaison-15-v1.mp4', 'status' => 'approved', 'ago' => 120, 'approvedBy' => 'Claire Vasseur', 'revisions' => [
                                [6.0, null, 'Claire Vasseur', 'Rien à redire, c’est validé pour nous.', 'done', ['Alex Martin', 'Merci Claire, on prépare les exports.'], 100],
                            ]],
                        ],
                    ],
                    [
                        'title' => 'Déclinaison 6 s réseaux sociaux', 'tags' => ['pub', 'réseaux sociaux'],
                        'versions' => [
                            ['file' => 'declinaison-6-v1.mp4', 'status' => 'in_progress', 'ago' => 3, 'revisions' => []],
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'Orée Mobilité',
            'colors' => ['#0f1b2b', '#eef3f8', '#4fa3ff'],
            'welcome' => "Votre espace de revue pour le film de marque Orée.\nPlacez vos révisions directement sur l’image.",
            'logo' => ['orée', '#4fa3ff', 'Helvetica, Arial, sans-serif'],
            'projects' => [
                'Film de marque' => [
                    [
                        'title' => 'Film de marque 45 s', 'tags' => ['corporate', 'marque'],
                        'versions' => [
                            ['file' => 'marque-v1.mp4', 'status' => 'changes_requested', 'ago' => 260, 'revisions' => [
                                [1.0, 5.0, 'Hugo Benali', 'L’image manque de contraste sur toute l’ouverture.', 'done', ['Alex Martin', 'Corrigé à l’étalonnage dans la V2.'], 240],
                                [15.0, null, 'Inès Morel', 'Peut-on flouter l’enseigne à gauche ? Ce n’est pas une marque partenaire.', 'refused', ['Sam Leroy', 'L’enseigne n’est lisible que deux images : on la laisse, comme convenu par téléphone.'], 238],
                                [30.0, null, 'Hugo Benali', 'Très bien ce rythme.', 'done', null, 236],
                            ]],
                            ['file' => 'marque-v2.mp4', 'status' => 'changes_requested', 'ago' => 140, 'revisions' => [
                                [12.0, 18.0, 'Inès Morel', 'Plage à resserrer : on perd le fil entre ces deux plans.', 'done', ['Sam Leroy', 'Resserré d’une seconde dans la V3.'], 120],
                                [38.0, null, 'Hugo Benali', 'Une teinte un peu plus chaude sur la fin ?', 'done', null, 118],
                            ]],
                            ['file' => 'marque-v3.mp4', 'status' => 'to_review', 'ago' => 20, 'revisions' => [
                                [9.0, null, 'Inès Morel', 'Beaucoup mieux. La direction artistique valide ce passage.', 'todo', null, 4],
                                [40.0, 44.0, 'Hugo Benali', 'Il manque le carton final avec l’adresse du site.', 'todo', null, 3],
                            ]],
                        ],
                    ],
                    [
                        'title' => 'Teaser 20 s', 'tags' => ['corporate', 'teaser'],
                        'versions' => [
                            ['file' => 'teaser-v1.mp4', 'status' => 'to_review', 'ago' => 48, 'revisions' => [
                                [3.0, null, 'Hugo Benali', 'Le son est un peu fort sur l’entrée, à baisser de 2 ou 3 dB.', 'todo', null, 26],
                            ]],
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'Théâtre du Rivage',
            'colors' => ['#1a0f12', '#f6ece6', '#e0565b'],
            'welcome' => "Saison 2027 : la bande-annonce et les portraits.\nBonne revue !",
            'logo' => ['Théâtre du Rivage', '#e0565b', 'Georgia, serif'],
            'projects' => [
                'Saison 2027' => [
                    [
                        'title' => 'Bande-annonce de saison', 'tags' => ['bande-annonce'], 'collection' => true,
                        'versions' => [
                            ['file' => 'saison-v1.mp4', 'status' => 'changes_requested', 'ago' => 330, 'revisions' => [
                                [5.0, 12.0, 'Nadia Ferrand', 'La montagne doit être plus lumineuse, on dirait un temps couvert.', 'done', ['Alex Martin', 'Étalonnage éclairci, voir V2.'], 310],
                                [33.0, null, 'Nadia Ferrand', 'Fin idéale pour le titre de la saison.', 'done', null, 309],
                            ]],
                            ['file' => 'saison-v2.mp4', 'status' => 'approved', 'ago' => 210, 'approvedBy' => 'Nadia Ferrand', 'revisions' => [
                                [20.0, null, 'Nadia Ferrand', 'Parfait, on valide.', 'done', null, 200],
                            ]],
                        ],
                    ],
                    [
                        'title' => 'Portrait de la metteuse en scène', 'tags' => ['portrait', 'interview'],
                        'versions' => [
                            ['file' => 'portrait-v1.mov', 'status' => 'to_review', 'ago' => 70, 'revisions' => [
                                [2.0, 6.0, 'Nadia Ferrand', 'Ajouter le nom et la fonction en bas de l’image sur ce passage.', 'todo', null, 50],
                                [18.0, null, 'Nadia Ferrand', 'Le regard caméra ici est magnifique.', 'todo', null, 49],
                            ]],
                        ],
                    ],
                ],
            ],
        ],
        [
            'name' => 'Coopérative Tilleul',
            'colors' => ['#eef2e6', '#1d2418', '#4f7a2a'],
            'welcome' => 'Vos recettes en vidéo, à revoir et à valider ici.',
            'logo' => ['Tilleul', '#4f7a2a', 'Helvetica, Arial, sans-serif'],
            'projects' => [
                'Recettes en vidéo' => [
                    [
                        'title' => 'Recette n° 1 : la cabane', 'tags' => ['recette'], 'collection' => true,
                        'versions' => [
                            ['file' => 'recette-v1.mp4', 'status' => 'to_review', 'ago' => 96, 'revisions' => [
                                [7.0, null, 'Paul Girard', 'Peut-on afficher la liste des ingrédients à cet endroit ?', 'todo', ['Sam Leroy', 'Oui, on prépare le texte : vous nous l’envoyez ?'], 70],
                            ]],
                        ],
                    ],
                ],
            ],
        ],
    ];

    /** @var \Closure(string): void */
    private readonly \Closure $say;

    /**
     * @param array{path: string, probe: string|null} $ffmpeg
     * @param (\Closure(string): void)|null $say progress messages
     */
    public function __construct(
        private readonly PDO $db,
        private readonly Storage $storage,
        private readonly array $ffmpeg,
        private readonly string $extracts,
        ?\Closure $say = null,
    ) {
        $this->say = $say ?? static function (string $m): void {
        };
    }

    /** @return array<string, string> the shared links, by name */
    public function build(): array
    {
        if ((int) $this->db->query('SELECT COUNT(*) FROM organizations')->fetchColumn() > 0) {
            throw new \RuntimeException('Cette installation contient déjà des données : la démo se construit sur une base vide.');
        }
        if ($this->ffmpeg['probe'] === null) {
            throw new \RuntimeException('ffprobe est introuvable à côté de FFmpeg.');
        }
        foreach (self::files() as $file) {
            if (!is_file($this->extracts . '/' . $file)) {
                throw new \RuntimeException('Extrait manquant : ' . $file . ' (lancez scripts/demo-media.sh).');
            }
        }

        $organizations = new Organizations($this->db);
        $org = $organizations->create(self::ORGANIZATION);
        $organizations->setLogo($org, $this->logo('o' . $org, 'Lucarne', '#e8515f', 'Georgia, serif'));
        $users = new Users($this->db);
        $hash = password_hash(Demo::PASSWORD, PASSWORD_ARGON2ID);
        foreach (Demo::ACCOUNTS as $account) {
            $users->create($org, $account['email'], $account['name'], $hash, $account['role']);
        }

        $clients = new Clients($this->db, $org);
        $projects = new Projects($this->db, $org);
        $videos = new Videos($this->db, $org);
        $versions = new Versions($this->db, $org);
        $comments = new Comments($this->db, $org);
        $links = new Links($this->db, $org);
        $tags = new Tags($this->db, $org);
        $service = new CommentService($this->db, $comments, $versions);
        $importer = new VersionImporter($this->db, $versions, $this->storage);
        $jobs = new EncodeJobs($this->db);
        $shared = [];
        $collected = [];

        foreach (self::CLIENTS as $c) {
            [$bg, $text, $accent] = $c['colors'];
            $clientId = $clients->create(['name' => $c['name'], 'bg_color' => $bg, 'text_color' => $text, 'accent_color' => $accent, 'welcome_text' => $c['welcome']]);
            $clients->setLogo($clientId, $this->logo($clientId, ...$c['logo']));
            $shared['Espace ' . $c['name']] = '/' . $links->create('space', $clientId)['key'];

            foreach ($c['projects'] as $projectName => $projectVideos) {
                $projectId = $projects->create($clientId, $projectName);
                foreach ($projectVideos as $v) {
                    $videoId = $videos->create($projectId, $v['title'], true);
                    $tags->sync($videoId, $v['tags']);
                    $video = (array) $videos->find($videoId);
                    $first = $v['versions'][0]['ago'];
                    $this->age('videos', $videoId, $first + 1);
                    $this->age('projects', $projectId, $first + 2);
                    $this->age('clients', $clientId, $first + 3);

                    foreach ($v['versions'] as $ver) {
                        ($this->say)("{$c['name']} — {$v['title']} — {$ver['file']}");
                        $versionId = $this->import($importer, $video, $ver['file']);
                        $this->ageActivity($ver['ago']);
                        $jobs->enqueue($versionId);
                        $published = $ver['status'] !== 'in_progress';
                        $versions->update($versionId, [
                            'is_published' => $published ? 1 : 0,
                            'published_at' => $published ? self::ago($ver['ago'] - 0.5) : null,
                        ]);
                        $version = (array) $versions->find($versionId);
                        $rate = (int) $version['fps_num'] / (int) $version['fps_den'];

                        foreach ($ver['revisions'] as [$in, $out, $author, $body, $status, $reply, $ago]) {
                            $client = !in_array($author, array_column(Demo::ACCOUNTS, 'name'), true);
                            $data = ['text' => $body, 'frameIn' => (int) round($in * $rate)];
                            if ($out !== null) {
                                $data['frameOut'] = (int) round($out * $rate);
                            }
                            $id = $service->create($version, new Input($data), $author, null, !$client, null);
                            $this->ageActivity($ago);
                            $this->age('comments', $id, $ago);
                            if ($status !== 'todo') {
                                $comments->setStatus($id, $status);
                            }
                            if ($reply !== null) {
                                $replyId = $service->create($version, new Input(['text' => $reply[1], 'parentId' => $id]), $reply[0], null, true, null);
                                $this->ageActivity($ago - 1);
                                $this->age('comments', $replyId, $ago - 1);
                            }
                        }
                        // The status chosen for the demo, once the revisions are in (a client's revision asks for changes).
                        $versions->update($versionId, ['status' => $ver['status']]);
                        $this->age('versions', $versionId, $ver['ago']);
                        if (isset($ver['approvedBy'])) {
                            $service->approve($version, $ver['approvedBy']);
                            $this->ageActivity($ver['ago'] - 12);
                            $this->db->prepare('UPDATE versions SET approved_at = ? WHERE id = ?')->execute([self::ago($ver['ago'] - 12), $versionId]);
                        }
                        // Only the revisions of the last days are still unread.
                        if ($ver['ago'] > 24) {
                            $comments->markVersionRead($versionId);
                        }
                    }
                    $client = $links->create('client', $videoId);
                    $links->create('view', $videoId);
                    $shared[$v['title']] = '/v/' . $video['number'] . $client['key'];
                    if ($v['collection'] ?? false) {
                        $collected[] = $videoId;
                    }
                }
            }
        }

        $collections = new Collections($this->db, $org);
        $collectionId = $collections->create([
            'title' => 'Sélection 2027',
            'introduction' => 'Une sélection de nos films de l’année, à partager.',
            'bg_color' => '#0a0a0b', 'text_color' => '#fafafa', 'accent_color' => '#e8515f',
        ]);
        $collections->setVideos($collectionId, $collected);
        $shared['Collection Sélection 2027'] = '/' . $links->create('collection', $collectionId)['key'];

        // Playback versions (720p, 3 Mbit/s) and images, by FFmpeg.
        $encoder = new ServerEncoder($this->db, $this->storage, $this->ffmpeg, max(1, (int) (shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null') ?? 2)));
        while (($done = $encoder->runOne()) !== null) {
            ($this->say)("Encodage de la version {$done['version']} : {$done['status']} ({$done['details']})");
            if ($done['status'] !== 'done') {
                throw new \RuntimeException('Encodage impossible : ' . $done['details']);
            }
        }
        $this->db->exec('DELETE FROM encode_jobs');

        return $shared;
    }

    /** @return list<string> */
    public static function files(): array
    {
        $files = [];
        foreach (self::CLIENTS as $c) {
            foreach ($c['projects'] as $projectVideos) {
                foreach ($projectVideos as $v) {
                    foreach ($v['versions'] as $ver) {
                        $files[] = $ver['file'];
                    }
                }
            }
        }

        return $files;
    }

    /** @param array<string, mixed> $video */
    private function import(VersionImporter $importer, array $video, string $file): int
    {
        $source = $this->extracts . '/' . $file;
        $meta = MediaProbe::read((string) $this->ffmpeg['probe'], $source);
        $meta['codec'] = 'avc1.640028';
        // The importer moves the file: it works on a copy, in the data folder (same volume).
        $copy = $this->storage->absolute('uploads/demo-' . bin2hex(random_bytes(4)) . '-' . $file);
        if (!copy($source, $copy)) {
            throw new \RuntimeException('Copie impossible : ' . $file);
        }

        return $importer->import($video, $copy, $file, $meta);
    }

    /** A simple word mark, through the same checks as a logo sent by the admin. */
    private function logo(int|string $owner, string $word, string $color, string $font): string
    {
        $width = 40 + (int) (mb_strlen($word) * 26);
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d 64" width="%d" height="64"><text x="%d" y="44" text-anchor="middle" font-family="%s" font-size="44" fill="%s">%s</text></svg>',
            $width,
            $width,
            intdiv($width, 2),
            htmlspecialchars($font, ENT_XML1),
            $color,
            htmlspecialchars($word, ENT_XML1),
        );
        $tmp = (string) tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($tmp, $svg);
        try {
            return (new LogoStore($this->storage))->store($owner, ['name' => 'logo.svg', 'tmp_name' => $tmp, 'size' => strlen($svg), 'error' => UPLOAD_ERR_OK]);
        } finally {
            @unlink($tmp);
        }
    }

    private function age(string $table, int $id, float $hours): void
    {
        $columns = $table === 'comments' || $table === 'versions' || $table === 'videos' || $table === 'projects' || $table === 'clients'
            ? 'created_at = :at, updated_at = :at' : 'created_at = :at';
        $this->db->prepare("UPDATE {$table} SET {$columns} WHERE id = :id")->execute(['at' => self::ago($hours), 'id' => $id]);
    }

    /** The activity line just written happened $hours ago. */
    private function ageActivity(float $hours): void
    {
        $this->db->prepare('UPDATE activity SET created_at = ? WHERE id = (SELECT MAX(id) FROM activity)')->execute([self::ago($hours)]);
    }

    private static function ago(float $hours): string
    {
        return gmdate('Y-m-d H:i:s', time() - (int) round($hours * 3600));
    }
}
