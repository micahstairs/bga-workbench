<?php

namespace BGAWorkbench\Test;

use BGAWorkbench\External\WorkbenchProjectConfigSerialiser;
use BGAWorkbench\Project\Project;
use BGAWorkbench\Project\WorkbenchProjectConfig;

trait TestHelp
{
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
