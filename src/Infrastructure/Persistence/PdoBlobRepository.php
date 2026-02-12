<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Blob;
use WebCalendar\Core\Domain\Repository\BlobRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\BlobType;

/**
 * PDO-based implementation of BlobRepositoryInterface.
 */
final readonly class PdoBlobRepository implements BlobRepositoryInterface
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findById(int $id): ?Blob
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webcal_blob WHERE cal_blob_id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToBlob($row);
    }

    /**
     * @return Blob[]
     */
    public function findByEvent(int $eventId, ?BlobType $type = null): array
    {
        $sql = 'SELECT * FROM webcal_blob WHERE cal_id = :event_id';
        $params = ['event_id' => $eventId];

        if ($type !== null) {
            $sql .= ' AND cal_type = :type';
            $params['type'] = $type->value;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $blobs = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $blobs[] = $this->mapRowToBlob($row);
            }
        }

        return $blobs;
    }

    public function save(Blob $blob): void
    {
        $data = [
            'id' => $blob->id(),
            'event_id' => $blob->eventId(),
            'login' => $blob->login(),
            'name' => $blob->name(),
            'description' => $blob->description(),
            'size' => $blob->size(),
            'mime_type' => $blob->mimeType(),
            'type' => $blob->type()->value,
            'date' => (int)$blob->date()->format('Ymd'),
            'time' => (int)$blob->date()->format('His'),
            'blob' => $blob->content()
        ];

        $stmt = $this->pdo->prepare('SELECT 1 FROM webcal_blob WHERE cal_blob_id = :id');
        $stmt->execute(['id' => $blob->id()]);
        
        if ($stmt->fetch() && $blob->id() !== 0) {
            $sql = 'UPDATE webcal_blob SET 
                    cal_id = :event_id, 
                    cal_login = :login, 
                    cal_name = :name, 
                    cal_description = :description, 
                    cal_size = :size, 
                    cal_mime_type = :mime_type, 
                    cal_type = :type, 
                    cal_mod_date = :date, 
                    cal_mod_time = :time, 
                    cal_blob = :blob 
                    WHERE cal_blob_id = :id';
        } else {
            // Check for AUTOINCREMENT/SERIAL or manually get next ID?
            // PRD says cal_blob_id is PK.
            $sql = 'INSERT INTO webcal_blob (cal_id, cal_login, cal_name, cal_description, cal_size, cal_mime_type, cal_type, cal_mod_date, cal_mod_time, cal_blob)
                    VALUES (:event_id, :login, :name, :description, :size, :mime_type, :type, :date, :time, :blob)';
            unset($data['id']);
        }

        $this->pdo->prepare($sql)->execute($data);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM webcal_blob WHERE cal_blob_id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToBlob(array $row): Blob
    {
        $rawDate = $row['cal_mod_date'] ?? '';
        $dateStr = is_scalar($rawDate) ? (string)$rawDate : '';
        
        $rawTime = $row['cal_mod_time'] ?? 0;
        $timeInt = is_numeric($rawTime) ? (int)$rawTime : 0;
        $timeStr = str_pad((string)$timeInt, 6, '0', STR_PAD_LEFT);
        
        $date = \DateTimeImmutable::createFromFormat('YmdHis', $dateStr . $timeStr);
        if ($date === false) {
            $date = new \DateTimeImmutable($dateStr !== '' ? $dateStr : 'now');
        }

        return new Blob(
            id: is_numeric($row['cal_blob_id'] ?? null) ? (int)$row['cal_blob_id'] : 0,
            eventId: is_numeric($row['cal_id'] ?? null) ? (int)$row['cal_id'] : 0,
            login: is_string($row['cal_login'] ?? null) ? $row['cal_login'] : '',
            name: is_string($row['cal_name'] ?? null) ? $row['cal_name'] : '',
            description: is_string($row['cal_description'] ?? null) ? $row['cal_description'] : '',
            size: is_numeric($row['cal_size'] ?? null) ? (int)$row['cal_size'] : 0,
            mimeType: is_string($row['cal_mime_type'] ?? null) ? $row['cal_mime_type'] : '',
            type: BlobType::from(is_string($row['cal_type'] ?? null) ? $row['cal_type'] : 'A'),
            date: $date,
            content: is_string($row['cal_blob'] ?? null) ? $row['cal_blob'] : ''
        );
    }
}
