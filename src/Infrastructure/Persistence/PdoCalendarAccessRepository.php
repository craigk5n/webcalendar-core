<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Repository\CalendarAccessRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\CalendarAccess;
use WebCalendar\Core\Domain\ValueObject\CalendarPermission;

/**
 * PDO-based implementation of CalendarAccessRepositoryInterface.
 */
final class PdoCalendarAccessRepository implements CalendarAccessRepositoryInterface
{
    private const COLUMNS = 'cal_login, cal_other_user, cal_can_view, cal_can_edit, '
        . 'cal_can_approve, cal_can_invite, cal_can_email, cal_see_time_only';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix = '',
    ) {
    }

    public function find(string $grantee, string $owner): ?CalendarAccess
    {
        $sql = "SELECT " . self::COLUMNS . " FROM {$this->tablePrefix}webcal_access_user
                WHERE cal_login = :grantee AND cal_other_user = :owner";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['grantee' => $grantee, 'owner' => $owner]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByGrantee(string $grantee): array
    {
        return $this->findBy('cal_login', $grantee);
    }

    public function findByOwner(string $owner): array
    {
        return $this->findBy('cal_other_user', $owner);
    }

    public function save(CalendarAccess $access): void
    {
        // Delete-then-insert rather than an upsert: the syntax for one differs
        // across MySQL, PostgreSQL and SQLite, and this table is small and
        // written rarely. Both statements are pinned to the full primary key,
        // so no other user's grant can be caught by them.
        $inTransaction = $this->pdo->inTransaction();
        if (!$inTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->delete($access->grantee(), $access->owner());

            $sql = "INSERT INTO {$this->tablePrefix}webcal_access_user (" . self::COLUMNS . ")
                    VALUES (:grantee, :owner, :view, :edit, :approve, :invite, :email, :time_only)";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'grantee' => $access->grantee(),
                'owner' => $access->owner(),
                'view' => $access->view()->value(),
                'edit' => $access->edit()->value(),
                'approve' => $access->approve()->value(),
                'invite' => $access->canInvite() ? 'Y' : 'N',
                'email' => $access->canEmail() ? 'Y' : 'N',
                'time_only' => $access->seeTimeOnly() ? 'Y' : 'N',
            ]);

            if (!$inTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function delete(string $grantee, string $owner): void
    {
        $sql = "DELETE FROM {$this->tablePrefix}webcal_access_user
                WHERE cal_login = :grantee AND cal_other_user = :owner";

        $this->pdo->prepare($sql)->execute(['grantee' => $grantee, 'owner' => $owner]);
    }

    public function deleteAllFor(string $login): void
    {
        $sql = "DELETE FROM {$this->tablePrefix}webcal_access_user
                WHERE cal_login = :login OR cal_other_user = :login";

        $this->pdo->prepare($sql)->execute(['login' => $login]);
    }

    /**
     * @return CalendarAccess[]
     */
    private function findBy(string $column, string $value): array
    {
        // $column is a private constant-fed literal, never caller input.
        $sql = "SELECT " . self::COLUMNS . " FROM {$this->tablePrefix}webcal_access_user
                WHERE {$column} = :value
                ORDER BY cal_login, cal_other_user";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['value' => $value]);

        $grants = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $grants[] = $this->hydrate($row);
            }
        }

        return $grants;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CalendarAccess
    {
        return new CalendarAccess(
            grantee: $this->asString($row['cal_login'] ?? ''),
            owner: $this->asString($row['cal_other_user'] ?? ''),
            view: CalendarPermission::fromStorage($row['cal_can_view']),
            edit: CalendarPermission::fromStorage($row['cal_can_edit']),
            approve: CalendarPermission::fromStorage($row['cal_can_approve']),
            canInvite: $this->isYes($row['cal_can_invite'] ?? 'Y'),
            canEmail: $this->isYes($row['cal_can_email'] ?? 'Y'),
            seeTimeOnly: $this->isYes($row['cal_see_time_only'] ?? 'N'),
        );
    }

    private function isYes(mixed $value): bool
    {
        return is_string($value) && strtoupper(trim($value)) === 'Y';
    }

    private function asString(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
