<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Venue;
use WebCalendar\Core\Domain\Repository\VenueRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\VenueId;

/**
 * PDO implementation of Venue persistence (webcal_venue).
 *
 * Deleting a venue nulls `cal_venue_id` on referencing events — the
 * event rows and their legacy location strings are never touched.
 */
final class PdoVenueRepository implements VenueRepositoryInterface
{
    use TransactionalTrait;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix = '',
    ) {
    }

    public function findById(VenueId $id): ?Venue
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_venue WHERE venue_id = :id"
        );
        $stmt->execute(['id' => $id->value()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findByName(string $name): ?Venue
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_venue
             WHERE LOWER(venue_name) = LOWER(:name)"
        );
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM {$this->tablePrefix}webcal_venue ORDER BY venue_name"
        );
        if ($stmt === false) {
            return [];
        }

        $venues = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $venues[] = $this->mapRow($row);
        }
        return $venues;
    }

    public function nextId(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COALESCE(MAX(venue_id), 0) + 1 FROM {$this->tablePrefix}webcal_venue"
        );
        $value = $stmt === false ? false : $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : 1;
    }

    public function save(Venue $venue): Venue
    {
        $isNew = $venue->id()->value() === 0;
        $persisted = $venue;

        $this->executeInTransaction(function () use ($venue, $isNew, &$persisted): void {
            $persisted = $isNew ? $venue->withId(new VenueId($this->nextId())) : $venue;

            $data = [
                'id' => $persisted->id()->value(),
                'name' => $persisted->name(),
                'address' => $persisted->address(),
                'city' => $persisted->city(),
                'state' => $persisted->state(),
                'zip' => $persisted->zip(),
                'country' => $persisted->country(),
                'lat' => $persisted->latitude(),
                'lon' => $persisted->longitude(),
                'url' => $persisted->url(),
                'phone' => $persisted->phone(),
            ];

            if ($isNew) {
                $sql = "INSERT INTO {$this->tablePrefix}webcal_venue
                        (venue_id, venue_name, venue_address, venue_city, venue_state,
                         venue_zip, venue_country, venue_lat, venue_lon, venue_url, venue_phone)
                        VALUES (:id, :name, :address, :city, :state,
                                :zip, :country, :lat, :lon, :url, :phone)";
            } else {
                $sql = "UPDATE {$this->tablePrefix}webcal_venue SET
                        venue_name = :name,
                        venue_address = :address,
                        venue_city = :city,
                        venue_state = :state,
                        venue_zip = :zip,
                        venue_country = :country,
                        venue_lat = :lat,
                        venue_lon = :lon,
                        venue_url = :url,
                        venue_phone = :phone
                        WHERE venue_id = :id";
            }

            $this->pdo->prepare($sql)->execute($data);
        });

        return $persisted;
    }

    public function delete(VenueId $id): void
    {
        $this->executeInTransaction(function () use ($id): void {
            $this->pdo->prepare(
                "UPDATE {$this->tablePrefix}webcal_entry SET cal_venue_id = NULL WHERE cal_venue_id = :id"
            )->execute(['id' => $id->value()]);
            $this->pdo->prepare(
                "DELETE FROM {$this->tablePrefix}webcal_venue WHERE venue_id = :id"
            )->execute(['id' => $id->value()]);
        });
    }

    public function reassignEvents(VenueId $from, VenueId $to): void
    {
        if ($from->equals($to)) {
            return;
        }

        $this->pdo->prepare(
            "UPDATE {$this->tablePrefix}webcal_entry SET cal_venue_id = :to WHERE cal_venue_id = :from"
        )->execute(['to' => $to->value(), 'from' => $from->value()]);
    }

    public function countEvents(VenueId $id): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->tablePrefix}webcal_entry WHERE cal_venue_id = :id"
        );
        $stmt->execute(['id' => $id->value()]);
        $value = $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row Database row.
     */
    private function mapRow(array $row): Venue
    {
        return new Venue(
            id: new VenueId(is_numeric($row['venue_id']) ? (int) $row['venue_id'] : 0),
            name: is_scalar($row['venue_name']) ? (string) $row['venue_name'] : '',
            address: $this->stringOrNull($row, 'venue_address'),
            city: $this->stringOrNull($row, 'venue_city'),
            state: $this->stringOrNull($row, 'venue_state'),
            zip: $this->stringOrNull($row, 'venue_zip'),
            country: $this->stringOrNull($row, 'venue_country'),
            latitude: $this->floatOrNull($row, 'venue_lat'),
            longitude: $this->floatOrNull($row, 'venue_lon'),
            url: $this->stringOrNull($row, 'venue_url'),
            phone: $this->stringOrNull($row, 'venue_phone'),
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

    /**
     * @param array<string, mixed> $row Database row.
     */
    private function floatOrNull(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;
        return is_numeric($value) ? (float) $value : null;
    }
}
