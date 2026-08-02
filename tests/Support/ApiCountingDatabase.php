<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Tests\Support;

use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use PDOStatement;

/**
 * HR: Testni ORM adapter koji bilježi SQL bez promjene produkcijskog servisa.
 * EN: Test ORM adapter that records SQL without changing the production service.
 */
final class ApiCountingDatabase extends Database
{
    /** @var string[] */
    private array $queries = [];

    /**
     * HR: Bilježi SQL predložak i delegira izvršavanje stvarnom ORM-u.
     * EN: Records the SQL template and delegates execution to the real ORM.
     *
     * @param mixed[] $params
     */
    public function query(
        string $sql,
        array $params = [],
        string $connectionName = self::DEFAULT_CONNECTION_NAME,
    ): PDOStatement {
        $this->queries[] = $sql;

        return parent::query($sql, $params, $connectionName);
    }

    /**
     * HR: Briše SQL zapise nastale tijekom pripreme fixturea.
     * EN: Clears SQL records produced while preparing fixtures.
     */
    public function resetQueries(): void
    {
        $this->queries = [];
    }

    /**
     * HR: Broji SQL predloške koji počinju zadanim tekstom.
     * EN: Counts SQL templates that start with the supplied text.
     */
    public function countStartingWith(string $prefix): int
    {
        return count(array_filter(
            $this->queries,
            static fn (string $sql): bool => str_starts_with($sql, $prefix),
        ));
    }
}
