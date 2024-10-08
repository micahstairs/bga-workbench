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
        $maxRetries = 3;
        $retries = 0;
        do {
            try {
                // Haven't yet found equivalent result type of mysqli->query via doctrine
                $conn = self::getDbConnection();
                //self::$affectedRows = $conn->executeQuery($sql)->rowCount();
                $host = $conn->getHost();
                if (!is_null($conn->getPort())) {
                    $host .= ':' . $conn->getPort();
                }
                $miConn = new mysqli($host, $conn->getUsername(), $conn->getPassword(), $conn->getDatabase());
                $result = $miConn->query($sql);
                if (!$result) {
                    throw new RuntimeException("QUERY FAILED: ". $miConn->error . " (query: $sql)");
                }
                self::$affectedRows = $miConn->affected_rows;
                return $result;
            } catch (Exception $e) {
                if (++$retries === $maxRetries) {
                    throw $e; 
                }
            }
        } while (true);
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
    protected function getCollectionFromDB($sql, $bSingleValue = false)
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
    protected function getNonEmptyCollectionFromDB($sql)
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
    protected static function getObjectListFromDB($sql, $bUniqueValue = false)
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
    protected function getNonEmptyObjectFromDB($sql)
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
    protected static function getUniqueValueFromDB($sql)
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

    protected function getObjectFromDB($sql)
    {
        $rows = self::getDbConnection()->fetchAllAssociative($sql);
        if (empty($rows)) {
            return null;
        } elseif (count($rows) > 1) {
            throw new \RuntimeException('More than one row returned. count: ' . count($rows));
        }
        return $rows[0];
    }

    protected static function escapeStringForDB($string)
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
