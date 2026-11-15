<?php

declare(strict_types=1);

namespace Belprise\Lifecycle;

use Belprise\Media\VideoEraser;
use Belprise\Quota;
use Belprise\Repository\AuditLog;
use Belprise\Repository\PlatformLog;
use Belprise\Storage;
use Belprise\Support\Dates;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Downgrade and purge (CAHIER_DES_CHARGES §5.12).
 *
 * When the operator lowers the quota of an organization under what it
 * stores, the organization gets 30 days: uploads blocked, e-mail at once,
 * reminders at J-7 and J-1. At J+30, if it still does not fit, whole videos
 * go — archived ones first, then the others, each time from the oldest
 * last activity — and no more than needed. A quota that fits again, or
 * freed space, cancels the purge.
 */
final class Downgrade
{
    public const DELAY = '+30 days';
    public const ACTOR = 'Purge automatique';

    public function __construct(
        private readonly PDO $db,
        private readonly Storage $storage,
        private readonly Notices $notices,
    ) {
    }

    /**
     * The operator changes the quota (null: no limit).
     *
     * @return string what happened: unchanged | changed | downgraded | cancelled | pending
     */
    public function changeQuota(int $organizationId, ?int $quota, string $actor, DateTimeImmutable $now): string
    {
        $organization = $this->organization($organizationId);
        $previous = $organization['quota_bytes'] === null ? null : (int) $organization['quota_bytes'];
        if ($quota === $previous) {
            return 'unchanged';
        }
        $this->db->prepare("UPDATE organizations SET quota_bytes = ?, updated_at = datetime('now') WHERE id = ?")->execute([$quota, $organizationId]);
        $usage = $this->quota()->usage($organizationId);
        $excess = Quota::excess($usage);
        $pending = $organization['purge_at'] !== null;
        $change = Quota::label($previous) . ' → ' . Quota::label($quota);
        $audit = new AuditLog($this->db);

        if ($excess <= 0) {
            if ($pending) {
                $this->clear($organizationId);
                $audit->add($organizationId, $actor, 'Purge annulée', 'Quota ' . $change . ' : le stockage tient dans le quota.');
                $this->platformLog($organization, $actor, 'Purge annulée', $change);
                $this->notices->send(
                    $this->notices->adminEmails($organizationId),
                    'Belprise : la purge de « ' . $organization['name'] . ' » est annulée',
                    'Le quota de votre organisation « ' . $organization['name'] . ' » est maintenant : ' . $usage['quotaLabel'] . ".\n\n"
                    . 'Votre stockage tient dans ce quota : aucune vidéo ne sera supprimée, et les envois sont de nouveau possibles.',
                );

                return 'cancelled';
            }
            $audit->add($organizationId, $actor, 'Quota changé', $change);

            return 'changed';
        }

        if ($pending) {
            // Another quota, still too small: the date already announced stays.
            $audit->add($organizationId, $actor, 'Quota changé', $change . ', purge toujours prévue le ' . Dates::frenchDay((string) $organization['purge_at']));

            return 'pending';
        }

        $purgeAt = $this->utc($now->modify(self::DELAY));
        $this->db->prepare(
            'UPDATE organizations SET downgraded_at = ?, downgraded_from = ?, purge_at = ?, purge_reminded = NULL WHERE id = ?'
        )->execute([$this->utc($now), Quota::label($previous), $purgeAt, $organizationId]);
        $day = Dates::frenchDay($purgeAt);
        $audit->add($organizationId, $actor, 'Quota abaissé', $change . ', ' . Quota::gigabytes($excess) . ' à libérer avant le ' . $day);
        $this->platformLog($organization, $actor, 'Quota abaissé', $change . ', purge le ' . $day);
        $this->notices->send(
            $this->notices->adminEmails($organizationId),
            'Belprise : quota abaissé, ' . Quota::gigabytes($excess) . ' à libérer avant le ' . $day,
            'Le quota de votre organisation « ' . $organization['name'] . ' » passe de ' . Quota::label($previous)
            . ' à ' . $usage['quotaLabel'] . ".\n\n"
            . 'Vous utilisez ' . Quota::gigabytes($usage['used'] + $usage['reserved']) . ' : il faut libérer ' . Quota::gigabytes($excess) . ".\n\n"
            . 'Date de purge : le ' . $day . ". D’ici là, les nouveaux envois sont bloqués ; vous pouvez toujours consulter, télécharger et supprimer vos vidéos.\n"
            . "Si le stockage dépasse encore le quota à cette date, belprise supprimera des vidéos entières jusqu’à repasser sous le quota : d’abord les vidéos archivées, puis les autres, en commençant par celles dont la dernière activité est la plus ancienne.\n\n"
            . "Pour récupérer vos masters : dans l’administration, ouvrez chaque vidéo ; chaque version a un bouton « Télécharger le master ».\n"
            . 'Pour libérer de l’espace : archivez les vidéos terminées (seule la version validée est gardée) ou supprimez celles dont vous n’avez plus besoin.',
        );

        return 'downgraded';
    }

