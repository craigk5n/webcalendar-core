<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Organizer;
use WebCalendar\Core\Domain\Repository\OrganizerRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\OrganizerId;

/**
 * PDO implementation of Organizer persistence (webcal_organizer).
 *
 * Deleting an organizer nulls `cal_organizer_id` on referencing events —
 * the event rows are never touched.
 */
final class PdoOrganizerRepository implements OrganizerRepositoryInterface
{
    use TransactionalTrait;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix = '',
    ) {
    }

    public function findById(OrganizerId $id): ?Organizer
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_organizer WHERE organizer_id = :id"
        );
        $stmt->execute(['id' => $id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findByName(string $name): ?Organizer
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_organizer
             WHERE LOWER(organizer_name) = LOWER(:name)"
        );
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM {$this->tablePrefix}webcal_organizer ORDER BY organizer_name"
        );
        if ($stmt === false) {
            return [];
        }

        $organizers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $organizers[] = $this->mapRow($row);
        }
        return $organizers;
    }

    public function nextId(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COALESCE(MAX(organizer_id), 0) + 1 FROM {$this->tablePrefix}webcal_organizer"
        );
        $value = $stmt === false ? false : $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : 1;
    }

    public function save(Organizer $organizer): Organizer
    {
        $isNew = $organizer->id()->value() === 0;
        $persisted = $organizer;

        $this->executeInTransaction(function () use ($organizer, $isNew, &$persisted): void {
            $persisted = $isNew
                ? $organizer->withId(new OrganizerId($this->nextId()))
                : $organizer;

            $data = [
                'id' => $persisted->id()->value(),
                'name' => $persisted->name(),
                'email' => $persisted->email(),
                'phone' => $persisted->phone(),
                'url' => $persisted->url(),
            ];

            if ($isNew) {
                $sql = "INSERT INTO {$this->tablePrefix}webcal_organizer
                        (organizer_id, organizer_name, organizer_email, organizer_phone, organizer_url)
                        VALUES (:id, :name, :email, :phone, :url)";
            } else {
                $sql = "UPDATE {$this->tablePrefix}webcal_organizer SET
                        organizer_name = :name,
                        organizer_email = :email,
                        organizer_phone = :phone,
                        organizer_url = :url
                        WHERE organizer_id = :id";
            }

            $this->pdo->prepare($sql)->execute($data);
        });

        return $persisted;
    }

    public function delete(OrganizerId $id): void
    {
        $this->executeInTransaction(function () use ($id): void {
            $this->pdo->prepare(
                "UPDATE {$this->tablePrefix}webcal_entry SET cal_organizer_id = NULL WHERE cal_organizer_id = :id"
            )->execute(['id' => $id->value()]);
            $this->pdo->prepare(
                "DELETE FROM {$this->tablePrefix}webcal_organizer WHERE organizer_id = :id"
            )->execute(['id' => $id->value()]);
        });
    }

    public function reassignEvents(OrganizerId $from, OrganizerId $to): void
    {
        if ($from->equals($to)) {
            return;
        }

        $this->pdo->prepare(
            "UPDATE {$this->tablePrefix}webcal_entry SET cal_organizer_id = :to WHERE cal_organizer_id = :from"
        )->execute(['to' => $to->value(), 'from' => $from->value()]);
    }

    public function countEvents(OrganizerId $id): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->tablePrefix}webcal_entry WHERE cal_organizer_id = :id"
        );
        $stmt->execute(['id' => $id->value()]);
        $value = $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row Database row.
     */
    private function mapRow(array $row): Organizer
    {
        return new Organizer(
            id: new OrganizerId(is_numeric($row['organizer_id']) ? (int) $row['organizer_id'] : 0),
            name: is_scalar($row['organizer_name']) ? (string) $row['organizer_name'] : '',
            email: $this->stringOrNull($row, 'organizer_email'),
            phone: $this->stringOrNull($row, 'organizer_phone'),
            url: $this->stringOrNull($row, 'organizer_url'),
        );
    }

    /**
     * @param array<string, mixed> $row Database row.
     */
    private function stringOrNull(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
