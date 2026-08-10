<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use PDO;
use WebCalendar\Core\Domain\Entity\Attendee;
use WebCalendar\Core\Domain\Entity\TicketOrder;
use WebCalendar\Core\Domain\Entity\TicketType;
use WebCalendar\Core\Domain\Repository\TicketRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\OrderStatus;

/**
 * PDO implementation of the ticketing domain (Epic 28):
 * webcal_ticket_type / webcal_ticket_order / webcal_attendee.
 *
 * Capacity enforcement lives here: createOrderIfCapacityAllows() counts
 * the held quantity and inserts inside one transaction, so two
 * concurrent buyers cannot both take the last seats.
 */
final class PdoTicketRepository implements TicketRepositoryInterface
{
    use TransactionalTrait;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tablePrefix = '',
    ) {
    }

    public function findTicketType(int $id): ?TicketType
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_ticket_type WHERE ticket_type_id = :id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapTicketType($row) : null;
    }

    public function findTicketTypesForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_ticket_type WHERE cal_id = :event ORDER BY ticket_type_id"
        );
        $stmt->execute(['event' => $eventId]);

        $types = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $types[] = $this->mapTicketType($row);
        }
        return $types;
    }

    public function saveTicketType(TicketType $type): TicketType
    {
        $persisted = $type;
        $this->executeInTransaction(function () use ($type, &$persisted): void {
            $isNew = $type->id() === 0;
            if ($isNew) {
                $persisted = new TicketType(
                    $this->nextId('webcal_ticket_type', 'ticket_type_id'),
                    $type->eventId(),
                    $type->name(),
                    $type->priceMinor(),
                    $type->currency(),
                    $type->capacity(),
                    $type->saleStart(),
                    $type->saleEnd(),
                    $type->isEnabled(),
                );
            }

            $data = [
                'id' => $persisted->id(),
                'event' => $persisted->eventId(),
                'name' => $persisted->name(),
                'price' => $persisted->priceMinor(),
                'currency' => $persisted->currency(),
                'capacity' => $persisted->capacity(),
                'sale_start' => $persisted->saleStart(),
                'sale_end' => $persisted->saleEnd(),
                'status' => $persisted->isEnabled() ? 'A' : 'D',
            ];

            if ($isNew) {
                $sql = "INSERT INTO {$this->tablePrefix}webcal_ticket_type
                        (ticket_type_id, cal_id, ticket_name, ticket_price, ticket_currency,
                         ticket_capacity, ticket_sale_start, ticket_sale_end, ticket_status)
                        VALUES (:id, :event, :name, :price, :currency,
                                :capacity, :sale_start, :sale_end, :status)";
            } else {
                $sql = "UPDATE {$this->tablePrefix}webcal_ticket_type SET
                        cal_id = :event,
                        ticket_name = :name,
                        ticket_price = :price,
                        ticket_currency = :currency,
                        ticket_capacity = :capacity,
                        ticket_sale_start = :sale_start,
                        ticket_sale_end = :sale_end,
                        ticket_status = :status
                        WHERE ticket_type_id = :id";
            }
            $this->pdo->prepare($sql)->execute($data);
        });

        return $persisted;
    }

    public function findOrder(int $id): ?TicketOrder
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_ticket_order WHERE order_id = :id"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapOrder($row) : null;
    }

    public function findOrdersForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_ticket_order WHERE cal_id = :event ORDER BY order_id DESC"
        );
        $stmt->execute(['event' => $eventId]);

        $orders = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orders[] = $this->mapOrder($row);
        }
        return $orders;
    }

    public function heldQuantity(int $ticketTypeId): int
    {
        $held = array_map(
            static fn (OrderStatus $status): string => $status->value,
            OrderStatus::capacityHolding()
        );
        $placeholders = [];
        $params = ['type' => $ticketTypeId];
        foreach ($held as $i => $status) {
            $placeholders[] = ":status_$i";
            $params["status_$i"] = $status;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(order_qty), 0) FROM {$this->tablePrefix}webcal_ticket_order
              WHERE ticket_type_id = :type AND order_status IN (" . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : 0;
    }

    public function createOrderIfCapacityAllows(TicketOrder $order, ?int $capacity): ?TicketOrder
    {
        $persisted = null;
        $this->executeInTransaction(function () use ($order, $capacity, &$persisted): void {
            if ($capacity !== null && $this->heldQuantity($order->ticketTypeId()) + $order->quantity() > $capacity) {
                return;
            }

            $persisted = $order->withId($this->nextId('webcal_ticket_order', 'order_id'));
            $this->pdo->prepare(
                "INSERT INTO {$this->tablePrefix}webcal_ticket_order
                 (order_id, ticket_type_id, cal_id, order_email, order_name, order_qty,
                  order_amount, order_currency, order_status, order_external_ref, order_created)
                 VALUES (:id, :type, :event, :email, :name, :qty,
                         :amount, :currency, :status, :external_ref, :created)"
            )->execute($this->orderData($persisted));
        });

        return $persisted;
    }

    public function updateOrder(TicketOrder $order): void
    {
        $data = $this->orderData($order);
        $this->pdo->prepare(
            "UPDATE {$this->tablePrefix}webcal_ticket_order SET
             ticket_type_id = :type,
             cal_id = :event,
             order_email = :email,
             order_name = :name,
             order_qty = :qty,
             order_amount = :amount,
             order_currency = :currency,
             order_status = :status,
             order_external_ref = :external_ref,
             order_created = :created
             WHERE order_id = :id"
        )->execute($data);
    }

    public function saveAttendee(Attendee $attendee): Attendee
    {
        $persisted = $attendee;
        $this->executeInTransaction(function () use ($attendee, &$persisted): void {
            $isNew = $attendee->id() === 0;
            if ($isNew) {
                $persisted = $attendee->withId($this->nextId('webcal_attendee', 'attendee_id'));
            }

            $data = [
                'id' => $persisted->id(),
                'order_id' => $persisted->orderId(),
                'event' => $persisted->eventId(),
                'name' => $persisted->name(),
                'email' => $persisted->email(),
                'token' => $persisted->checkInToken(),
                'checked_in' => $persisted->checkedInAt(),
            ];

            if ($isNew) {
                $sql = "INSERT INTO {$this->tablePrefix}webcal_attendee
                        (attendee_id, order_id, cal_id, attendee_name, attendee_email,
                         attendee_token, attendee_checked_in)
                        VALUES (:id, :order_id, :event, :name, :email, :token, :checked_in)";
            } else {
                $sql = "UPDATE {$this->tablePrefix}webcal_attendee SET
                        order_id = :order_id,
                        cal_id = :event,
                        attendee_name = :name,
                        attendee_email = :email,
                        attendee_token = :token,
                        attendee_checked_in = :checked_in
                        WHERE attendee_id = :id";
            }
            $this->pdo->prepare($sql)->execute($data);
        });

        return $persisted;
    }

    public function findAttendeeByToken(string $token): ?Attendee
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_attendee WHERE attendee_token = :token"
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->mapAttendee($row) : null;
    }

    public function findAttendeesForOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->tablePrefix}webcal_attendee WHERE order_id = :order_id ORDER BY attendee_id"
        );
        $stmt->execute(['order_id' => $orderId]);

        $attendees = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attendees[] = $this->mapAttendee($row);
        }
        return $attendees;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function orderData(TicketOrder $order): array
    {
        return [
            'id' => $order->id(),
            'type' => $order->ticketTypeId(),
            'event' => $order->eventId(),
            'email' => $order->email(),
            'name' => $order->name(),
            'qty' => $order->quantity(),
            'amount' => $order->amountMinor(),
            'currency' => $order->currency(),
            'status' => $order->status()->value,
            'external_ref' => $order->externalRef(),
            'created' => $order->createdAt(),
        ];
    }

    private function nextId(string $table, string $column): int
    {
        $stmt = $this->pdo->query("SELECT COALESCE(MAX($column), 0) + 1 FROM {$this->tablePrefix}$table");
        $value = $stmt === false ? false : $stmt->fetchColumn();
        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapTicketType(array $row): TicketType
    {
        return new TicketType(
            id: is_numeric($row['ticket_type_id'] ?? null) ? (int) $row['ticket_type_id'] : 0,
            eventId: is_numeric($row['cal_id'] ?? null) ? (int) $row['cal_id'] : 0,
            name: is_scalar($row['ticket_name'] ?? null) ? (string) $row['ticket_name'] : '',
            priceMinor: is_numeric($row['ticket_price'] ?? null) ? (int) $row['ticket_price'] : 0,
            currency: is_scalar($row['ticket_currency'] ?? null) ? (string) $row['ticket_currency'] : 'USD',
            capacity: is_numeric($row['ticket_capacity'] ?? null) ? (int) $row['ticket_capacity'] : null,
            saleStart: is_numeric($row['ticket_sale_start'] ?? null) ? (int) $row['ticket_sale_start'] : null,
            saleEnd: is_numeric($row['ticket_sale_end'] ?? null) ? (int) $row['ticket_sale_end'] : null,
            enabled: ($row['ticket_status'] ?? 'A') === 'A',
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapOrder(array $row): TicketOrder
    {
        $statusRaw = is_scalar($row['order_status'] ?? null) ? (string) $row['order_status'] : 'P';
        return new TicketOrder(
            id: is_numeric($row['order_id'] ?? null) ? (int) $row['order_id'] : 0,
            ticketTypeId: is_numeric($row['ticket_type_id'] ?? null) ? (int) $row['ticket_type_id'] : 0,
            eventId: is_numeric($row['cal_id'] ?? null) ? (int) $row['cal_id'] : 0,
            email: is_scalar($row['order_email'] ?? null) ? (string) $row['order_email'] : '',
            name: is_scalar($row['order_name'] ?? null) ? (string) $row['order_name'] : '',
            quantity: is_numeric($row['order_qty'] ?? null) ? (int) $row['order_qty'] : 1,
            amountMinor: is_numeric($row['order_amount'] ?? null) ? (int) $row['order_amount'] : 0,
            currency: is_scalar($row['order_currency'] ?? null) ? (string) $row['order_currency'] : 'USD',
            status: OrderStatus::from($statusRaw),
            externalRef: is_scalar($row['order_external_ref'] ?? null) && (string) $row['order_external_ref'] !== ''
                ? (string) $row['order_external_ref']
                : null,
            createdAt: is_numeric($row['order_created'] ?? null) ? (int) $row['order_created'] : 0,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapAttendee(array $row): Attendee
    {
        return new Attendee(
            id: is_numeric($row['attendee_id'] ?? null) ? (int) $row['attendee_id'] : 0,
            orderId: is_numeric($row['order_id'] ?? null) ? (int) $row['order_id'] : 0,
            eventId: is_numeric($row['cal_id'] ?? null) ? (int) $row['cal_id'] : 0,
            name: is_scalar($row['attendee_name'] ?? null) ? (string) $row['attendee_name'] : '',
            checkInToken: is_scalar($row['attendee_token'] ?? null) ? (string) $row['attendee_token'] : '',
            email: is_scalar($row['attendee_email'] ?? null) && (string) $row['attendee_email'] !== ''
                ? (string) $row['attendee_email']
                : null,
            checkedInAt: is_numeric($row['attendee_checked_in'] ?? null) ? (int) $row['attendee_checked_in'] : null,
        );
    }
}
