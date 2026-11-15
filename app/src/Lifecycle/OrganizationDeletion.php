<?php

declare(strict_types=1);

namespace Belprise\Lifecycle;

use Belprise\Database\Database;
use Belprise\Http\HttpException;
use Belprise\Repository\AuditLog;
use Belprise\Repository\PlatformLog;
use Belprise\Storage;
use Belprise\Support\Dates;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Deletion of an organization and all its data (CAHIER_DES_CHARGES §5.10),
 * always 30 days after the request: e-mail at once, then at J-7 and J-1;
 * meanwhile the organization keeps its access and its shared links, only
 * new uploads are blocked. A request of the operator can only be cancelled
 * by the operator.
 */
final class OrganizationDeletion
{
    public const DELAY = '+30 days';
    public const ACTOR = 'Suppression programmée';

    public function __construct(
        private readonly PDO $db,
        private readonly Storage $storage,
        private readonly Notices $notices,
    ) {
    }

    /**
     * @param 'admin'|'operator' $by
     */
    public function request(int $organizationId, string $by, string $requestedBy, DateTimeImmutable $now): string
    {
        $organization = $this->organization($organizationId);
        if ($organization['deletion_at'] !== null) {
            throw new HttpException(409, 'La suppression de cette organisation est déjà programmée.');
        }
        $at = self::utc($now->modify(self::DELAY));
        $this->db->prepare(
            'UPDATE organizations SET deletion_requested_at = ?, deletion_at = ?, deletion_by = ?, deletion_requested_by = ?, deletion_reminded = NULL WHERE id = ?'
        )->execute([self::utc($now), $at, $by, $requestedBy, $organizationId]);
        $day = Dates::frenchDay($at);
        $who = $by === 'operator' ? 'l’exploitant de belprise' : $requestedBy;
        (new AuditLog($this->db))->add($organizationId, $requestedBy, 'Suppression de l’organisation demandée', 'Exécution le ' . $day);
        (new PlatformLog($this->db))->add($organizationId, (string) $organization['name'], $requestedBy, 'Suppression demandée', 'Exécution le ' . $day);
        $this->notices->send(
            $this->notices->adminEmails($organizationId),
            'Belprise : suppression de « ' . $organization['name'] . ' » programmée le ' . $day,
            'La suppression de votre organisation « ' . $organization['name'] . ' » et de toutes ses données a été demandée par ' . $who . ".\n\n"
            . 'Elle sera exécutée le ' . $day . ' : comptes, clients, projets, vidéos, masters, révisions, collections et liens seront effacés définitivement.' . "\n\n"
            . "D’ici là, vous gardez l’accès pour récupérer vos masters (bouton « Télécharger le master » de chaque version) ; les liens partagés restent actifs ; les nouveaux envois sont bloqués.\n"
            . ($by === 'operator'
                ? 'Seul l’exploitant de belprise peut annuler cette demande : répondez-lui si elle est une erreur.'
                : 'Un administrateur peut l’annuler d’ici là, dans la page Équipe.'),
        );

        return $at;
    }

    /**
     * @param 'admin'|'operator' $by
     */
    public function cancel(int $organizationId, string $by, string $cancelledBy): void
    {
        $organization = $this->organization($organizationId);
        if ($organization['deletion_at'] === null) {
            throw new HttpException(409, 'Aucune suppression n’est programmée.');
        }
        if ($by === 'admin' && $organization['deletion_by'] === 'operator') {
            throw new HttpException(403, 'Cette suppression a été demandée par l’exploitant de belprise : lui seul peut l’annuler.');
        }
        $this->db->prepare(
            'UPDATE organizations SET deletion_requested_at = NULL, deletion_at = NULL, deletion_by = NULL, deletion_requested_by = NULL, deletion_reminded = NULL WHERE id = ?'
        )->execute([$organizationId]);
        (new AuditLog($this->db))->add($organizationId, $cancelledBy, 'Suppression de l’organisation annulée');
        (new PlatformLog($this->db))->add($organizationId, (string) $organization['name'], $cancelledBy, 'Suppression annulée');
        $this->notices->send(
            $this->notices->adminEmails($organizationId),
            'Belprise : la suppression de « ' . $organization['name'] . ' » est annulée',
            'La suppression programmée de votre organisation « ' . $organization['name'] . ' » a été annulée par '
            . ($by === 'operator' ? 'l’exploitant de belprise' : $cancelledBy) . ". Rien ne sera effacé, et les envois sont de nouveau possibles.",
        );
    }

    /**
     * Hourly task: reminders, then the deletion once the date has come.
     *
     * @return list<array{organization: string, event: string, details: string}>
     */
    public function run(DateTimeImmutable $now, bool $dryRun): array
    {
        $report = [];
        foreach ($this->db->query('SELECT * FROM organizations WHERE deletion_at IS NOT NULL ORDER BY deletion_at, id')->fetchAll() as $organization) {
            $id = (int) $organization['id'];
            $name = (string) $organization['name'];
            $at = (string) $organization['deletion_at'];
            if (self::utc($now) >= $at) {
                $counts = $this->counts($id);
                $report[] = ['organization' => $name, 'event' => 'Suppression de l’organisation', 'details' => self::describe($counts)];
                if (!$dryRun) {
                    $this->erase($organization, $counts);
                }
                continue;
            }
            $days = (int) ceil(((new DateTimeImmutable($at, new DateTimeZone('UTC')))->getTimestamp() - $now->getTimestamp()) / 86400);
            $reminded = $organization['deletion_reminded'] === null ? null : (int) $organization['deletion_reminded'];
            $step = $days <= 1 ? 1 : ($days <= 7 ? 7 : null);
            if ($step === null || ($reminded !== null && $reminded <= $step)) {
                continue;
            }
            $report[] = ['organization' => $name, 'event' => 'Rappel J-' . $step . ' (suppression)', 'details' => 'le ' . Dates::frenchDay($at)];
            if (!$dryRun) {
                $this->db->prepare('UPDATE organizations SET deletion_reminded = ? WHERE id = ?')->execute([$step, $id]);
                $this->notices->send(
                    $this->notices->adminEmails($id),
                    'Belprise : rappel, suppression de « ' . $name . ' » le ' . Dates::frenchDay($at),
                    'Rappel : votre organisation « ' . $name . ' » et toutes ses données seront effacées définitivement le ' . Dates::frenchDay($at)
                    . ($step === 1 ? ', demain' : '') . ".\n\n"
                    . 'Pensez à récupérer vos masters (bouton « Télécharger le master » de chaque version). '
                    . ($organization['deletion_by'] === 'operator'
                        ? 'Seul l’exploitant de belprise peut annuler cette suppression.'
                        : 'Un administrateur peut encore l’annuler dans la page Équipe.'),
                );
            }
        }

        return $report;
    }

