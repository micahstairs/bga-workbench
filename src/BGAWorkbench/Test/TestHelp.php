<?php

namespace BGAWorkbench\Test;

use BGAWorkbench\External\WorkbenchProjectConfigSerialiser;
use BGAWorkbench\Project\Project;
use BGAWorkbench\Project\WorkbenchProjectConfig;

trait TestHelp
{
    /**
     * @var TableInstance
     */
    private $table;

    protected function setUp(): void
    {
        $this->table = $this->createGameTableInstanceBuilder()
            ->build()
            ->createDatabase();
    }

    /**
     * @return TableInstanceBuilder
     */
    abstract protected function createGameTableInstanceBuilder() : TableInstanceBuilder;

    protected function tearDown(): void
    {
        if ($this->table !== null) {
            $this->table->dropDatabaseAndDisconnect();
        }
    }

    /**
     * @var Project|null
     */
    private static $cwdConfig = null;

    /**
     * @return WorkbenchProjectConfig
     */
    private static function getCwdProjectConfig() : WorkbenchProjectConfig
    {
        if (self::$cwdConfig === null) {
            self::$cwdConfig = WorkbenchProjectConfigSerialiser::readFromCwd();
        }

        return self::$cwdConfig;
    }

    /**
     * @return TableInstanceBuilder
     */
    protected function gameTableInstanceBuilder() : TableInstanceBuilder
    {
        return TableInstanceBuilder::create(self::getCwdProjectConfig());
    }
}
