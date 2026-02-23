<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

/**
 * Provides transaction management for PDO-based repositories.
 *
 * Requires the using class to have a `PDO $pdo` property.
 */
trait TransactionalTrait
{
  /**
   * Executes a callback within a database transaction.
   *
   * Supports nesting: if already in a transaction, the callback executes
   * without starting a new one. On failure, rolls back only if this
   * call started the transaction, then re-throws.
   *
   * @param callable $callback The operation to execute
   * @throws \Throwable Re-throws any exception after rollback
   */
  private function executeInTransaction(callable $callback): void
  {
    $inTransaction = $this->pdo->inTransaction();

    if (!$inTransaction) {
      $this->pdo->beginTransaction();
    }

    try {
      $callback();
      if (!$inTransaction) {
        $this->pdo->commit();
      }
    } catch (\Throwable $e) {
      if (!$inTransaction) {
        $this->pdo->rollBack();
      }
      throw $e;
    }
  }
}