    /**
     * @return array{users: int, clients: int, videos: int, collections: int}
     */
    public function counts(int $organizationId): array
    {
        $count = function (string $sql) use ($organizationId): int {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$organizationId]);

            return (int) $stmt->fetchColumn();
        };

        return [
            'users' => $count('SELECT COUNT(*) FROM users WHERE organization_id = ?'),
            'clients' => $count('SELECT COUNT(*) FROM clients WHERE organization_id = ?'),
            'videos' => $count('SELECT COUNT(*) FROM videos v JOIN projects p ON p.id = v.project_id JOIN clients c ON c.id = p.client_id WHERE c.organization_id = ?'),
            'collections' => $count('SELECT COUNT(*) FROM collections WHERE organization_id = ?'),
        ];
    }

    /**
     * @param array{users: int, clients: int, videos: int, collections: int} $counts
     */
    public static function describe(array $counts): string
    {
        return sprintf('%d utilisateur(s), %d client(s), %d vidéo(s), %d collection(s)', $counts['users'], $counts['clients'], $counts['videos'], $counts['collections']);
    }

    /**
     * Everything goes: database rows, then files.
     *
     * @param array<string, mixed> $organization
     * @param array{users: int, clients: int, videos: int, collections: int} $counts
     */
    private function erase(array $organization, array $counts): void
    {
        $id = (int) $organization['id'];
        $recipients = $this->notices->adminEmails($id);
        $ids = function (string $sql) use ($id): array {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id]);

            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        };
        $files = function (string $sql) use ($id): array {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$id]);

            return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), static fn (string $f) => $f !== ''));
        };
        $clients = $ids('SELECT id FROM clients WHERE organization_id = ?');
        $videos = $ids('SELECT v.id FROM videos v JOIN projects p ON p.id = v.project_id JOIN clients c ON c.id = p.client_id WHERE c.organization_id = ?');
        $collections = $ids('SELECT id FROM collections WHERE organization_id = ?');
        $uploads = $ids('SELECT u.id FROM uploads u JOIN videos v ON v.id = u.video_id JOIN projects p ON p.id = v.project_id JOIN clients c ON c.id = p.client_id WHERE c.organization_id = ?');
        $logos = array_merge(
            $files('SELECT logo FROM clients WHERE organization_id = ? AND logo IS NOT NULL'),
            $files('SELECT logo FROM collections WHERE organization_id = ? AND logo IS NOT NULL'),
            $files('SELECT logo FROM organizations WHERE id = ? AND logo IS NOT NULL'),
        );

        Database::transaction($this->db, function () use ($id, $clients, $videos, $collections, $organization, $counts): void {
            $deleteLinks = function (string $type, array $targets): void {
                foreach (array_chunk($targets, 500) as $chunk) {
                    $marks = implode(',', array_fill(0, count($chunk), '?'));
                    $this->db->prepare("DELETE FROM links WHERE type = ? AND target_id IN ({$marks})")->execute([$type, ...$chunk]);
                }
            };
            $deleteLinks('space', $clients);
            $deleteLinks('client', $videos);
            $deleteLinks('view', $videos);
            $deleteLinks('collection', $collections);
            // Clients first (projects, videos, versions, revisions, uploads follow), then the organization (users, invitations, journal…).
            $this->db->prepare('DELETE FROM clients WHERE organization_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM organizations WHERE id = ?')->execute([$id]);
            $this->db->exec('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM video_tags)');
            // The only trace left: name, date, how much went. Never the content.
            (new PlatformLog($this->db))->add($id, (string) $organization['name'], self::ACTOR, 'Organisation supprimée', self::describe($counts));
        });

        foreach ($clients as $client) {
            $this->storage->deleteTree(Storage::clientMediaDir($client));
        }
        foreach ($logos as $logo) {
            $this->storage->deleteFile($logo);
        }
        foreach ($uploads as $upload) {
            $this->storage->deleteFile(Storage::uploadPart($upload));
        }
        $this->storage->deleteTree('inbox/' . $id); // import folder of versions before 2.1

        $this->notices->send(
            $recipients,
            'Belprise : « ' . $organization['name'] . ' » a été supprimée',
            'Comme annoncé, l’organisation « ' . $organization['name'] . ' » et toutes ses données ont été effacées définitivement aujourd’hui ('
            . self::describe($counts) . ").\n\nVos comptes belprise n’existent plus. Merci d’avoir utilisé belprise.",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function organization(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM organizations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? throw HttpException::notFound() : $row;
    }

    private static function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
