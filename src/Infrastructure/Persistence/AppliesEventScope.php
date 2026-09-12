<?php

declare(strict_types=1);

namespace WebCalendar\Core\Infrastructure\Persistence;

use WebCalendar\Core\Domain\ValueObject\EventScope;

/**
 * Turns an {@see EventScope} into SQL.
 *
 * Events, tasks and journals are all rows of `webcal_entry` separated only by
 * `cal_type`, so they share one access rule and this is the single place it
 * is written. Duplicating it per repository is how tasks and journals came to
 * have no access filter at all while events had one.
 */
trait AppliesEventScope
{
    /**
     * Builds the access-scoping SQL for a query.
     *
     * @param string $alias Table alias including its dot ('e.'), or '' when
     *                      the query has no alias.
     * @return array{0: string[], 1: array<string, mixed>}
     */
    private function scopeConditions(EventScope $scope, string $alias): array
    {
        $clauses = [];
        $params = [];

        $user = $scope->user();
        $accessLevel = $scope->accessLevel();

        if ($user !== null) {
            // Signed-in reader: public entries, plus anything they created.
            $clauses[] = "({$alias}cal_access = 'P' OR {$alias}cal_create_by = :scope_login)";
            $params['scope_login'] = $user->login();
        } elseif ($accessLevel !== null) {
            // No identity: one access level only.
            $clauses[] = "{$alias}cal_access = :scope_access";
            $params['scope_access'] = $accessLevel;
        }
        // Otherwise the scope is administrative and adds no access filter.

        $users = $scope->users();
        if ($users !== null && $users !== []) {
            $placeholders = [];
            foreach (array_values($users) as $i => $login) {
                $key = 'scope_user_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $login;
            }
            $clauses[] = "{$alias}cal_create_by IN (" . implode(', ', $placeholders) . ')';
        }

        return [$clauses, $params];
    }
}
