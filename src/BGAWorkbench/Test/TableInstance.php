<?php

namespace BGAWorkbench\Test;

use BGAWorkbench\Project\Project;
use BGAWorkbench\Project\WorkbenchProjectConfig;
use BGAWorkbench\Utils;
use BGAWorkbench\Utils\FileUtils;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Functional as F;

class TableInstance
{
    /**
     * @var WorkbenchProjectConfig
     */
    private $config;

    /**
     * @var Project
     */
    private $project;

    /**
     * @var array
     */
    private $players;

    /**
     * @var array
     */
    private $options;

    /**
     * @var array
     */
    private $globalGameStates;

    /**
     * @var DatabaseInstance
     */
    private $database;

    /**
     * @var boolean
     */
    private $isSetup;

    /** Only populated after the game is created */
    private $table;

    /**
     * @param WorkbenchProjectConfig $config
     * @param array $players
     * @param array $options
     */
    public function __construct(WorkbenchProjectConfig $config, array $players, array $options, array $globalGameStates)
    {
        $this->config = $config;
        $this->project = $config->loadProject();
        $this->players = $players;
        $this->options = $options;
        $this->globalGameStates = $globalGameStates;
        $this->database = new DatabaseInstance(
            getenv('DB_NAME_PREFIX') . substr(md5(time()), 0, 10),
            getenv('DB_USERNAME'),
            "",
            [
                FileUtils::joinPath(__DIR__, '..', 'Stubs', 'dbmodel.sql'),
                $this->project->getDbModelSqlFile()->getPathname()
            ]
        );
        $this->isSetup = false;
    }

    /**
     * @return self
     */
    public function createDatabase()
    {
        $this->database->create();
        return $this;
    }

    /**
     * @return self
     */
    public function dropDatabaseAndDisconnect()
    {
        $this->database->drop();
        $this->database->disconnect();
        return $this;
    }

    /**
     * @param string $tableName
     * @param array $conditions
     * @return array
     */
    public function fetchDbRows($tableName, array $conditions = [])
    {
        return $this->database->fetchRows($tableName, $conditions);
    }

    /**
     * @param string $sql
     * @return mixed
     */
    public function fetchValue($sql)
    {
        return $this->database->fetchValue($sql);
    }

    /**
     * @return QueryBuilder
     */
    public function createDbQueryBuilder()
    {
        return $this->database->getOrCreateConnection()->createQueryBuilder();
    }

    /**
     * @return Connection
     */
    public function getDbConnection()
    {
        return $this->database->getOrCreateConnection();
    }

    /**
     * @param \Table $game
     * @return self
     */
    public function seedDatabaseBeforeSetupNewGame($game)
    {
        foreach ($this->globalGameStates as $label => $value) {
            $game->setGameStateInitialValue($label, $value);
        }
        return $this;
    }

    /**
     * @return self
     */
    public function setupNewGame()
    {
        if ($this->isSetup) {
            throw new \RuntimeException('Already setup');
        }

        $this->isSetup = true;

        $this->table = $this->createGameInstanceWithNoBoundedPlayer();
        $this->table->stubStates($this->project->getStates());
        $gameClass = new \ReflectionClass($this->table);
        call_user_func([$gameClass->getName(), 'stubGameInfos'], $this->project->getGameInfos());
        call_user_func([$gameClass->getName(), 'setDbConnection'], $this->database->getOrCreateConnection());
        $this->seedDatabaseBeforeSetupNewGame($this->table);
        Utils::callProtectedMethod($this->table, 'setupNewGame', $this->createPlayersById(), $this->options);

        return $this;
    }

    /**
     * @param callable $callable
     * @return $this
     */
    public function withDbConnection($callable)
    {
        call_user_func($callable, $this->getDbConnection());
        return $this;
    }

    /**
     * @return \Table
     */
    public function createGameInstanceWithNoBoundedPlayer()
    {
        return $this->project->createGameTableInstance();
    }

    /**
     * @param int $currentPlayerId
     * @return \Table
     */
    public function createGameInstanceForCurrentPlayer($currentPlayerId)
    {
        $playerIds = F\pluck($this->players, 'player_id');
        if (!in_array($currentPlayerId, $playerIds, true)) {
            $playerIdsList = join(', ', $playerIds);
            throw new \InvalidArgumentException("Current player {$currentPlayerId} not in {$playerIdsList}");
        }

        $game = $this->createGameInstanceWithNoBoundedPlayer();
        $game->stubCurrentPlayerId($currentPlayerId);
        return $game;
    }

    /**
     * @param int $currentPlayerId
     * @return \APP_GameAction
     */
    public function createActionInstanceForCurrentPlayer($currentPlayerId)
    {
        $action = $this->project->createActionInstance();
        $action->stubGame($this->createGameInstanceForCurrentPlayer($currentPlayerId));
        return $action;
    }

    /**
     * @param string $stateName
     * @param null|int $activePlayerId
     * @return \Table
     */
    public function runZombieTurn($stateName, $activePlayerId = null)
    {
        $state = F\first(
            $this->project->getStates(),
            function (array $state) use ($stateName) {
                return $state['name'] === $stateName;
            }
        );
        $game = $this->createGameInstanceWithNoBoundedPlayer();
        $game->zombieTurn($state, $activePlayerId);
        return $game;
    }

    /**
     * @return array
     */
    private function createPlayersById()
    {
        $ids = array_map(
            function ($i, array $player) {
                if (isset($player['player_id'])) {
                    return $player['player_id'];
                }
                return $i;
            },
            range(1, count($this->players)),
            $this->players
        );
        return array_combine($ids, $this->players);
    }

    /**
     * @return Table
     */
    public function getTable()
    {
        return $this->table;
    }
}
