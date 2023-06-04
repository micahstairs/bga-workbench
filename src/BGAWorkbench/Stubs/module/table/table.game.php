<?php

use BGAWorkbench\Test\Notification;

class feException extends Exception
{

}

class BgaSystemException extends feException
{

}

class BgaUserException extends feException
{

}

class APP_GameClass extends APP_DbObject
{

}

class Gamestate
{
    /**
     * @var Table
     */
    private $table;

    public function __construct($table)
    {
        $this->table = $table;
    }

    public function setAllPlayersMultiactive()
    {
    }

    public function setPlayerNonMultiactive($player_id, $next_state)
    {
        return false;
    }

    public function nextState($action = '')
    {
    }

    public function state(): Array
    {
        return ['name' => ''];
    }

    public function changeActivePlayer($player_id)
    {
        $this->table->stubActivePlayerId($player_id);
    }
}

abstract class Table extends APP_GameClass
{
    /**
     * @var Gamestate
     */
    public $gamestate;

    public function __construct()
    {
        $this->gamestate = new Gamestate($this);
    }

    abstract protected function setupNewGame($players, $options = array());

    public function _(string $text): string
    {
        return $text;
    }

    public function trace(string $text): void
    {
    }

    public function getBgaEnvironment(): string {
        return 'studio';
    }

    public function reattributeColorsBasedOnPreferences($players, $colors)
    {
    }

    public function reloadPlayersBasicInfos()
    {
    }

    protected function activeNextPlayer()
    {
    }

    public function checkAction($actionName, $bThrowException = true)
    {
        // TODO: Implement checkAction() method.
        return true;
    }

    public $gameStateLabelsToIds = [];

    public function initGameStateLabels($labels)
    {
        foreach ($labels as $label => $id) {
            if (!is_string($label)) {
                throw new InvalidArgumentException('All labels must be a string');
            }
            $this->gameStateLabelsToIds[$label] = $id;
        }
    }

