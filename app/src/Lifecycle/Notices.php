<?php

declare(strict_types=1);

namespace Belprise\Lifecycle;

use Belprise\Mail\Mailer;
use Belprise\Mail\Message;
use PDO;

/** E-mails to the administrators of an organization (§5.14): plain text, in French, no remote image. */
final class Notices
{
    public function __construct(
        private readonly PDO $db,
        private readonly Mailer $mailer,
        private readonly ?string $appUrl,
    ) {
    }

    /**
     * @return list<string>
     */
    public function adminEmails(int $organizationId): array
    {
        $stmt = $this->db->prepare("SELECT email FROM users WHERE organization_id = ? AND role = 'admin' ORDER BY id");
        $stmt->execute([$organizationId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Sends to each address; returns how many went.
     *
     * @param list<string> $to
     */
    public function send(array $to, string $subject, string $body): int
    {
        $sent = 0;
        $text = "Bonjour,\n\n" . rtrim($body) . "\n\n"
            . ($this->appUrl === null ? '' : 'Belprise : ' . $this->appUrl . "/admin\n\n")
            . "--\nBelprise, propulsé par belprod — https://belprod.fr\n";
        foreach ($to as $address) {
            $sent += $this->mailer->send(new Message($address, $subject, $text)) ? 1 : 0;
        }

        return $sent;
    }
}
