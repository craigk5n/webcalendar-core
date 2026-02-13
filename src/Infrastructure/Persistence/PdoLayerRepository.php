<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Layer;
use WebCalendar\Core\Domain\Repository\LayerRepositoryInterface;

/**
 * PDO-based implementation of LayerRepositoryInterface.
 */
final readonly class PdoLayerRepository implements LayerRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private string $tablePrefix = '',
    ) {
    }

    /**
     * @return Layer[]
     */
    public function findByOwner(string $owner): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->tablePrefix}webcal_user_layers WHERE cal_login = :owner");
        $stmt->execute(['owner' => $owner]);
        $layers = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $layers[] = $this->mapRowToLayer($row);
            }
        }

        return $layers;
    }

    public function save(Layer $layer): void
    {
        $data = [
            'owner' => $layer->owner(),
            'layeruser' => $layer->layerUser(),
            'color' => $layer->color(),
            'dups' => $layer->showDuplicates() ? 'Y' : 'N'
        ];

        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->tablePrefix}webcal_user_layers WHERE cal_login = :owner AND cal_layeruser = :layeruser");
        $stmt->execute(['owner' => $layer->owner(), 'layeruser' => $layer->layerUser()]);
        
        if ($stmt->fetch()) {
            $sql = "UPDATE {$this->tablePrefix}webcal_user_layers SET
                    cal_color = :color,
                    cal_dups = :dups
                    WHERE cal_login = :owner AND cal_layeruser = :layeruser";
        } else {
            $sql = "INSERT INTO {$this->tablePrefix}webcal_user_layers (cal_login, cal_layeruser, cal_color, cal_dups)
                    VALUES (:owner, :layeruser, :color, :dups)";
        }

        $this->pdo->prepare($sql)->execute($data);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->tablePrefix}webcal_user_layers WHERE cal_layerid = :id");
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToLayer(array $row): Layer
    {
        $id = is_numeric($row['cal_layerid'] ?? null) ? (int)$row['cal_layerid'] : 0;
        $owner = is_string($row['cal_login'] ?? null) ? $row['cal_login'] : '';
        $layerUser = is_string($row['cal_layeruser'] ?? null) ? $row['cal_layeruser'] : '';
        $color = is_string($row['cal_color'] ?? null) ? $row['cal_color'] : '';

        return new Layer(
            id: $id,
            owner: $owner,
            layerUser: $layerUser,
            color: $color,
            showDuplicates: ($row['cal_dups'] ?? 'N') === 'Y'
        );
    }
}
