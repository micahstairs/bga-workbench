<?php

namespace BGAWorkbench\Project;

use PhpOption\Option;

class WorkbenchProjectConfig
{
    /**
     * @var \SplFileInfo
     */
    private $directory;

    /**
     * @var boolean
     */
    private $useComposer;

    /**
     * @var string
     */
    private $gameName;

    /**
     * @var string[]
     */
    private $extraSrcPaths;

    /**
     * @var string
     */
    private $testDbNamePrefix;

    /**
     * @var string
     */
    private $testDbUsername;

    /**
     * @var string
     */
    private $testDbPassword;

    /**
     * @var string
     */
    private $linterPhpBin;

    /**
     * @var Option
     */
    private $sftpConfig;

    /**
     * @param \SplFileInfo $directory
     * @param bool $useComposer
     * @param string $gameName
     * @param string[] $extraSrcPaths
     * @param string $testDbNamePrefix
     * @param string $testDbUsername
     * @param string $testDbPassword
     * @param string $linterPhpBin
     * @param Option $sftpConfig
     */
    public function __construct(
        \SplFileInfo $directory,
        bool $useComposer,
        string $gameName,
        array $extraSrcPaths,
        string $testDbNamePrefix,
        string $testDbUsername,
        string $testDbPassword,
        string $linterPhpBin,
        Option $sftpConfig
    ) {

        $this->directory = $directory;
        $this->useComposer = $useComposer;
        $this->gameName = $gameName;
        $this->extraSrcPaths = $extraSrcPaths;
        $this->testDbNamePrefix = $testDbNamePrefix;
        $this->testDbUsername = $testDbUsername;
        $this->testDbPassword = $testDbPassword;
        $this->linterPhpBin = $linterPhpBin;
        $this->sftpConfig = $sftpConfig;
    }

    /**
     * @return string
     */
    public function getTestDbNamePrefix(): string
    {
        return $this->testDbNamePrefix;
    }

    /**
     * @return string
     */
    public function getTestDbUsername(): string
    {
        return $this->testDbUsername;
    }

    /**
     * @return string
     */
    public function getTestDbPassword(): string
    {
        return $this->testDbPassword;
    }

    /**
     * @return string
     */
    public function getLinterPhpBin(): string
    {
        return $this->linterPhpBin;
    }

    /**
     * @return Option
     */
    public function getDeployConfig(): Option
    {
        return $this->sftpConfig;
    }

    /**
     * @return bool
     */
    public function getUseComposer(): bool
    {
        return $this->useComposer;
    }

    /**
     * @return string
     */
    public function getGameName(): string
    {
        return $this->gameName;
    }

    /**
     * @return string[]
     */
    public function getExtraSrcPaths(): array
    {
        return $this->extraSrcPaths;
    }

    /**
     * @return Project
     */
    public function loadProject(): Project
    {
        if ($this->useComposer) {
            return new ComposerProject($this->directory, $this->gameName, $this->extraSrcPaths);
        }
        return new Project($this->directory, $this->gameName);
    }
}
