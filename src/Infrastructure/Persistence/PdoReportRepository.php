<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Report;
use WebCalendar\Core\Domain\Repository\ReportRepositoryInterface;

/**
 * PDO-based implementation of ReportRepositoryInterface.
 */
final readonly class PdoReportRepository implements ReportRepositoryInterface
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    public function findById(int $id): ?Report
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webcal_report WHERE cal_report_id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToReport($row);
    }

    /**
     * @return Report[]
     */
    public function findByOwner(string $owner): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM webcal_report WHERE cal_user = :owner');
        $stmt->execute(['owner' => $owner]);
        $reports = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $reports[] = $this->mapRowToReport($row);
            }
        }

        return $reports;
    }

    /**
     * @return Report[]
     */
    public function findAllGlobal(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM webcal_report WHERE cal_is_global = 'Y'");
        $reports = [];

        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (is_array($row)) {
                    $reports[] = $this->mapRowToReport($row);
                }
            }
        }

        return $reports;
    }

    public function save(Report $report): void
    {
        $data = [
            'id' => $report->id(),
            'owner' => $report->owner(),
            'name' => $report->name(),
            'type' => $report->type(),
            'global' => $report->isGlobal() ? 'Y' : 'N'
        ];

        // This is simplified; real implementation would need to handle all fields
        // and templates. For now, focus on the entity persistence.
        
        $stmt = $this->pdo->prepare('SELECT 1 FROM webcal_report WHERE cal_report_id = :id');
        $stmt->execute(['id' => $report->id()]);
        
        if ($stmt->fetch()) {
            $sql = 'UPDATE webcal_report SET 
                    cal_user = :owner, 
                    cal_report_name = :name, 
                    cal_report_type = :type, 
                    cal_is_global = :global 
                    WHERE cal_report_id = :id';
        } else {
            // Need other required fields for INSERT
            $sql = "INSERT INTO webcal_report (cal_report_id, cal_user, cal_report_name, cal_report_type, cal_is_global, cal_time_range, cal_update_date)
                    VALUES (:id, :owner, :name, :type, :global, 0, 0)";
        }

        $this->pdo->prepare($sql)->execute($data);
        
        $this->saveTemplates($report);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM webcal_report_template WHERE cal_report_id = :id')
            ->execute(['id' => $id]);
        $this->pdo->prepare('DELETE FROM webcal_report WHERE cal_report_id = :id')
            ->execute(['id' => $id]);
    }

    private function saveTemplates(Report $report): void
    {
        $this->pdo->prepare('DELETE FROM webcal_report_template WHERE cal_report_id = :id')
            ->execute(['id' => $report->id()]);

        $stmt = $this->pdo->prepare('INSERT INTO webcal_report_template (cal_report_id, cal_template_type, cal_template_text)
                                     VALUES (:id, :type, :text)');
        
        foreach ($report->templates() as $type => $text) {
            $stmt->execute(['id' => $report->id(), 'type' => $type, 'text' => $text]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToReport(array $row): Report
    {
        $id = is_numeric($row['cal_report_id'] ?? null) ? (int)$row['cal_report_id'] : 0;
        $owner = is_string($row['cal_user'] ?? null) ? $row['cal_user'] : '';
        $name = is_string($row['cal_report_name'] ?? null) ? $row['cal_report_name'] : '';
        $type = is_string($row['cal_report_type'] ?? null) ? $row['cal_report_type'] : '';
        $isGlobal = ($row['cal_is_global'] ?? 'N') === 'Y';

        $templates = $this->loadTemplates($id);

        return new Report($id, $owner, $name, $type, $isGlobal, $templates);
    }

    /**
     * @return array<string, string>
     */
    private function loadTemplates(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT cal_template_type, cal_template_text FROM webcal_report_template WHERE cal_report_id = :id');
        $stmt->execute(['id' => $id]);
        $templates = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $type = is_string($row['cal_template_type']) ? $row['cal_template_type'] : '';
                $text = is_string($row['cal_template_text']) ? $row['cal_template_text'] : '';
                if ($type !== '') {
                    $templates[$type] = $text;
                }
            }
        }

        return $templates;
    }
}
