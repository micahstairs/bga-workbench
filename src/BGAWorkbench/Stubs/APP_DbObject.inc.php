<?php

use Doctrine\DBAL\Connection;

class APP_DbObject extends APP_Object
{
    ////////////////////////////////////////////////////////////////////////
    // Testing methods
    private static $affectedRows = 0;

    /**
     * @param string $sql
     * @return mysqli_result
     */
    public static function DbQuery($sql)
    {
        try {
            if (self::$mysqliConnection === null) {
                $conn = self::getDbConnection();
                self::$mysqliConnection = new mysqli(
                    $conn->getHost(),
                    $conn->getUsername(),
                    $conn->getPassword(),
                    $conn->getDatabase(),
                    $conn->getPort() ?: 3306
                );
            }
            $result = self::$mysqliConnection->query($sql);
            if ($result === false) {
                throw new RuntimeException("QUERY FAILED: " . self::$mysqliConnection->error);
            }
            self::$affectedRows = self::$mysqliConnection->affected_rows;
            return $result;
        } catch (Exception $e) {
            // Do not retry writes: the server may have applied one before disconnecting.
            if ((int) $e->getCode() === 1040) {
                self::logConnectionDiagnostics();
            }
            self::closeDbQueryConnection();
            throw $e;
        }
    }

    /** One mysqli connection for the currently bound test database. */
    private static $mysqliConnection;

    public static function closeDbQueryConnection(Connection $owner = null)
    {
        if ($owner !== null && $owner !== self::$connection) {
            return;
        }
        if (self::$mysqliConnection !== null) {
            self::$mysqliConnection->close();
            self::$mysqliConnection = null;
        }
    }

    private static function logConnectionDiagnostics()
    {
        try {
            $conn = self::getDbConnection();
            $diagnostics = [
                'limits' => $conn->fetchAll("SHOW VARIABLES LIKE 'max_connections'"),
                'status' => $conn->fetchAll("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Max_used_connections')"),
                // Only connection metadata, never SQL text or credentials.
                'connections' => $conn->fetchAll("SELECT ID, DB, COMMAND, TIME FROM information_schema.PROCESSLIST"),
            ];
            error_log('BGA Workbench connection diagnostics: ' . json_encode($diagnostics));
        } catch (Exception $ignored) {
            error_log('BGA Workbench connection diagnostics unavailable');
        }
    }

    /**
     * @return int
     */
    public static function DbAffectedRow()
    {
        return self::$affectedRows;
    }

    /**
     * @param string $sql
     * @param boolean $bSingleValue
     * @return array
     */
    public function getCollectionFromDB($sql, $bSingleValue = false)
    {
        $rows = self::getObjectListFromDB($sql);
        $result = array();
        foreach ($rows as $row) {
            if ($bSingleValue) {
                $key = reset($row);
                $result[$key] = next($row);
            } else {
                $result[reset($row)] = $row;
            }
        }

        return $result;
    }

    /**
     * @param $sql
     * @return array
     */
    public function getNonEmptyCollectionFromDB($sql)
    {
        $rows = self::getCollectionFromDB($sql);
        if (empty($rows)) {
            throw new BgaSystemException('Expected collection to not be empty');
        }
        return $rows;
    }

    /**
     * @param string $sql
     * @param boolean $bUniqueValue
     * @return array
     */
    public static function getObjectListFromDB($sql, $bUniqueValue = false)
    {
        $rows = self::getDbConnection()->fetchAll($sql);
        if ($bUniqueValue) {
            $flatRows = [];
            foreach ($rows as $row) {
                $flatRows = array_merge($flatRows, array_values($row));
            }
            $rows = $flatRows;
        }
        return $rows;
    }

    /**
     * @param string $sql
     * @return array
     * @throws BgaSystemException
     */
    public function getNonEmptyObjectFromDB($sql)
    {
        $rows = $this->getObjectListFromDB($sql);
        if (count($rows) !== 1) {
            throw new BgaSystemException('Expected exactly one result');
        }

        return $rows[0];
    }

    /**
     * @param string $sql
     * @return mixed
     */
    public static function getUniqueValueFromDB($sql)
    {
        // TODO: Throw exception if not unique
        $rows = self::getDbConnection()->fetchArray($sql);
        // NOTE: Not sure why this is necessary, but it is
        if (!is_array($rows)) {
            return null;
        }
        if (count($rows) !== 1) {
            throw new \RuntimeException('Non unique result');
        }
        return $rows[0];
    }

    public function getObjectFromDB($sql)
    {
        $rows = self::getDbConnection()->fetchAllAssociative($sql);
        if (empty($rows)) {
            return null;
        } elseif (count($rows) > 1) {
            throw new \RuntimeException('More than one row returned. count: ' . count($rows));
        }
        return $rows[0];
    }

    public static function escapeStringForDB($string)
    {
        $quoted = self::$connection->quote($string);
        return substr($quoted, 1, -1);
    }

    /**
     * @var Connection
     */
    private static $connection;

    /**
     * @param Connection $connection
     */
    public static function setDbConnection(Connection $connection)
    {
        if (self::$connection !== $connection) {
            self::closeDbQueryConnection();
            self::$affectedRows = 0;
        }
        self::$connection = $connection;
    }

    /**
     * @return Connection
     */
    private static function getDbConnection()
    {
        if (self::$connection === null) {
            throw new \RuntimeException('No db connection set');
        }
        return self::$connection;
    }
}