    /**
     * Hourly task: reminders, then the purge once the date has come.
     *
     * @return list<array{organization: string, event: string, details: string}>
     */
    public function run(DateTimeImmutable $now, bool $dryRun): array
    {
        $report = [];
        $due = $this->db->query('SELECT * FROM organizations WHERE purge_at IS NOT NULL ORDER BY purge_at, id')->fetchAll();
        foreach ($due as $organization) {
            $id = (int) $organization['id'];
            $usage = $this->quota()->usage($id);
            $excess = Quota::excess($usage);
            $name = (string) $organization['name'];

            if ($excess <= 0) {
                // Room was freed: nothing will be deleted.
                $report[] = ['organization' => $name, 'event' => 'Purge sans objet', 'details' => 'le stockage tient dans le quota'];
                if (!$dryRun) {
                    $this->clear($id);
                    (new AuditLog($this->db))->add($id, self::ACTOR, 'Purge annulée', 'Espace libéré : le stockage tient dans le quota (' . $usage['quotaLabel'] . ').');
                    $this->notices->send(
                        $this->notices->adminEmails($id),
                        'Belprise : espace libéré, aucune vidéo ne sera supprimée',
                        'Le stockage de « ' . $name . ' » tient maintenant dans son quota (' . $usage['quotaLabel']
                        . ") : la purge prévue est annulée et les envois sont de nouveau possibles.",
                    );
                }
                continue;
            }

            $purgeAt = (string) $organization['purge_at'];
            if ($this->utc($now) >= $purgeAt) {
                $plan = $this->plan($id);
                $report[] = ['organization' => $name, 'event' => 'Purge', 'details' => self::describe($plan)];
                if (!$dryRun) {
                    $this->execute($organization, $plan);
                }
                continue;
            }

            $days = (int) ceil(((new DateTimeImmutable($purgeAt, new DateTimeZone('UTC')))->getTimestamp() - $now->getTimestamp()) / 86400);
            $reminded = $organization['purge_reminded'] === null ? null : (int) $organization['purge_reminded'];
            $step = $days <= 1 ? 1 : ($days <= 7 ? 7 : null);
            if ($step === null || ($reminded !== null && $reminded <= $step)) {
                continue;
            }
            $report[] = ['organization' => $name, 'event' => 'Rappel J-' . $step, 'details' => Quota::gigabytes($excess) . ' à libérer'];
            if (!$dryRun) {
                $this->db->prepare('UPDATE organizations SET purge_reminded = ? WHERE id = ?')->execute([$step, $id]);
                $day = Dates::frenchDay($purgeAt);
                $this->notices->send(
                    $this->notices->adminEmails($id),
                    'Belprise : rappel, ' . Quota::gigabytes($excess) . ' à libérer avant le ' . $day,
                    'Rappel : le quota de « ' . $name . ' » est de ' . $usage['quotaLabel'] . ",\n"
                    . 'et vous utilisez ' . Quota::gigabytes($usage['used'] + $usage['reserved']) . ".\n\n"
                    . 'Le ' . $day . ($step === 1 ? ', demain,' : '') . ', belprise supprimera des vidéos entières jusqu’à libérer ' . Quota::gigabytes($excess)
                    . " : d’abord les vidéos archivées, puis les autres, de la dernière activité la plus ancienne à la plus récente.\n\n"
                    . "Pour garder une vidéo : téléchargez son master (bouton « Télécharger le master » de chaque version), archivez ou supprimez d’autres vidéos, ou demandez un quota plus grand à l’exploitant.",
                );
            }
        }

        return $report;
    }

