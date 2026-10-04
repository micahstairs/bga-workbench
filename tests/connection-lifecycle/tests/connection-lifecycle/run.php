<?php
// Run independently of the legacy test fixtures: php tests/connection-lifecycle/run.php
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../src/BGAWorkbench/Stubs/APP_Object.inc.php';
require __DIR__ . '/../../src/BGAWorkbench/Stubs/APP_DbObject.inc.php';

use Doctrine\DBAL\DriverManager;

function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function checkThreads($conn, $expected, $message) {
    // COM_QUIT is processed asynchronously by the server after client close().
    for ($i = 0; $i < 100; $i++) {
        if (threads($conn) === $expected) { return; }
        usleep(10000);
    }
    check(false, $message);
}
function connection($database = null) {
    return DriverManager::getConnection([
        'driver' => 'pdo_mysql', 'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: 3306, 'user' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '', 'dbname' => $database,
    ]);
}
function threads($conn) {
    return (int) $conn->fetchAll("SHOW GLOBAL STATUS LIKE 'Threads_connected'")[0]['Value'];
}

$admin = connection();
$firstName = 'wb_connections_' . getmypid() . '_a';
$secondName = 'wb_connections_' . getmypid() . '_b';
$first = null;
$second = null;
try {
    $admin->executeUpdate("CREATE DATABASE `$firstName`");
    $admin->executeUpdate("CREATE DATABASE `$secondName`");
    $first = connection($firstName);
    $second = connection($secondName);
    $first->connect();
    $second->connect();
    APP_DbObject::setDbConnection($first);
    $baseline = threads($admin);
    $ids = [];
    for ($i = 0; $i < 500; $i++) {
        $result = APP_DbObject::DbQuery('SELECT CONNECTION_ID() AS id, DATABASE() AS db');
        $row = $result->fetch_assoc();
        check($row['db'] === $firstName, 'Query used the wrong database');
        $ids[$row['id']] = true;
        $result->free();
    }
    check(count($ids) === 1, 'DbQuery opened more than one connection for 500 queries');
    checkThreads($admin, $baseline + 1, 'Connection count is not bounded');
    APP_DbObject::setDbConnection($first);
    $same = APP_DbObject::DbQuery('SELECT CONNECTION_ID() AS id')->fetch_assoc()['id'];
    check(isset($ids[$same]), 'Rebinding the same connection should preserve the handle');

    APP_DbObject::DbQuery('CREATE TABLE items (id INT PRIMARY KEY)');
    APP_DbObject::DbQuery('INSERT INTO items VALUES (1), (2)');
    check(APP_DbObject::DbAffectedRow() === 2, 'Affected-row count changed');
    $buffered = APP_DbObject::DbQuery('SELECT * FROM items ORDER BY id');
    APP_DbObject::closeDbQueryConnection();
    check(count($buffered->fetch_all()) === 2, 'Closing broke buffered results');
    $buffered->free();
    checkThreads($admin, $baseline, 'Explicit close leaked a connection');
    APP_DbObject::closeDbQueryConnection(); // Idempotent.
    APP_DbObject::DbQuery('SELECT 1');
    checkThreads($admin, $baseline + 1, 'Query did not reconnect after close');

    APP_DbObject::closeDbQueryConnection($second);
    checkThreads($admin, $baseline + 1, 'Unrelated DB owner closed the active connection');
    APP_DbObject::setDbConnection($second);
    checkThreads($admin, $baseline, 'Rebinding did not close the old handle');
    check(APP_DbObject::DbQuery('SELECT DATABASE() AS db')->fetch_assoc()['db'] === $secondName,
        'Rebinding left queries in the old database');
    $failed = false;
    try {
        APP_DbObject::DbQuery('THIS IS NOT SQL');
    } catch (Exception $e) {
        $failed = true;
    }
    check($failed, 'Invalid SQL did not throw');
    checkThreads($admin, $baseline, 'Error path leaked the query connection');
    check(APP_DbObject::DbQuery('SELECT 1 AS n')->fetch_assoc()['n'] == 1, 'Recovery after error failed');
    // Exercise the real teardown method without creating a game fixture.
    $database = new \BGAWorkbench\Test\DatabaseInstance('unused', 'root', '', []);
    $property = new ReflectionProperty($database, 'connection');
    $property->setAccessible(true);
    $property->setValue($database, $second);
    $database->disconnect();
    $database->disconnect();
    checkThreads($admin, $baseline - 1, 'Teardown did not close mysqli and PDO');
    echo "PASS: 500 queries, bounded connection ID/count, rebind, buffered results, affected rows, close/reconnect and error cleanup\n";
} finally {
    APP_DbObject::closeDbQueryConnection();
    if ($first !== null) { $first->close(); }
    if ($second !== null) { $second->close(); }
    $admin->executeUpdate("DROP DATABASE IF EXISTS `$firstName`");
    $admin->executeUpdate("DROP DATABASE IF EXISTS `$secondName`");
    $admin->close();
}
