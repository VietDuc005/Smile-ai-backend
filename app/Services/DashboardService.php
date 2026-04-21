<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Exceptions\ValidationException;
use App\Models\DigitalAccount;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class DashboardService
{
    private DatabaseService $databaseService;

    private EnvService $envService;

    public function __construct(?DatabaseService $databaseService = null, ?EnvService $envService = null)
    {
        $this->databaseService = $databaseService ?? new DatabaseService();
        $this->envService = $envService ?? new EnvService();
    }

    public function summary(array $filters = []): array
    {
        $timezone = $this->resolveTimezone($filters['timezone'] ?? null);
        $periods = $this->periodBoundaries($timezone);
        $overviewOrders = $this->overviewOrderStats();
        $overviewUsers = $this->overviewUserStats();
        $ticketStats = $this->ticketStats();
        $inventoryStats = $this->inventoryStats();
        $todayCreatedOrders = $this->createdOrderStats($periods['today']['from'], $periods['today']['to']);
        $monthCreatedOrders = $this->createdOrderStats($periods['month']['from'], $periods['month']['to']);
        $todayCompleted = $this->completedOrderStats($periods['today']['from'], $periods['today']['to']);
        $monthCompleted = $this->completedOrderStats($periods['month']['from'], $periods['month']['to']);

        return [
            'generated_at' => $periods['generated_at']->format(DateTimeInterface::ATOM),
            'timezone' => $timezone->getName(),
            'periods' => [
                'today' => $this->presentPeriod($periods['today']['from'], $periods['today']['to']),
                'month' => $this->presentPeriod($periods['month']['from'], $periods['month']['to']),
            ],
            'overview' => [
                'total_revenue' => (float) ($overviewOrders['total_revenue'] ?? 0),
                'total_orders' => (int) ($overviewOrders['total_orders'] ?? 0),
                'completed_orders' => (int) ($overviewOrders['completed_orders'] ?? 0),
                'pending_orders' => (int) ($overviewOrders['pending_orders'] ?? 0),
                'failed_orders' => (int) ($overviewOrders['failed_orders'] ?? 0),
                'cancelled_orders' => (int) ($overviewOrders['cancelled_orders'] ?? 0),
                'total_users' => (int) ($overviewUsers['total_users'] ?? 0),
                'active_users' => (int) ($overviewUsers['active_users'] ?? 0),
                'blocked_users' => (int) ($overviewUsers['blocked_users'] ?? 0),
            ],
            'today' => [
                'revenue' => (float) ($todayCompleted['revenue'] ?? 0),
                'orders_created' => (int) ($todayCreatedOrders['total_orders'] ?? 0),
                'completed_orders' => (int) ($todayCompleted['completed_orders'] ?? 0),
                'new_users' => $this->countUsersBetween($periods['today']['from'], $periods['today']['to']),
            ],
            'month' => [
                'revenue' => (float) ($monthCompleted['revenue'] ?? 0),
                'orders_created' => (int) ($monthCreatedOrders['total_orders'] ?? 0),
                'completed_orders' => (int) ($monthCompleted['completed_orders'] ?? 0),
                'new_users' => $this->countUsersBetween($periods['month']['from'], $periods['month']['to']),
            ],
            'tickets' => [
                'total' => (int) ($ticketStats['total'] ?? 0),
                'open' => (int) ($ticketStats['open'] ?? 0),
                'in_progress' => (int) ($ticketStats['in_progress'] ?? 0),
                'resolved' => (int) ($ticketStats['resolved'] ?? 0),
            ],
            'inventory' => [
                'total_accounts' => (int) ($inventoryStats['total_accounts'] ?? 0),
                'available_accounts' => (int) ($inventoryStats['available_accounts'] ?? 0),
                'sold_accounts' => (int) ($inventoryStats['sold_accounts'] ?? 0),
                'banned_accounts' => (int) ($inventoryStats['banned_accounts'] ?? 0),
            ],
        ];
    }

    private function overviewOrderStats(): array
    {
        return $this->fetchRow(
            'SELECT COUNT(*) AS total_orders,
                    COALESCE(SUM(CASE WHEN status = :completed_for_revenue THEN total_amount ELSE 0 END), 0) AS total_revenue,
                    SUM(CASE WHEN status = :completed_status THEN 1 ELSE 0 END) AS completed_orders,
                    SUM(CASE WHEN status = :pending_status THEN 1 ELSE 0 END) AS pending_orders,
                    SUM(CASE WHEN status = :failed_status THEN 1 ELSE 0 END) AS failed_orders,
                    SUM(CASE WHEN status = :cancelled_status THEN 1 ELSE 0 END) AS cancelled_orders
             FROM ' . Order::TABLE,
            [
                'completed_for_revenue' => 'completed',
                'completed_status' => 'completed',
                'pending_status' => 'pending',
                'failed_status' => 'failed',
                'cancelled_status' => 'cancelled',
            ]
        );
    }

    private function createdOrderStats(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->fetchRow(
            'SELECT COUNT(*) AS total_orders
             FROM ' . Order::TABLE . '
             WHERE created_at >= :from_at AND created_at < :to_at',
            [
                'from_at' => $this->dbDateTime($from),
                'to_at' => $this->dbDateTime($to),
            ]
        );
    }

    private function completedOrderStats(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->fetchRow(
            'SELECT COUNT(*) AS completed_orders,
                    COALESCE(SUM(total_amount), 0) AS revenue
             FROM ' . Order::TABLE . '
             WHERE status = :completed_status
               AND paid_at >= :from_at
               AND paid_at < :to_at',
            [
                'completed_status' => 'completed',
                'from_at' => $this->dbDateTime($from),
                'to_at' => $this->dbDateTime($to),
            ]
        );
    }

    private function overviewUserStats(): array
    {
        return $this->fetchRow(
            'SELECT COUNT(*) AS total_users,
                    SUM(CASE WHEN status = :active_status THEN 1 ELSE 0 END) AS active_users,
                    SUM(CASE WHEN status = :blocked_status THEN 1 ELSE 0 END) AS blocked_users
             FROM ' . User::TABLE,
            [
                'active_status' => 'active',
                'blocked_status' => 'blocked',
            ]
        );
    }

    private function countUsersBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $row = $this->fetchRow(
            'SELECT COUNT(*) AS total_users
             FROM ' . User::TABLE . '
             WHERE created_at >= :from_at AND created_at < :to_at',
            [
                'from_at' => $this->dbDateTime($from),
                'to_at' => $this->dbDateTime($to),
            ]
        );

        return (int) ($row['total_users'] ?? 0);
    }

    private function ticketStats(): array
    {
        return $this->fetchRow(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = :open_status THEN 1 ELSE 0 END) AS open,
                    SUM(CASE WHEN status = :in_progress_status THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN status = :resolved_status THEN 1 ELSE 0 END) AS resolved
             FROM ' . SupportTicket::TABLE,
            [
                'open_status' => 'open',
                'in_progress_status' => 'in_progress',
                'resolved_status' => 'resolved',
            ]
        );
    }

    private function inventoryStats(): array
    {
        return $this->fetchRow(
            'SELECT COUNT(*) AS total_accounts,
                    SUM(CASE WHEN status = :available_status THEN 1 ELSE 0 END) AS available_accounts,
                    SUM(CASE WHEN status = :sold_status THEN 1 ELSE 0 END) AS sold_accounts,
                    SUM(CASE WHEN status = :banned_status THEN 1 ELSE 0 END) AS banned_accounts
             FROM ' . DigitalAccount::TABLE,
            [
                'available_status' => 'available',
                'sold_status' => 'sold',
                'banned_status' => 'banned',
            ]
        );
    }

    private function periodBoundaries(DateTimeZone $timezone): array
    {
        $now = new DateTimeImmutable('now', $timezone);
        $todayStart = $now->setTime(0, 0, 0);
        $tomorrowStart = $todayStart->modify('+1 day');
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0);
        $nextMonthStart = $monthStart->modify('+1 month');

        return [
            'generated_at' => $now,
            'today' => [
                'from' => $todayStart,
                'to' => $tomorrowStart,
            ],
            'month' => [
                'from' => $monthStart,
                'to' => $nextMonthStart,
            ],
        ];
    }

    private function presentPeriod(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            'from' => $from->format(DateTimeInterface::ATOM),
            'to' => $to->format(DateTimeInterface::ATOM),
        ];
    }

    private function resolveTimezone(mixed $timezone): DateTimeZone
    {
        $candidate = trim((string) $timezone);

        if ($candidate === '') {
            $candidate = trim((string) $this->envService->get('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'));
        }

        try {
            return new DateTimeZone($candidate);
        } catch (\Throwable) {
            throw new ValidationException('Timezone is invalid.');
        }
    }

    private function fetchRow(string $sql, array $params = []): array
    {
        try {
            $statement = $this->databaseService->connection()->prepare($sql);
            $statement->execute($params);
            $row = $statement->fetch();

            return is_array($row) ? $row : [];
        } catch (\PDOException $exception) {
            throw new InfrastructureException('Unable to load dashboard statistics.', 0, $exception);
        }
    }

    private function dbDateTime(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