    /**
     * What the purge would delete now: whole videos, in order, until the
     * storage fits — never more.
     *
     * @return array{excess: int, freed: int, fits: bool, videos: list<array{id: int, number: int, title: string, client_id: int, archived: bool, last_activity: string, bytes: int}>}
     */
    public function plan(int $organizationId): array
    {
        $usage = $this->quota()->usage($organizationId);
        $excess = Quota::excess($usage);
        $stmt = $this->db->prepare(
            "SELECT v.id, v.number, v.title, p.client_id, v.archived_at,
                COALESCE(
                    (SELECT MAX(a.created_at) FROM activity a WHERE a.video_id = v.id AND a.type IN ('version', 'revision', 'reply')),
                    v.created_at
                ) AS last_activity
             FROM videos v JOIN projects p ON p.id = v.project_id JOIN clients c ON c.id = p.client_id
             WHERE c.organization_id = ?
             ORDER BY (v.archived_at IS NULL), last_activity, v.id"
        );
        $stmt->execute([$organizationId]);
        $eraser = new VideoEraser($this->db, $this->storage);
        $videos = [];
        $freed = 0;
        foreach ($stmt->fetchAll() as $row) {
            if ($freed >= $excess) {
                break;
            }
            $bytes = $eraser->footprint((int) $row['id'], (int) $row['client_id'], (int) $row['number']);
            if ($bytes === 0) {
                continue; // frees nothing: deleting it would only lose content
            }
            $freed += $bytes;
            $videos[] = [
                'id' => (int) $row['id'],
                'number' => (int) $row['number'],
                'title' => (string) $row['title'],
                'client_id' => (int) $row['client_id'],
                'archived' => $row['archived_at'] !== null,
                'last_activity' => (string) $row['last_activity'],
                'bytes' => $bytes,
            ];
        }

        return ['excess' => max(0, $excess), 'freed' => $freed, 'fits' => $freed >= $excess, 'videos' => $videos];
    }

    /**
     * @param array<string, mixed> $organization
     * @param array{excess: int, freed: int, fits: bool, videos: list<array<string, mixed>>} $plan
     */
    private function execute(array $organization, array $plan): void
    {
        $id = (int) $organization['id'];
        $eraser = new VideoEraser($this->db, $this->storage);
        foreach ($plan['videos'] as $video) {
            $eraser->erase($video['id'], $video['client_id'], $video['number']);
        }
        $this->clear($id);
        $usage = $this->quota()->usage($id);
        $list = implode("\n", array_map(
            static fn (array $v) => '- N° ' . $v['number'] . ' ' . $v['title'] . ($v['archived'] ? ' (archivée)' : '') . ' — ' . Quota::gigabytes($v['bytes']),
            $plan['videos'],
        ));
        $summary = count($plan['videos']) . ' vidéo' . (count($plan['videos']) > 1 ? 's' : '') . ' supprimée' . (count($plan['videos']) > 1 ? 's' : '')
            . ', ' . Quota::gigabytes($plan['freed']) . ' libérés';
        (new AuditLog($this->db))->add($id, self::ACTOR, 'Purge effectuée', $summary . ' : ' . implode(', ', array_map(
            static fn (array $v) => 'N° ' . $v['number'] . ' ' . $v['title'],
            $plan['videos'],
        )));
        $this->platformLog($organization, self::ACTOR, 'Purge effectuée', $summary);
        $this->notices->send(
            $this->notices->adminEmails($id),
            'Belprise : purge effectuée, ' . $summary,
            'Le stockage de « ' . $organization['name'] . ' » dépassait encore son quota (' . $usage['quotaLabel']
            . ") à la date annoncée.\n\n"
            . "Vidéos supprimées définitivement (masters, images, révisions et liens) :\n" . $list . "\n\n"
            . 'Stockage utilisé maintenant : ' . Quota::gigabytes($usage['used'] + $usage['reserved']) . '. Les envois sont de nouveau possibles dans la limite du quota.',
        );
    }

    /**
     * @param array{excess: int, freed: int, fits: bool, videos: list<array<string, mixed>>} $plan
     */
    public static function describe(array $plan): string
    {
        return count($plan['videos']) . ' vidéo(s), ' . Quota::gigabytes($plan['freed']) . ' libérés pour ' . Quota::gigabytes($plan['excess']) . ' à libérer : '
            . implode(', ', array_map(static fn (array $v) => 'n° ' . $v['number'] . ($v['archived'] ? ' (archivée)' : ''), $plan['videos']));
    }

    private function clear(int $organizationId): void
    {
        $this->db->prepare('UPDATE organizations SET downgraded_at = NULL, downgraded_from = NULL, purge_at = NULL, purge_reminded = NULL WHERE id = ?')
            ->execute([$organizationId]);
    }

    /**
     * @param array<string, mixed> $organization
     */
    private function platformLog(array $organization, string $actor, string $action, string $details): void
    {
        (new PlatformLog($this->db))->add((int) $organization['id'], (string) $organization['name'], $actor, $action, $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function organization(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM organizations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new \InvalidArgumentException('Unknown organization.');
        }

        return $row;
    }

    private function quota(): Quota
    {
        return new Quota($this->db, $this->storage);
    }

    private function utc(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