    public function setGameStateInitialValue($label, $value)
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('The value must be numeric');
        }

        if (!array_key_exists($label, $this->gameStateLabelsToIds)) {
            throw new InvalidArgumentException(sprintf('The label %s was not defined by initGameStateLabels', $label));
        }
        $id = $this->gameStateLabelsToIds[$label];
        self::DbQuery("INSERT INTO global (global_id, global_value) VALUES ({$id}, {$value})");
    }

    public function setGameStateValue($label, $value)
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('The value must be numeric');
        }

        if (!array_key_exists($label, $this->gameStateLabelsToIds)) {
            throw new InvalidArgumentException(sprintf('The label %s was not defined by initGameStateLabels', $label));
        }
        $id = $this->gameStateLabelsToIds[$label];

        if (self::getUniqueValueFromDB("SELECT COUNT(*) FROM global WHERE global_id = {$id}") === 0) {
            throw new Exception("The game state value {$label} has not been initialized");
        }

        self::DbQuery("UPDATE global SET global_value = {$value} WHERE global_id = {$id}");
    }

    public function getGameStateValue($label)
    {
        if (!array_key_exists($label, $this->gameStateLabelsToIds)) {
            throw new InvalidArgumentException(sprintf('The label %s was not defined by initGameStateLabels', $label));
        }
        $id = $this->gameStateLabelsToIds[$label];

        if (self::getUniqueValueFromDB("SELECT COUNT(*) FROM global WHERE global_id = {$id}") == 0) {
            throw new Exception("The game state value {$label} (id={$id}) has not been initialized");
        }

        return self::getUniqueValueFromDB("SELECT global_value FROM global WHERE global_id = {$id}");
    }

    private function getStatTypeId($targetName)
    {
        include('stats.inc.php');
        foreach ($stats_type as $type => $stats) {
            foreach ($stats as $name => $stat) {
                if ($name === $targetName) {
                    return $stat['id'];
                }
            }
        }
        throw new Exception('State not found: ' . $targetName);
    }

    public function initStat($table_or_player, $name, $value, $player_id = null)
    {
        $typeId = $this->getStatTypeId($name);
        $sql = 'INSERT INTO stats (stats_type, stats_player_id, stats_value) VALUES ';

        switch ($table_or_player) {
            case 'table':
                $sql .= sprintf('(%d, NULL, %s)', $typeId, $value);
                break;
            case 'player':
                $players = self::loadPlayersBasicInfos();
                if ($player_id === null) {
                    $values = [];
                    foreach (array_keys($players) as $id) {
                        $values[] = "('" . $typeId . "','$id','" . $value . "')";
                    }
                    $sql .= implode(', ', $values);
                } else {
                    $values[] = "('" . $typeId . "','$player_id','" . $value . "')";
                }
                break;
            default:
                throw new InvalidArgumentException(sprintf('Wrong table_or_player type: %s', $table_or_player));
        }

        self::DbQuery($sql);
    }

    public function incStat($delta, $name, $player_id = null)
    {
        $typeId = $this->getStatTypeId($name);
        if ($player_id === null) {
            self::DbQuery("UPDATE stats SET stats_value = stats_value + {$delta} WHERE stats_type = {$typeId}");
        } else {
            self::DbQuery("UPDATE stats SET stats_value = stats_value + {$delta} WHERE stats_type = {$typeId} AND stats_player_id = {$player_id}");
        }
    }

    public function setStat($value, $name, $player_id = null)
    {
        $typeId = $this->getStatTypeId($name);
        if ($player_id === null) {
            self::DbQuery("UPDATE stats SET stats_value = {$value} WHERE stats_type = {$typeId}");
        } else {
            self::DbQuery("UPDATE stats SET stats_value = {$value} WHERE stats_type = {$typeId} AND stats_player_id = {$player_id}");
        }
    }

    public function getStat($name, $player_id = null)
    {
        $typeId = $this->getStatTypeId($name);
        if ($player_id === null) {
            return self::getUniqueValueFromDB("SELECT stats_value FROM stats WHERE stats_type = ${typeId}");
        }
        return self::getUniqueValueFromDB("SELECT stats_value FROM stats WHERE stats_type = ${typeId} AND stats_player_id = {$player_id}");
    }

    /**
     * @param int $player_id
     * @param int $specific_time
     */
    public function giveExtraTime($player_id, $specific_time = null) {}

    /**
     * @return string
     */
    public function getActivePlayerName()
    {
        $players = self::loadPlayersBasicInfos();
        return $players[$this->getActivePlayerId()]['player_name'];
    }

    ////////////////////////////////////////////////////////////////////////
    // Testing methods
    /**
     * @var array[]
     */
    private $notifications = [];

    /**
     * @return array[]
     */
    public function getNotifications()
    {
        return $this->notifications;
    }

    public function resetNotifications()
    {
        $this->notifications = [];
    }

    /**
     * @param string $notification_type
     * @param string $notification_log
     * @param array $notification_args
     */
    public function notifyAllPlayers($notification_type, $notification_log, $notification_args)
    {
        $this->notifyPlayer('all', $notification_type, $notification_log, $notification_args);
    }

    /**
     * @param int $player_id
     * @param string $notification_type
     * @param string $notification_log
     * @param array $notification_args
     */
    public function notifyPlayer($player_id, $notification_type, $notification_log, $notification_args)
    {
        if ($notification_log === null) {
            throw new \InvalidArgumentException('Use empty string for notification_log instead of null');
        }
        $this->notifications[] = [
            'playerId' => $player_id,
            'type' => $notification_type,
            'log' => $notification_log,
            'args' => $notification_args
        ];
    }

    /**
     * @var int
     */
    private $currentPlayerId;

    /**
     * @return int
     */
    protected function getCurrentPlayerId()
    {
        if ($this->currentPlayerId === null) {
            throw new \RuntimeException('Not a player bounded instance');
        }
        return $this->currentPlayerId;
    }

    /**
     * @todo get from getCurrentPlayerId table load
     * @return string
     */
    protected function getCurrentPlayerName()
    {
        return null;
    }

    /**
     * @todo get from getCurrentPlayerId table load
     * @return string
     */
    protected function getCurrentPlayerColor()
    {
        return null;
    }

    /**
     * @param int $currentPlayerId
     * @return self
     */
    public function stubCurrentPlayerId($currentPlayerId)
    {
        $this->currentPlayerId = $currentPlayerId;
        return $this;
    }

    /**
     * @var int
     */
    private $activePlayerId;

    /**
     * @return int
     */
    public function getActivePlayerId()
    {
        return $this->activePlayerId;
    }

    /**
     * @param int $activePlayerId
     * @return self
     */
    public function stubActivePlayerId($activePlayerId)
    {
        $this->activePlayerId = $activePlayerId;
        return $this;
    }

    /**
     * @var array|null
     */
    private static $stubbedGameInfos = null;

    /**
     * @param array $gameInfos
     */
    public static function stubGameInfos(array $gameInfos)
    {
        self::$stubbedGameInfos = $gameInfos;
    }

    /**
     * @param string $name
     * @return array
     */
    public static function getGameInfosForGame($name)
    {
        return self::$stubbedGameInfos;
    }

    /**
     * @return array
     */
    public function loadPlayersBasicInfos()
    {
        $players = self::getObjectListFromDB('SELECT * FROM player');
        $playerIds = array_map(
            function (array $player) {
                return (int) $player['player_id'];
            },
            $players
        );
        return array_combine($playerIds, $players);
    }
}
